<?php

/**
 * SabreDAV UserCalendars derived class for the Kolab.
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
use Sabre\DAVACL;
use Sabre\CalDAV\Backend;
use Kolab\CalDAV\Calendar;

/**
 * The UserCalenders class contains all calendars associated to one user
 *
 */
class UserCalendars extends \Sabre\CalDAV\UserCalendars implements DAV\IExtendedCollection, DAVACL\IACL
{
    /**
     * CalDAV backend
     *
     * @var Sabre\CalDAV\Backend\BackendInterface
     */
    protected $caldavBackend;

    /**
     * Principal information
     *
     * @var array
     */
    protected $principalInfo;

    /**
     * Constructor
     *
     * @param Backend\BackendInterface $caldavBackend
     * @param mixed $userUri
     */
    public function __construct(\Sabre\CalDAV\Backend\BackendInterface $caldavBackend, $principalInfo)
    {
        $this->caldavBackend = $caldavBackend;
        $this->principalInfo = $principalInfo;
    }

    /**
     * Returns the name of this object
     *
     * @return string
     */
    public function getName()
    {
        list(,$name) = DAV\URLUtil::splitPath($this->principalInfo['uri']);
        return $name;
    }

    /**
     * Updates the name of this object
     *
     * @param string $name
     * @return void
     */
    public function setName($name)
    {
        // TODO: implement this
        throw new DAV\Exception\Forbidden();
    }

    /**
     * Deletes this object
     *
     * @return void
     */
    public function delete()
    {
        // TODO: implement this
        throw new DAV\Exception\Forbidden();
    }

    /**
     * Returns the last modification date
     *
     * @return int
     */
    public function getLastModified()
    {
        return null;
    }

    /**
     * Creates a new file under this object.
     *
     * This is currently not allowed
     *
     * @param string $filename
     * @param resource $data
     * @return void
     */
    public function createFile($filename, $data=null)
    {
        throw new DAV\Exception\MethodNotAllowed('Creating new files in this collection is not supported');
    }

    /**
     * Creates a new directory under this object.
     *
     * @param string $filename
     * @return void
     */
    public function createDirectory($filename)
    {
        // TODO: implement this
        throw new DAV\Exception\MethodNotAllowed('Creating new collections in this collection is not supported');
    }

    /**
     * Returns a list of calendars
     *
     * @return array
     */
    public function getChildren()
    {
        $calendars = $this->caldavBackend->getCalendarsForUser($this->principalInfo['uri']);
        $objs = array();
        foreach ($calendars as $calendar) {
            // TODO: (later) add sharing support by implenting this all
            if ($this->caldavBackend instanceof Backend\SharingSupport) {
                if (isset($calendar['{http://calendarserver.org/ns/}shared-url'])) {
                    $objs[] = new SharedCalendar($this->caldavBackend, $calendar);
                }
                else {
                    $objs[] = new ShareableCalendar($this->caldavBackend, $calendar);
                }
            }
            else {
                $objs[] = new Calendar($this->caldavBackend, $calendar);
            }
        }

        // TODO: add notification support (check with clients first, if anybody supports it)
        if ($this->caldavBackend instanceof Backend\NotificationSupport) {
            $objs[] = new Notifications\Collection($this->caldavBackend, $this->principalInfo['uri']);
        }

        return $objs;
    }

    /**
     * Creates a new calendar
     *
     * @param string $name
     * @param array $resourceType
     * @param array $properties
     * @return void
     */
    public function createExtendedCollection($name, array $resourceType, array $properties)
    {
        $isCalendar = false;
        foreach($resourceType as $rt) {
            switch ($rt) {
                case '{DAV:}collection' :
                case '{http://calendarserver.org/ns/}shared-owner' :
                    // ignore
                    break;
                case '{urn:ietf:params:xml:ns:caldav}calendar' :
                    $isCalendar = true;
                    break;
                default :
                    throw new DAV\Exception\InvalidResourceType('Unknown resourceType: ' . $rt);
            }
        }
        if (!$isCalendar) {
            throw new DAV\Exception\InvalidResourceType('You can only create calendars in this collection');
        }

        $this->caldavBackend->createCalendar($this->principalInfo['uri'], $name, $properties);
    }

    /**
     * Returns the owner principal
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    public function getOwner()
    {
        return $this->principalInfo['uri'];
    }

    /**
     * Returns a group principal
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    public function getGroup()
    {
        return null;
    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   * 'privilege', a string such as {DAV:}read or {DAV:}write. These are
     *     currently the only supported privileges
     *   * 'principal', a url to the principal who owns the node
     *   * 'protected' (optional), indicating that this ACE is not allowed to
     *      be updated.
     *
     * @return array
     */
    public function getACL()
    {
        // TODO: implement this
        return array(
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'],
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}write',
                'principal' => $this->principalInfo['uri'],
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-write',
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}write',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-write',
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-read',
                'protected' => true,
            ),
        );
    }

    /**
     * Updates the ACL
     *
     * This method will receive a list of new ACE's.
     *
     * @param array $acl
     * @return void
     */
    public function setACL(array $acl)
    {
        // TODO: implement this
        throw new DAV\Exception\MethodNotAllowed('Changing ACL is not yet supported');
    }

    /**
     * Returns the list of supported privileges for this node.
     *
     * The returned data structure is a list of nested privileges.
     * See Sabre\DAVACL\Plugin::getDefaultSupportedPrivilegeSet for a simple
     * standard structure.
     *
     * If null is returned from this method, the default privilege set is used,
     * which is fine for most common usecases.
     *
     * @return array|null
     */
    public function getSupportedPrivilegeSet()
    {
        // TODO: implement this
        return null;
    }

    /**
     * This method is called when a user replied to a request to share.
     *
     * This method should return the url of the newly created calendar if the
     * share was accepted.
     *
     * @param string href The sharee who is replying (often a mailto: address)
     * @param int status One of the SharingPlugin::STATUS_* constants
     * @param string $calendarUri The url to the calendar thats being shared
     * @param string $inReplyTo The unique id this message is a response to
     * @param string $summary A description of the reply
     * @return null|string
     */
    public function shareReply($href, $status, $calendarUri, $inReplyTo, $summary = null)
    {
        if (!$this->caldavBackend instanceof Backend\SharingSupport) {
            throw new DAV\Exception\NotImplemented('Sharing support is not implemented by this backend.');
        }

        return $this->caldavBackend->shareReply($href, $status, $calendarUri, $inReplyTo, $summary);
    }

}
