<?php

/**
 * Extended CardDAV plugin for the Kolab DAV server
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

namespace Kolab\CardDAV;

use Sabre\DAV;
use Sabre\DAVACL;
use Sabre\CardDAV;
use Sabre\VObject;
use Kolab\DAV\Auth\HTTPBasic;


/**
 * Extended CardDAV plugin to tweak data validation
 */
class Plugin extends CardDAV\Plugin
{
    // make already parsed vcard blocks available for later use
    public static $parsed_vcard;

    // allow the backend to force a redirect Location
    public static $redirect_basename;

    // vcard version requested by the connecting client
    public static $vcard_version = 'vcard3';

    /**
     * Initializes the plugin
     *
     * @param DAV\Server $server
     * @return void
     */
    public function initialize(DAV\Server $server)
    {
        parent::initialize($server);

        $server->on('beforeMethod', array($this, 'beforeMethod'), 0);
        $server->on('afterCreateFile', array($this, 'afterWriteContent'));
        $server->on('afterWriteContent', array($this, 'afterWriteContent'));
    }

    /**
     * Adds all CardDAV-specific properties
     *
     * @param DAV\PropFind $propFind
     * @param DAV\INode $node
     * @return void
     */
    public function propFindEarly(DAV\PropFind $propFind, DAV\INode $node)
    {
        // publish global ldap address book and resources list for this principal
        if ($node instanceof DAVACL\IPrincipal && empty($this->directories)) {
            if (\rcube::get_instance()->config->get('kolabdav_ldap_directory')) {
                $this->directories[] = self::ADDRESSBOOK_ROOT . '/' . $node->getName() . '/' . LDAPDirectory::DIRECTORY_NAME;
            }
            if (\rcube::get_instance()->config->get('kolabdav_ldap_resources')) {
                $this->directories[] = self::ADDRESSBOOK_ROOT . '/' . $node->getName() . '/' . LDAPResources::DIRECTORY_NAME;
            }
        }

        $propFind->handle('{' . self::NS_CARDDAV . '}addressbook-home-set', function() {
            return new DAV\Xml\Property\Href($this->getAddressBookHomeForPrincipal(HTTPBasic::$current_user) . '/');
        });

        parent::propFindEarly($propFind, $node);
    }

    /**
     * Handler for beforeMethod events
     */
    public function beforeMethod($request, $response)
    {
        $method = $request->getMethod();

        if ($method == 'PUT' && $request->getHeader('If-None-Match') == '*') {
            // In-None-Match: * is only valid with PUT requests creating a new resource.
            // SOGo Conenctor for Thunderbird also sends it with update requests which then fail
            // in the Server::checkPreconditions().
            // See https://issues.kolab.org/show_bug.cgi?id=2589 and http://www.sogo.nu/bugs/view.php?id=1624
            // This is a work-around for the buggy SOGo connector and should be removed once fixed.
            if (strpos($request->getHeader('User-Agent'), 'Thunderbird/') > 0) {
                unset($_SERVER['HTTP_IF_NONE_MATCH']);
            }
        }
        else if ($method == 'GET' && ($accept = $request->getHeader('Accept'))) {
            // determine requested vcard version from Accept: header
            self::$vcard_version = parent::negotiateVCard($accept);
        }
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
     * @param bool $modified Should be set to true, if this event handler
     *                       changed &$data.
     * @return void
     */
    protected function validateVCard(&$data, &$modified)
    {
        // If it's a stream, we convert it to a string first.
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        $before = md5($data);

        // Converting the data to unicode, if needed.
        $data = DAV\StringUtil::ensureUTF8($data);

        if (md5($data) !== $before) $modified = true;

        try {
            $vobj = VObject\Reader::read($data, VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);

            if ($vobj->name == 'VCARD') {
                $this->parsed_vcard = $vobj;
            }
        }
        catch (VObject\ParseException $e) {
            throw new DAV\Exception\UnsupportedMediaType('This resource only supports valid vcard data. Parse error: ' . $e->getMessage());
        }

        if ($vobj->name !== 'VCARD') {
            throw new DAV\Exception\UnsupportedMediaType('This collection can only support vcard objects.');
        }

        if (!isset($vobj->UID)) {
            throw new DAV\Exception\BadRequest('Every vcard must have a UID.');
        }
    }

    /**
     * Wrapper for Plugin::negotiateVCard() to store the requested vcard version for the backend
     */
    protected function negotiateVCard($input, &$mimeType = null)
    {
        self::$vcard_version = parent::negotiateVCard($input, $mimeType);
        return self::$vcard_version;
    }

    /**
     * Converts a vcard blob to a different version, or jcard.
     *
     * (optimized version that skips parsing and re-serialization if possible)
     *
     * @param string|resource $data
     * @param string $target
     * @param array $propertiesFilter
     * @return string
     */
    protected function convertVCard($data, $target, $propertiesFilter = array())
    {
        $version = 'vcard3';
        if (is_string($data) && preg_match('/VERSION:(\d)/', $data, $m)) {
            $version = 'vcard' . $m[1];
        }

        // no conversion needed
        if ($target == $version && empty($propertiesFilter)) {
            if (is_resource($data)) {
                $data = stream_get_contents($data);
            }
            return $data;
        }

        return parent::convertVCard($data, $target, $propertiesFilter);
    }

    /**
     * This function handles the addressbook-query REPORT
     *
     * This report is used by the client to filter an addressbook based on a
     * complex query.
     *
     * @param \Sabre\CardDAV\Xml\Request\AddressBookQueryReport $report
     * @return void
     */
    protected function addressbookQueryReport($report)
    {
        $uri = $this->server->getRequestUri();
        $node = $this->server->tree->getNodeForPath($uri);
        console(__METHOD__, $uri);

        // TODO: port to new API if still required.
        // fix some bogus parameters in queries sent by the SOGo connector.
        // issue submitted in http://www.sogo.nu/bugs/view.php?id=2655
        // $xpath = new \DOMXPath($dom);
        // $xpath->registerNameSpace('card', Plugin::NS_CARDDAV);

        // $filters = $xpath->query('/card:addressbook-query/card:filter');
        // if ($filters->length === 1) {
        //     $filter = $filters->item(0);
        //     $propFilters = $xpath->query('card:prop-filter', $filter);
        //     for ($ii=0; $ii < $propFilters->length; $ii++) {
        //         $propFilter = $propFilters->item($ii);
        //         $name = $propFilter->getAttribute('name');

        //         // attribute 'mail' => EMAIL
        //         if ($name == 'mail') {
        //             $propFilter->setAttribute('name', 'EMAIL');
        //         }

        //         $textMatches = $xpath->query('card:text-match', $propFilter);
        //         for ($jj=0; $jj < $textMatches->length; $jj++) {
        //             $textMatch = $textMatches->item($jj);
        //             $collation = $textMatch->getAttribute('collation');

        //             // 'i;unicasemap' is a non-standard collation
        //             if ($collation == 'i;unicasemap') {
        //                 $textMatch->setAttribute('collation', 'i;unicode-casemap');
        //             }
        //         }
        //     }
        // }

        // query on LDAP node: pass along filter query
        if ($node instanceof LDAPDirectory || $node instanceof LDAPResources) {

            // set query and ...
            $node->setAddressbookQuery($report);
        }

        // ... proceed with default action
        parent::addressbookQueryReport($report);
    }
}
