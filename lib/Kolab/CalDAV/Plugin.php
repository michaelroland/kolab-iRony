<?php

/**
 * Extended CalDAV plugin for the Kolab DAV server
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2013, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Kolab\CalDAV;

use Sabre\DAV;
use Sabre\CalDAV;
use Sabre\VObject;
use Sabre\HTTP;
use Sabre\Uri;
use Kolab\DAV\Auth\HTTPBasic;


/**
 * Extended CalDAV plugin to tweak data validation
 */
class Plugin extends CalDAV\Plugin
{
    // make already parsed text/calednar blocks available for later use
    public static $parsed_vcalendar;
    public static $parsed_vevent;

    // allow the backend to force a redirect Location
    public static $redirect_basename;

    /**
     * Initializes the plugin
     *
     * @param DAV\Server $server
     * @return void
     */
    public function initialize(DAV\Server $server)
    {
        parent::initialize($server);

        $server->on('afterCreateFile', array($this, 'afterWriteContent'));
        $server->on('afterWriteContent', array($this, 'afterWriteContent'));
    }

    /**
     * Inject some additional HTTP response headers
     */
    public function afterWriteContent($uri, $node)
    {
        // send Location: header to corrected URI
        if (self::$redirect_basename) {
            $path = explode('/', $uri);
            array_pop($path);
            array_push($path, self::$redirect_basename);
            $this->server->httpResponse->setHeader('Location', $this->server->getBaseUri() . join('/', array_map('urlencode', $path)));
            self::$redirect_basename = null;
        }
    }

    /**
     * Checks if the submitted iCalendar data is in fact, valid.
     *
     * An exception is thrown if it's not.
     *
     * @param resource|string $data
     * @param string $path
     * @param bool $modified Should be set to true, if this event handler
     *                       changed &$data.
     * @param RequestInterface $request The http request.
     * @param ResponseInterface $response The http response.
     * @param bool $isNew Is the item a new one, or an update.
     * @return void
     */
    protected function validateICalendar(&$data, $path, &$modified, HTTP\RequestInterface $request, HTTP\ResponseInterface $response, $isNew)
    {
        // If it's a stream, we convert it to a string first.
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        $before = md5($data);
        // Converting the data to unicode, if needed.
        $data = DAV\StringUtil::ensureUTF8($data);

        if ($before !== md5($data))
            $modified = true;

        try {
            // If the data starts with a [, we can reasonably assume we're dealing
            // with a jCal object.
            if (substr($data,0,1) === '[') {
                $vobj = VObject\Reader::readJson($data);

                // Converting $data back to iCalendar, as that's what we
                // technically support everywhere.
                $data = $vobj->serialize();
                $modified = true;
            } else {
                // modification: Set options to be more tolerant when parsing extended or invalid properties
                $vobj = VObject\Reader::read($data, VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);

                // keep the parsed object in memory for later processing
                if ($vobj->name == 'VCALENDAR') {
                    self::$parsed_vcalendar = $vobj;
                    foreach ($vobj->getComponents() as $vevent) {
                        if ($vevent->name == 'VEVENT' || $vevent->name == 'VTODO') {
                            self::$parsed_vevent = $vevent;
                            break;
                        }
                    }
                }
            }
        }
        catch (VObject\ParseException $e) {
            throw new DAV\Exception\UnsupportedMediaType('This resource only supports valid iCalendar 2.0 data. Parse error: ' . $e->getMessage());
        }

        if ($vobj->name !== 'VCALENDAR') {
            throw new DAV\Exception\UnsupportedMediaType('This collection can only support iCalendar objects.');
        }

        $sCCS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

        // Get the Supported Components for the target calendar
        list($parentPath) = Uri\split($path);
        $calendarProperties = $this->server->getProperties($parentPath, [$sCCS]);

        if (isset($calendarProperties[$sCCS])) {
            $supportedComponents = $calendarProperties[$sCCS]->getValue();
        }
        else {
            $supportedComponents = ['VTODO', 'VEVENT'];
        }

        $foundType = null;
        $foundUID = null;
        foreach($vobj->getComponents() as $component) {
            switch($component->name) {
                case 'VTIMEZONE':
                    continue 2;

                case 'VEVENT':
                case 'VTODO':
                case 'VJOURNAL':
                    if (is_null($foundType)) {
                        $foundType = $component->name;
                        if (!in_array($foundType, $supportedComponents)) {
                            throw new CalDAV\Exception\InvalidComponentType('This resource only supports ' . implode(', ', $supportedComponents) . '. We found a ' . $foundType);
                        }
                        if (!isset($component->UID)) {
                            throw new DAV\Exception\BadRequest('Every ' . $component->name . ' component must have an UID');
                        }
                        $foundUID = (string)$component->UID;
                    } else {
                        if ($foundType !== $component->name) {
                            throw new DAV\Exception\BadRequest('A calendar object must only contain 1 component. We found a ' . $component->name . ' as well as a ' . $foundType);
                        }
                        if ($foundUID !== (string)$component->UID) {
                            throw new DAV\Exception\BadRequest('Every ' . $component->name . ' in this object must have identical UIDs');
                        }
                    }
                    break;

                default:
                    throw new DAV\Exception\BadRequest('You are not allowed to create components of type: ' . $component->name . ' here');

            }
        }
        if (!$foundType)
            throw new DAV\Exception\BadRequest('iCalendar object must contain at least 1 of VEVENT, VTODO or VJOURNAL');

        // We use an extra variable to allow event handles to tell us wether
        // the object was modified or not.
        //
        // This helps us determine if we need to re-serialize the object.
        $subModified = false;

        $this->server->emit(
            'calendarObjectChange',
            [
                $request,
                $response,
                $vobj,
                $parentPath,
                &$subModified,
                $isNew
            ]
        );

        if ($subModified) {
            // An event handler told us that it modified the object.
            $data = $vobj->serialize();

            // Using md5 to figure out if there was an *actual* change.
            if (!$modified && $before !== md5($data)) {
                $modified = true;
            }
        }
    }

    /**
     * Returns a list of features for the DAV: HTTP header.
     * Including 'calendar-schedule' to enable scheduling support in Thunderbird Lightning.
     *
     * @return array
     */
    public function getFeatures()
    {
        $features = parent::getFeatures();
        $features[] = 'calendar-schedule';
        return $features;
    }

    /**
     * PropFind
     *
     * This method handler is invoked before any after properties for a
     * resource are fetched. This allows us to add in any CalDAV specific
     * properties.
     *
     * @param DAV\PropFind $propFind
     * @param DAV\INode $node
     * @return void
     */
    function propFind(DAV\PropFind $propFind, DAV\INode $node)
    {
        $propFind->handle('{' . self::NS_CALDAV . '}calendar-home-set', function() {
            return new DAV\Xml\Property\Href($this->getCalendarHomeForPrincipal(HTTPBasic::$current_user) . '/');
        });

        parent::propFind($propFind, $node);
    }
}
