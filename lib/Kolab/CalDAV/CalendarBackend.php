<?php

/**
 * SabreDAV Calendaring backend for Kolab.
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

use \PEAR;
use \rcube;
use \rcube_charset;
use \kolab_storage;
use \libcalendaring;
use Kolab\Utils\DAVBackend;
use Kolab\Utils\VObjectUtils;
use Sabre\DAV;
use Sabre\CalDAV;
use Sabre\VObject;

/**
 * Kolab Calendaring backend.
 *
 * Checkout the Sabre\CalDAV\Backend\BackendInterface for all the methods that must be implemented.
 *
 */
class CalendarBackend extends CalDAV\Backend\AbstractBackend
{
    private $calendars;
    private $folders;
    private $aliases;
    private $useragent;
    private $type_component_map = array('event' => 'VEVENT', 'task' => 'VTODO');

    /**
     * Read available calendar folders from server
     */
    private function _read_calendars()
    {
        // already read sources
        if (isset($this->calendars))
            return $this->calendars;

        // get all folders that have "event" type
        $folders = array_merge(kolab_storage::get_folders('event'), kolab_storage::get_folders('task'));
        $this->calendars = $this->folders = $this->aliases = array();

        foreach (DAVBackend::sort_folders($folders) as $folder) {
            $id = DAVBackend::get_uid($folder);
            $this->folders[$id] = $folder;
            $fdata = $folder->get_imap_data();  // fetch IMAP folder data for CTag generation
            $this->calendars[$id] = array(
                'id' => $id,
                'uri' => $id,
                '{DAV:}displayname' => html_entity_decode($folder->get_name(), ENT_COMPAT, RCUBE_CHARSET),
                '{http://apple.com/ns/ical/}calendar-color' => $folder->get_color(),
                '{http://calendarserver.org/ns/}getctag' => sprintf('%d-%d-%d', $fdata['UIDVALIDITY'], $fdata['HIGHESTMODSEQ'], $fdata['UIDNEXT']),
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new CalDAV\Property\SupportedCalendarComponentSet(array($this->type_component_map[$folder->type])),
                '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new CalDAV\Property\ScheduleCalendarTransp('opaque'),
            );
            $this->aliases[$folder->name] = $id;

            // these properties are used for sharing supprt (not yet active)
            if (false && $folder->get_namespace() != 'personal') {
                $rights = $folder->get_myrights();
                $this->calendars[$id]['{http://calendarserver.org/ns/}shared-url'] = '/calendars/' . $folder->get_owner() . '/' . $id;
                $this->calendars[$id]['{http://calendarserver.org/ns/}owner-principal'] = $folder->get_owner();
                $this->calendars[$id]['{http://sabredav.org/ns}read-only'] = strpos($rights, 'i') === false;
            }
        }

        return $this->calendars;
    }

    /**
     * Getter for a kolab_storage_folder representing the calendar for the given ID
     *
     * @param string Calendar ID
     * @return object kolab_storage_folder instance
     */
    public function get_storage_folder($id)
    {
        // resolve alias name
        if ($this->aliases[$id]) {
            $id = $this->aliases[$id];
        }

        if ($this->folders[$id]) {
            return $this->folders[$id];
        }
        else {
            return DAVBackend::get_storage_folder($id, 'event');
        }
    }

    /**
     * Returns a list of calendars for a principal.
     *
     * Every calendars is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    calendar. This can be the same as the uri or a database key.
     *  * uri, which the basename of the uri with which the calendar is
     *    accessed.
     *  * principaluri. The owner of the calendar. Almost always the same as
     *    principalUri passed to this method.
     *
     * Furthermore it can contain webdav properties in clark notation. A very
     * common one is '{DAV:}displayname'.
     *
     * @param string $principalUri
     * @return array
     */
    public function getCalendarsForUser($principalUri)
    {
        console(__METHOD__, $principalUri);

        $this->_read_calendars();

        $calendars = array();
        foreach ($this->calendars as $id => $cal) {
            $this->calendars[$id]['principaluri'] = $principalUri;
            $calendars[] = $this->calendars[$id];
        }

        return $calendars;
    }

    /**
     * Returns calendar properties for a specific node identified by name/uri
     *
     * @param string Node name/uri
     * @return array Hash array with calendar properties or null if not found
     */
    public function getCalendarByName($calendarUri)
    {
        console(__METHOD__, $calendarUri);

        $this->_read_calendars();
        $id = $calendarUri;

        // resolve aliases (calendar by folder name)
        if ($this->aliases[$calendarUri]) {
            $id = $this->aliases[$calendarUri];
        }

        return $this->calendars[$id];
    }

    /**
     * Creates a new calendar for a principal.
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this calendar in other methods, such as updateCalendar.
     *
     * @param string $principalUri
     * @param string $calendarUri
     * @param array $properties
     * @return void
     */
    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        console(__METHOD__, $calendarUri, $properties);

        return DAVBackend::folder_create('event', $properties, $calendarUri);
    }

    /**
     * Updates properties for a calendar.
     *
     * The mutations array uses the propertyName in clark-notation as key,
     * and the array value for the property value. In the case a property
     * should be deleted, the property value will be null.
     *
     * This method must be atomic. If one property cannot be changed, the
     * entire operation must fail.
     *
     * If the operation was successful, true can be returned.
     * If the operation failed, false can be returned.
     *
     * Deletion of a non-existent property is always successful.
     *
     * Lastly, it is optional to return detailed information about any
     * failures. In this case an array should be returned with the following
     * structure:
     *
     * array(
     *   403 => array(
     *      '{DAV:}displayname' => null,
     *   ),
     *   424 => array(
     *      '{DAV:}owner' => null,
     *   )
     * )
     *
     * In this example it was forbidden to update {DAV:}displayname.
     * (403 Forbidden), which in turn also caused {DAV:}owner to fail
     * (424 Failed Dependency) because the request needs to be atomic.
     *
     * @param mixed $calendarId
     * @param array $mutations
     * @return bool|array
     */
    public function updateCalendar($calendarId, array $mutations)
    {
        console(__METHOD__, $calendarId, $mutations);

        $folder = $this->get_storage_folder($calendarId);
        return DAVBackend::folder_update($folder, $mutations);
    }

    /**
     * Delete a calendar and all it's objects
     *
     * @param mixed $calendarId
     * @return void
     */
    public function deleteCalendar($calendarId)
    {
        console(__METHOD__, $calendarId);

        $folder = $this->get_storage_folder($calendarId);
        if ($folder && !kolab_storage::folder_delete($folder->name)) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error deleting calendar folder $folder->name"),
                true, false);
        }
    }


    /**
     * Returns all calendar objects within a calendar.
     *
     * Every item contains an array with the following keys:
     *   * id - unique identifier which will be used for subsequent updates
     *   * calendardata - The iCalendar-compatible calendar data (optional)
     *   * uri - a unique key which will be used to construct the uri. This can be any arbitrary string.
     *   * lastmodified - a timestamp of the last modification time
     *   * etag - An arbitrary string, surrounded by double-quotes. (e.g.: "abcdef"')
     *   * calendarid - The calendarid as it was passed to this function.
     *   * size - The size of the calendar objects, in bytes.
     *
     * Note that the etag is optional, but it's highly encouraged to return for
     * speed reasons.
     *
     * If neither etag or size are specified, the calendardata will be
     * used/fetched to determine these numbers. If both are specified the
     * amount of times this is needed is reduced by a great degree.
     *
     * @param mixed $calendarId
     * @return array
     */
    public function getCalendarObjects($calendarId)
    {
        console(__METHOD__, $calendarId);

        $query = array();
        $events = array();
        $storage = $this->get_storage_folder($calendarId);
        if ($storage) {
            foreach ((array)$storage->select($query) as $event) {
                $events[] = array(
                    'id' => $event['uid'],
                    'uri' => $event['uid'] . '.ics',
                    'lastmodified' => $event['changed']->format('U'),
                    'calendarid' => $calendarId,
                    'etag' => self::_get_etag($event),
                    'size' => $event['_size'],
                );
            }
        }

        return $events;
    }


    /**
     * Returns information from a single calendar object, based on it's object
     * uri.
     *
     * The returned array must have the same keys as getCalendarObjects. The
     * 'calendardata' object is required here though, while it's not required
     * for getCalendarObjects.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @return array
     */
    public function getCalendarObject($calendarId, $objectUri)
    {
        console(__METHOD__, $calendarId, $objectUri);

        $uid = basename($objectUri, '.ics');
        $storage = $this->get_storage_folder($calendarId);

        // attachment content is requested
        if (preg_match('!^(.+).ics:attachment:(\d+):.+$!', $objectUri, $m)) {
            $uid = $m[1]; $part = $m[2];
        }

        if ($storage && ($event = $storage->get_object($uid))) {
            // deliver attachment content directly
            if ($part && !empty($event['_attachments'])) {
                foreach ($event['_attachments'] as $attachment) {
                    if ($attachment['id'] == $part) {
                        header('Content-Type: ' . $attachment['mimetype']);
                        header('Content-Disposition: inline; filename="' . $attachment['name'] . '"');
                        $storage->get_attachment($uid, $part, null, true);
                        exit;
                    }
                }
            }

            $base_uri = DAVBackend::abs_url(array(
                CalDAV\Plugin::CALENDAR_ROOT,
                preg_replace('!principals/!', '', $this->calendars[$calendarId]['principaluri']),
                $calendarId,
                $event['uid'] . '.ics',
            ));

            // default response
            return array(
                'id' => $event['uid'],
                'uri' => $event['uid'] . '.ics',
                'lastmodified' => $event['changed']->format('U'),
                'calendarid' => $calendarId,
                'calendardata' => $this->_to_ical($event, $base_uri, $storage),
                'etag' => self::_get_etag($event),
            );
        }

        return array();
    }


    /**
     * Creates a new calendar object.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @return string|null
     */
    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        console(__METHOD__, $calendarId, $objectUri, $calendarData);

        $uid = basename($objectUri, '.ics');
        $storage = $this->get_storage_folder($calendarId);
        $object = $this->parse_calendar_data($calendarData, $uid);

        if ($object['uid'] == $uid) {
            $success = $storage->save($object, $object['_type']);
            if (!$success) {
                rcube::raise_error(array(
                    'code' => 600, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Error saving $object[_type] object to Kolab server"),
                    true, false);

                throw new DAV\Exception('Error saving calendar object to backend');
            }
        }
        else {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error creating calendar object: UID doesn't match object URI"),
                true, false);

             throw new DAV\Exception\NotFound("UID doesn't match object URI");
        }

        // return new Etag
        return $success ? self::_get_etag($object) : null;
    }

    /**
     * Updates an existing calendarobject, based on it's uri.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @return string|null
     */
    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        console(__METHOD__, $calendarId, $objectUri, $calendarData);

        $uid = basename($objectUri, '.ics');
        $storage = $this->get_storage_folder($calendarId);
        $object = $this->parse_calendar_data($calendarData, $uid);

        // sanity check
        if ($object['uid'] != $uid) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error creating calendar object: UID doesn't match object URI"),
                true, false);

            throw new DAV\Exception\NotFound("UID doesn't match object URI");
        }

        // copy meta data (starting with _) from old object
        $old = $storage->get_object($uid);
        foreach ((array)$old as $key => $val) {
            if (!isset($object[$key]) && $key[0] == '_')
                $object[$key] = $val;
        }

        // TODO: remove attachments not listed anymore

        // save object
        $saved = $storage->save($object, $object['_type'], $uid);
        if (!$saved) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error saving event object to Kolab server"),
                true, false);

            throw new DAV\Exception('Error saving event object to backend');
        }

        // return new Etag
        return self::_get_etag($object);
    }

    /**
     * Deletes an existing calendar object.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @return void
     */
    public function deleteCalendarObject($calendarId, $objectUri)
    {
        console(__METHOD__, $calendarId, $objectUri);

        $uid = basename($objectUri, '.ics');
        if ($storage = $this->get_storage_folder($calendarId)) {
            $storage->delete($uid);
        }
    }

    /**
     * Performs a calendar-query on the contents of this calendar.
     *
     * The calendar-query is defined in RFC4791 : CalDAV. Using the
     * calendar-query it is possible for a client to request a specific set of
     * object, based on contents of iCalendar properties, date-ranges and
     * iCalendar component types (VTODO, VEVENT).
     *
     * This method should just return a list of (relative) urls that match this
     * query.
     *
     * The list of filters are specified as an array. The exact array is
     * documented by Sabre\CalDAV\CalendarQueryParser.
     *
     * Note that it is extremely likely that getCalendarObject for every path
     * returned from this method will be called almost immediately after. You
     * may want to anticipate this to speed up these requests.
     *
     * Requests that are extremely common are:
     *   * requests for just VEVENTS
     *   * requests for just VTODO
     *   * requests with a time-range-filter on either VEVENT or VTODO.
     *
     * ..and combinations of these requests. It may not be worth it to try to
     * handle every possible situation and just rely on the (relatively
     * easy to use) CalendarQueryValidator to handle the rest.
     *
     * Note that especially time-range-filters may be difficult to parse. A
     * time-range filter specified on a VEVENT must for instance also handle
     * recurrence rules correctly.
     * A good example of how to interprete all these filters can also simply
     * be found in Sabre\CalDAV\CalendarQueryFilter. This class is as correct
     * as possible, so it gives you a good idea on what type of stuff you need
     * to think of.
     *
     * @param mixed $calendarId
     * @param array $filters
     * @return array
     */
    public function calendarQuery($calendarId, array $filters)
    {
      console(__METHOD__, $calendarId, $filters);

      // build kolab storage query from $filters
      $query = array();
      foreach ((array)$filters['comp-filters'] as $filter) {
          if ($filter['name'] != 'VEVENT')
              continue;
          if (is_array($filter['time-range'])) {
              $query[] = array('dtstart', '<=', $filter['time-range']['end']);
              $query[] = array('dtend',   '>=', $filter['time-range']['start']);
          }
      }

      $results = array();
      if ($storage = $this->get_storage_folder($calendarId)) {
          foreach ((array)$storage->select($query) as $event) {
              // TODO: cache the already fetched events in memory (really?)
              $results[] = $event['uid'] . '.ics';
          }
      }

      return $results;
    }

    /**
     * Set User-Agent string of the connected client
     */
    public function setUserAgent($uastring)
    {
        $ua_classes = array(
            'ical'      => 'iCal/\d',
            'outlook'   => 'iCal4OL/\d',
            'lightning' => 'Lightning/\d',
        );

        foreach ($ua_classes as $class => $regex) {
            if (preg_match("!$regex!", $uastring)) {
                $this->useragent = $class;
                break;
            }
        }
    }


    /**********  Data conversion utilities  ***********/

    private $attendee_keymap = array('name' => 'CN', 'status' => 'PARTSTAT', 'role' => 'ROLE', 'cutype' => 'CUTYPE', 'rsvp' => 'RSVP');

    /**
     * Parse the given iCal string into a hash array kolab_format_event can handle
     *
     * @param string iCal data block
     * @return array Hash array with event properties or null on failure
     */
    private function parse_calendar_data($calendarData, $uid)
    {
        try {
            // use already parsed object
            if (Plugin::$parsed_vevent && Plugin::$parsed_vevent->UID == $uid) {
                $vobject = Plugin::$parsed_vcalendar;
                $vevent = Plugin::$parsed_vevent;
            }
            else {
                $vobject = VObject\Reader::read($calendarData, VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);
                if ($vobject->name == 'VCALENDAR') {
                    foreach ($vobject->getBaseComponents() as $ve) {
                        if ($ve->name == 'VEVENT' || $ve->name == 'VTODO') {
                            $vevent = $ve;
                            break;
                        }
                    }
                }
            }

            // convert the VEvent object into a hash array
            if ($vevent && $vevent->name == 'VEVENT' || $vevent->name == 'VTODO') {
                $object = $this->_to_array($vevent);
                if (!empty($object['uid'])) {
                    // parse recurrence exceptions
                    if ($object['recurrence']) {
                        foreach ($vobject->children as $i => $component) {
                            if ($component->name == 'VEVENT' && isset($component->{'RECURRENCE-ID'})) {
                                $object['recurrence']['EXCEPTIONS'][] = $this->_to_array($component);
                            }
                        }
                    }

                    return $object;
                }
            }
        }
        catch (VObject\ParseException $e) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "iCal data parse error: " . $e->getMessage()),
                true, false);
        }

        return null;
    }

    /**
     * Convert the given Sabre\VObject\Component\Vevent object to a libkolab compatible event format
     *
     * @param object Vevent object to convert
     * @return array Hash array with event properties
     * @TODO: move this to libcalendaring for common use
     */
    private function _to_array($ve)
    {
        $event = array(
            'uid'     => strval($ve->UID),
            'title'   => strval($ve->SUMMARY),
            'created' => $ve->CREATED ? $ve->CREATED->getDateTime() : null,
            'changed' => $ve->DTSTAMP->getDateTime(),
            '_type'   => $ve->name == 'VTODO' ? 'task' : 'event',
            // set defaults
            'free_busy' => 'busy',
            'priority' => 0,
            'attendees' => array(),
        );

        // map other attributes to internal fields
        $_attendees = array();
        foreach ($ve->children as $prop) {
            if (!($prop instanceof VObject\Property))
                continue;

            switch ($prop->name) {
            case 'DTSTART':
            case 'DTEND':
            case 'DUE':
                $propmap = array('DTSTART' => 'start', 'DTEND' => 'end', 'DUE' => 'due');
                $event[$propmap[$prop->name]] =  VObjectUtils::convert_datetime($prop);
                break;

            case 'TRANSP':
                $event['free_busy'] = $prop->value == 'TRANSPARENT' ? 'free' : 'busy';
                break;

            case 'STATUS':
                if ($prop->value == 'TENTATIVE')
                    $event['free_busy'] = 'tentative';
                else if ($prop->value == 'CANCELLED')
                    $event['cancelled'] = true;
                else if ($prop->value == 'COMPLETED')
                    $event['complete'] = 100;
                break;

            case 'PRIORITY':
                if (is_numeric($prop->value))
                    $event['priority'] = $prop->value;
                break;

            case 'RRULE':
                $params = array();
                // parse recurrence rule attributes
                foreach (explode(';', $prop->value) as $par) {
                    list($k, $v) = explode('=', $par);
                    $params[$k] = $v;
                }
                if ($params['UNTIL'])
                    $params['UNTIL'] = date_create($params['UNTIL']);
                if (!$params['INTERVAL'])
                    $params['INTERVAL'] = 1;

                $event['recurrence'] = $params;
                break;

            case 'EXDATE':
                $event['recurrence']['EXDATE'] = array_merge((array)$event['recurrence']['EXDATE'], (array)VObjectUtils::convert_datetime($prop));
                break;

            case 'RECURRENCE-ID':
                // $event['recurrence_id'] = VObjectUtils::convert_datetime($prop);
                break;

            case 'RELATED-TO':
                if ($prop->offsetGet('RELTYPE') == 'PARENT') {
                    $event['parent_id'] = $prop->value;
                }
                break;

            case 'SEQUENCE':
                $event['sequence'] = intval($prop->value);
                break;

            case 'PERCENT-COMPLETE':
                $event['complete'] = intval($prop->value);
                break;

            case 'DESCRIPTION':
            case 'LOCATION':
            case 'URL':
                $event[strtolower($prop->name)] = $prop->value;
                break;

            case 'CATEGORY':
            case 'CATEGORIES':
                $event['categories'] = $prop->getParts();
                break;

            case 'CLASS':
            case 'X-CALENDARSERVER-ACCESS':
                $event['sensitivity'] = strtolower($prop->value);
                break;

            case 'X-MICROSOFT-CDO-BUSYSTATUS':
                if ($prop->value == 'OOF')
                    $event['free_busy'] == 'outofoffice';
                else if (in_array($prop->value, array('FREE', 'BUSY', 'TENTATIVE')))
                    $event['free_busy'] = strtolower($prop->value);
                break;

            case 'ATTENDEE':
            case 'ORGANIZER':
                $params = array();
                foreach ($prop->parameters as $param) {
                    switch ($param->name) {
                        case 'RSVP': $params[$param->name] = strtolower($param->value) == 'true'; break;
                        default:     $params[$param->name] = $param->value; break;
                    }
                }
                $attendee = VObjectUtils::map_keys($params, array_flip($this->attendee_keymap));
                $attendee['email'] = preg_replace('/^mailto:/i', '', $prop->value);

                if ($prop->name == 'ORGANIZER') {
                    $attendee['status'] = 'ACCEPTED';
                    $event['organizer'] = $attendee;
                }
                else if ($attendee['email'] != $event['organizer']['email']) {
                    $event['attendees'][] = $attendee;
                }
                break;

            case 'ATTACH':
                if (substr($prop->value, 0, 4) == 'http' && !strpos($prop->value, ':attachment:')) {
                    $event['links'][] = $prop->value;
                }
                break;

            default:
                if (substr($prop->name, 0, 2) == 'X-')
                    $event['x-custom'][] = array($prop->name, strval($prop->value));
                break;
            }
        }

        // check for all-day dates
        if ($event['start']->_dateonly) {
            $event['allday'] = true;
        }

        if ($event['allday'] && is_object($event['end'])) {
            $event['end']->sub(new \DateInterval('PT23H'));
        }

        // find alarms
        if ($valarms = $ve->select('VALARM')) {
            $action = 'DISPLAY';
            $trigger = null;

            $valarm = reset($valarms);
            foreach ($valarm->children as $prop) {
                switch ($prop->name) {
                case 'TRIGGER':
                    foreach ($prop->parameters as $param) {
                        if ($param->name == 'VALUE' && $param->value == 'DATE-TIME') {
                            $trigger = '@' . $prop->getDateTime()->format('U');
                        }
                    }
                    if (!$trigger) {
                        $trigger = preg_replace('/PT/', '', $prop->value);
                    }
                    break;

                case 'ACTION':
                    $action = $prop->value;
                    break;
                }
            }

            if ($trigger)
                $event['alarms'] = $trigger . ':' . $action;
        }

        return $event;
    }


    /**
     * Build a valid iCal format block from the given event
     *
     * @param array Hash array with event/task properties from libkolab
     * @param string Absolute URI referenceing this event object
     * @param object RECURRENCE-ID property when serializing a recurrence exception
     * @return mixed VCALENDAR string containing the VEVENT data
     *    or VObject\VEvent object with a recurrence exception instance
     * @TODO: move this to libcalendaring for common use
     */
    private function _to_ical($event, $base_uri, $storage, $recurrence_id = null)
    {
        $type = $event['_type'] ?: 'event';
        $ve = VObject\Component::create($this->type_component_map[$type]);
        $ve->add('UID', $event['uid']);

        if (!empty($event['created']))
            $ve->add(VObjectUtils::datetime_prop('CREATED', $event['created'], true));
        if (!empty($event['changed']))
            $ve->add(VObjectUtils::datetime_prop('DTSTAMP', $event['changed'], true));
        if (!empty($event['start']))
            $ve->add(VObjectUtils::datetime_prop('DTSTART', $event['start'], false));
        if (!empty($event['end']))
            $ve->add(VObjectUtils::datetime_prop('DTEND',   $event['end'], false));
        if (!empty($event['due']))
            $ve->add(VObjectUtils::datetime_prop('DUE',   $event['due'], false));

        if ($recurrence_id)
            $ve->add($recurrence_id);

        $ve->add('SUMMARY', $event['title']);

        if ($event['location'])
            $ve->add('LOCATION', $event['location']);
        if ($event['description'])
            $ve->add('DESCRIPTION', strtr($event['description'], array("\r\n" => "\n", "\r" => "\n"))); // normalize line endings

        if ($event['sequence'])
            $ve->add('SEQUENCE', $event['sequence']);

        if ($event['recurrence'] && !$recurrence_id) {
            if ($exdates = $event['recurrence']['EXDATE']) {
                unset($event['recurrence']['EXDATE']);  // don't serialize EXDATEs into RRULE value
            }

            $ve->add('RRULE', libcalendaring::to_rrule($event['recurrence']));

            // add EXDATEs each one per line (for Thunderbird Lightning)
            if ($exdates) {
                foreach ($exdates as $ex) {
                    if ($ex instanceof \DateTime) {
                        $exd = clone $event['start'];
                        $exd->setDate($ex->format('Y'), $ex->format('n'), $ex->format('j'));
                        $exd->setTimeZone(new \DateTimeZone('UTC'));
                        $ve->add(new VObject\Property('EXDATE', $exd->format('Ymd\\THis\\Z')));
                    }
                }
            }
        }

        if ($event['categories']) {
            $cat = VObject\Property::create('CATEGORIES');
            $cat->setParts((array)$event['categories']);
            $ve->add($cat);
        }

        $ve->add('TRANSP', $event['free_busy'] == 'free' ? 'TRANSPARENT' : 'OPAQUE');

        if ($event['priority'])
          $ve->add('PRIORITY', $event['priority']);

        if ($event['cancelled'])
            $ve->add('STATUS', 'CANCELLED');
        else if ($event['free_busy'] == 'tentative')
            $ve->add('STATUS', 'TENTATIVE');
        else if ($event['complete'] == 100)
            $ve->add('STATUS', 'COMPLETED');

        if (!empty($event['sensitivity']))
            $ve->add('CLASS', strtoupper($event['sensitivity']));

        if (isset($event['complete'])) {
            $ve->add('PERCENT-COMPLETE', intval($event['complete']));
            // Apple iCal required the COMPLETED date to be set in order to consider a task complete
            if ($event['complete'] == 100)
                $ve->add(VObjectUtils::datetime_prop('COMPLETED', $event['changed'] ?: new DateTime('now - 1 hour'), true));
        }

        if ($event['alarms']) {
            $va = VObject\Component::create('VALARM');
            list($trigger, $va->action) = explode(':', $event['alarms']);
            $val = libcalendaring::parse_alaram_value($trigger);
            if ($val[1]) $va->add('TRIGGER', preg_replace('/^([-+])(.+)/', '\\1PT\\2', $trigger));
            else         $va->add('TRIGGER', gmdate('Ymd\THis\Z', $val[0]), array('VALUE' => 'DATE-TIME'));
            $ve->add($va);
        }

        if ($event['organizer']) {
            unset($event['organizer']['rsvp'], $event['organizer']['role']);
            $ve->add('ORGANIZER', 'mailto:' . $event['organizer']['email'], VObjectUtils::map_keys($event['organizer'], $this->attendee_keymap));
        }

        foreach ((array)$event['attendees'] as $attendee) {
            if ($event['organizer'] && $attendee['role'] == 'ORGANIZER')
                continue;
            $attendee['rsvp'] = $attendee['rsvp'] ? 'TRUE' : null;
            $ve->add('ATTENDEE', 'mailto:' . $attendee['email'], VObjectUtils::map_keys($attendee, $this->attendee_keymap));
        }

        foreach ((array)$event['_attachments'] as $attachment) {
            if ($this->useragent == 'ical') {
                // embed attachments for iCal
                $ve->add('ATTACH',
                    base64_encode($storage->get_attachment($event['uid'], $attachment['id'])),
                    array('FMTTYPE' => $attachment['mimetype'], 'ENCODING' => 'BASE64', 'VALUE' => 'BINARY'));
            }
            else {
                // list attachments as absolute URIs
                $ve->add('ATTACH',
                    $base_uri . ':attachment:' . $attachment['id'] . ':' . urlencode($attachment['name']),
                    array('FMTTYPE' => $attachment['mimetype'], 'VALUE' => 'URI'));
            }
        }

        foreach ((array)$event['url'] as $url) {
            $ve->add('URL', $url);
        }

        foreach ((array)$event['links'] as $uri) {
            $ve->add('ATTACH', $uri);
        }

        if (!empty($event['parent_id'])) {
            $ve->add('RELATED-TO', $event['parent_id'], array('RELTYPE' => 'PARENT'));
        }

        // add custom properties
        foreach ((array)$event['x-custom'] as $prop) {
            $ve->add($prop[0], $prop[1]);
        }

        // we're dealing with a recurrence exception here, so no final serialization is desired
        if ($recurrence_id)
            return $ve;

        // encapsulate in VCALENDAR container
        $vcal = VObject\Component::create('VCALENDAR');
        $vcal->version = '2.0';
        $vcal->prodid = '-//Kolab DAV Server ' .KOLAB_DAV_VERSION . '//Sabre//Sabre VObject ' . CalDAV\Version::VERSION . '//EN';
        $vcal->calscale = 'GREGORIAN';
        $vcal->add($ve);

        // append recurrence exceptions
        if ($event['recurrence']['EXCEPTIONS']) {
            foreach ($event['recurrence']['EXCEPTIONS'] as $ex) {
                $exdate = clone $event['start'];
                $exdate->setDate($ex['start']->format('Y'), $ex['start']->format('n'), $ex['start']->format('j'));
                $recurrence_id = VObjectUtils::datetime_prop('RECURRENCE-ID', $exdate);
                // if ($ex['thisandfuture'])  // not supported by any client :-(
                //    $recurrence_id->add('RANGE', 'THISANDFUTURE');
                $vcal->add($this->_to_ical($ex, $base_uri, $storage, $recurrence_id));
            }
        }


        return $vcal->serialize();
    }


    /**
     * Generate an Etag string from the given event data
     *
     * @param array Hash array with event properties from libkolab
     * @return string Etag string
     */
    private static function _get_etag($event)
    {
        return sprintf('"%s-%d"', substr(md5($event['uid']), 0, 16), $event['_msguid']);
    }

}
