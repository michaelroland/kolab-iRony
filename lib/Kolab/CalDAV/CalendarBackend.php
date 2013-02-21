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
use \rcube_charset;
use \kolab_storage;
use \libcalendaring;
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

    /**
     * Read available calendar folders from server
     */
    private function _read_calendars()
    {
        // already read sources
        if (isset($this->calendars))
            return $this->calendars;

        // get all folders that have "event" type
        $folders = kolab_storage::get_folders('event');
        $this->calendars = $this->folders = array();

        // convert to UTF8 and sort
        $names = array();
        foreach ($folders as $folder) {
            $folders[$folder->name] = $folder;
            $names[$folder->name] = rcube_charset::convert($folder->name, 'UTF7-IMAP');
        }

        asort($names, SORT_LOCALE_STRING);

        foreach ($names as $utf7name => $name) {
            $id = urlencode($utf7name);
            $folder = $this->folders[$id] = $folders[$utf7name];
            $fdata = $folder->get_imap_data();  // fetch IMAP folder data for CTag generation
            $this->calendars[$id] = array(
                'id' => $id,
                'uri' => $id,
                '{DAV:}displayname' => $name,
                '{http://apple.com/ns/ical/}calendar-color' => $this->get_color($folders[$utf7name]),
                '{http://calendarserver.org/ns/}getctag' => sprintf('%d-%d-%d', $fdata['UIDVALIDITY'], $fdata['HIGHESTMODSEQ'], $fdata['UIDNEXT']),
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new CalDAV\Property\SupportedCalendarComponentSet(array('VEVENT')),
                '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new CalDAV\Property\ScheduleCalendarTransp('opaque'),
            );
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
        if ($this->folders[$id]) {
            return $this->folders[$id];
        }
        else {
            $storage = kolab_storage::get_folder(urldecode($id));
            return !PEAR::isError($this->storage) ? $storage : null;
        }
    }

    /**
     * Helper method to extract calendar color from metadata
     */
    private function get_color($folder)
    {
        // color is defined in folder METADATA
        $metadata = $folder->get_metadata(array(kolab_storage::COLOR_KEY_PRIVATE, kolab_storage::COLOR_KEY_SHARED));
        if (($color = $metadata[kolab_storage::COLOR_KEY_PRIVATE]) || ($color = $metadata[kolab_storage::COLOR_KEY_SHARED])) {
            return $color;
        }

        return '';
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
        $this->_read_calendars();

        $calendars = array();
        foreach ($this->calendars as $id => $cal) {
            $cal['principaluri'] = $principalUri;
            $calendars[] = $cal;
        }

        return $calendars;
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
    public function createCalendar($principalUri,$calendarUri,array $properties)
    {
        // TODO: implement this
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
        // TODO: implement this
        return false;
    }

    /**
     * Delete a calendar and all it's objects
     *
     * @param mixed $calendarId
     * @return void
     */
    public function deleteCalendar($calendarId)
    {
        // TODO: implement this
    }


    /**
     * Returns all calendar objects within a calendar.
     *
     * Every item contains an array with the following keys:
     *   * id - unique identifier which will be used for subsequent updates
     *   * calendardata - The iCalendar-compatible calendar data
     *   * uri - a unique key which will be used to construct the uri. This can be any arbitrary string.
     *   * lastmodified - a timestamp of the last modification time
     *   * etag - An arbitrary string, surrounded by double-quotes. (e.g.:
     *   '  "abcdef"')
     *   * calendarid - The calendarid as it was passed to this function.
     *   * size - The size of the calendar objects, in bytes.
     *
     * Note that the etag is optional, but it's highly encouraged to return for
     * speed reasons.
     *
     * The calendardata is also optional. If it's not returned
     * 'getCalendarObject' will be called later, which *is* expected to return
     * calendardata.
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
                    'etag' => $this->_get_etag($event),
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
        console(__METHOD__, $calendarId, $objectUri);  # , debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));

        $uid = basename($objectUri, '.ics');
        $storage = $this->get_storage_folder($calendarId);

        if ($storage && ($event = $storage->get_object($uid))) {
            console('FOUND: ' . $event['uid']);
            return array(
                'id' => $event['uid'],
                'uri' => $event['uid'] . '.ics',
                'lastmodified' => $event['changed']->format('U'),
                'calendarid' => $calendarId,
                'calendardata' => $this->_to_ical($event),
                'etag' => $this->_get_etag($event),
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
        $object = $this->_parse_calendar_object($calendarData);

        if ($object['uid'] == $uid) {
            $success = $storage->save($object, 'event');
            if (!$success) {
                rcube::raise_error(array(
                    'code' => 600, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Error saving event object to Kolab server"),
                    true, false);
            }
        }
        else {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error creating calendar object: UID doesn't match object URI"),
                true, false);
        }

        // return new Etag
        return $success ? $this->_get_etag($object) : null;
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
        $object = $this->_parse_calendar_object($calendarData);

        // sanity check
        if ($object['uid'] != $uid) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error creating calendar object: UID doesn't match object URI"),
                true, false);

            return null;
        }

        // copy meta data (starting with _) from old object
        $old = $storage->get_object($uid);
        foreach ((array)$old as $key => $val) {
          if (!isset($object[$key]) && $key[0] == '_')
            $object[$key] = $val;
        }

        // save object
        $saved = $storage->save($object, 'event', $uid);
        if (!$saved) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error saving event object to Kolab server"),
                true, false);

            return null;
        }

        // return new Etag
        return $this->_get_etag($object);
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
     * This method provides a default implementation, which parses *all* the
     * iCalendar objects in the specified calendar.
     *
     * This default may well be good enough for personal use, and calendars
     * that aren't very large. But if you anticipate high usage, big calendars
     * or high loads, you are strongly adviced to optimize certain paths.
     *
     * The best way to do so is override this method and to optimize
     * specifically for 'common filters'.
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
        // TODO: implement this
        return array();
    }


    /**********  Data conversion utilities  ***********/

    /**
     * Parse the given iCal string into a hash array kolab_format_event can handle
     *
     * @param string iCal data block
     * @return array Hash array with event properties or null on failure
     */
    private function _parse_calendar_object($calendarData)
    {
        try {
            $vobject = VObject\Reader::read($calendarData, VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);

            if ($vobject->name == 'VCALENDAR') {
                foreach ($vobject->getBaseComponents('VEVENT') as $vevent) {
                    $object = $this->_to_array($vevent);
                    if (!empty($object['uid'])) {
                        return $object;
                    }
                }
            }
        }
        catch (VObject\ParseException $e) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "iCal data Parse error: " . $e->getMessage()),
                true, false);
        }

        return null;
    }

    /**
     * Convert the given Sabre\VObject\Component\Vevent object to a libkolab compatible event format
     *
     * @param object Vevent object to convert
     * @return array Hash array with event properties
     */
    private function _to_array($ve)
    {
        $event = array(
            'uid'     => strval($ve->UID),
            'title'   => strval($ve->SUMMARY),
            'changed' => $ve->DTSTAMP->getDateTime(),
            'start'   => $this->_convert_datetime($ve->DTSTART),
            'end'     => $this->_convert_datetime($ve->DTEND),
            // set defaults
            'free_busy' => 'busy',
            'priority' => 0,
            'attendees' => array(),
        );

        // check for all-day dates
        if ($event['start']->_dateonly) {
            $event['allday'] = true;
        }

        if ($event['allday'] && is_object($event['end'])) {
            $event['end']->sub(new \DateInterval('PT23H'));
        }

        // map other attributes to internal fields
        $_attendees = array();
        foreach ($ve->children as $prop) {
            if (!($prop instanceof VObject\Property))
                continue;

            switch ($prop->name) {
            case 'ORGANIZER':
                break;

            case 'ATTENDEE':
                break;

            case 'TRANSP':
                $event['free_busy'] = $prop->value == 'TRANSPARENT' ? 'free' : 'busy';
                break;

            case 'STATUS':
                if ($prop->value == 'TENTATIVE')
                    $event['free_busy'] == 'tentative';
                break;

            case 'PRIORITY':
                if (is_numeric($prop->value))
                    $event['priority'] = $prop->value;
                break;

            case 'RRULE':
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
                $event['recurrence']['EXDATE'][] = $this->_convert_datetime($prop);
                break;

            case 'RECURRENCE-ID':
                // $event['recurrence_id'] = $this->_convert_datetime($prop);
                break;
            
            case 'SEQUENCE':
                $event['sequence'] = intval($prop->value);
                break;

            case 'DESCRIPTION':
            case 'LOCATION':
                $event[strtolower($prop->name)] = $prop->value;
                break;

            case 'CLASS':
            case 'X-CALENDARSERVER-ACCESS':
                $sensitivity_map = array('PUBLIC' => 0, 'PRIVATE' => 1, 'CONFIDENTIAL' => 2);
                $event['sensitivity'] = $sensitivity_map[$prop->value];
                break;

            case 'X-MICROSOFT-CDO-BUSYSTATUS':
                if ($prop->value == 'OOF')
                    $event['free_busy'] == 'outofoffice';
                else if (in_array($prop->value, array('FREE', 'BUSY', 'TENTATIVE')))
                    $event['free_busy'] = strtolower($prop->value);
                break;
            }
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
console($event);
        return $event;
    }

    /**
     * Helper method to correctly interpret an all-day date value
     */
    private function _convert_datetime($prop)
    {
        if (empty($prop)) {
            return null;
        }
        else if ($prop instanceof VObject\Property\DateTime) {
            $dt = $prop->getDateTime();
            if ($prop->getDateType() & VObject\Property\DateTime::DATE) {
                $dt->_dateonly = true;
            }
        }
        else if ($prop instanceof \DateTime) {
            $dt = $prop;
        }

        return $dt;
    }

    /**
     * Build a valid iCal format block from the given event
     *
     * @param array Hash array with event properties from libkolab
     * return string VCALENDAR block containing the VEVENT data
     */
    private function _to_ical($event)
    {
        $ve = VObject\Component::create('VEVENT');
        $ve->add('UID', $event['uid']);
        $ve->add('SUMMARY', $event['title']);
        $ve->add($this->_datetime_prop('DTSTAMP', $event['changed'], true));
        $ve->add($this->_datetime_prop('DTSTART', $event['start'], false));
        $ve->add($this->_datetime_prop('DTEND',   $event['end'], false));

        if ($event['location'])
            $ve->add('LOCATION', $event['location']);
        if ($event['description'])
            $ve->add('DESCRIPTION', $event['description']);

        if ($event['sequence'])
            $ve->add('SEQUENCE', $event['sequence']);

        if ($event['recurrence'])
            $ve->add('RRULE', libcalendaring::to_rrule($event['recurrence']));

        if ($event['alarms']) {
            $va = VObject\Component::create('VALARM');
            list($trigger, $va->action) = explode(':', $event['alarms']);
            $val = libcalendaring::parse_alaram_value($trigger);
            if ($val[1]) $va->add('TRIGGER', preg_replace('/^([-+])(.+)/', '\\1PT\\2', $trigger));
            else         $va->add('TRIGGER', gmdate('Ymd\THis\Z', $val[0]), array('VALUE' => 'DATE-TIME'));
            $ve->add($va);
        }

        // encapsulate in VCALENDAR container
        $vcal = VObject\Component::create('VCALENDAR');
        $vcal->version = '2.0';
        $vcal->prodid = '-//Kolab DAV Server ' .KOLAB_DAV_VERSION . '//Sabre//Sabre VObject ' . CalDAV\Version::VERSION . '//EN';
        $vcal->calscale = 'GREGORIAN';
        $vcal->add($ve);

        return $vcal->serialize();
    }

    /**
     * Create a Sabre\VObject\Property instance from a PHP DateTime object
     *
     * @param string Property name
     * @param object DateTime
     */
    private function _datetime_prop($name, $dt, $utc = false)
    {
        $vdt = new VObject\Property\DateTime($name);
        $vdt->setDateTime($dt, $db->_dateonly ? VObject\Property\DateTime::DATE : ($utc ? VObject\Property\DateTime::UTC : VObject\Property\DateTime::LOCALTZ));
        return $vdt;
    }


    /**
     * Generate an Etag string from the given event data
     *
     * @param array Hash array with event properties from libkolab
     * @return string Etag string
     */
    private function _get_etag($event)
    {
        return sprintf('"%s-%d"', substr(md5($event['uid']), 0, 16), $event['_msguid']);
    }
}
