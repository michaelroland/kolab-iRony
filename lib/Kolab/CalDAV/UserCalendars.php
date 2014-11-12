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
use Sabre\CalDAV\Schedule;
use Kolab\CalDAV\Calendar;

/**
 * The UserCalenders class contains all calendars associated to one user
 *
 */
class UserCalendars extends \Sabre\CalDAV\CalendarHome implements DAV\IExtendedCollection, DAVACL\IACL
{
    /**
     * Checks if a calendar exists.
     *
     * @param string $name
     * @return bool
     */
    public function childExists($name)
    {
        // Special nodes
        if ($name === 'inbox' || $name === 'outbox') {
            return true;
        }
        if ($name === 'notifications') {
            return false;
        }

        if ($this->caldavBackend->getCalendarByName($name)) {
            return true;
        }
        return false;
    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   - 'privilege', a string such as {DAV:}read or {DAV:}write. These are currently the only supported privileges
     *   - 'principal', a url to the principal who owns the node
     *   - 'protected' (optional), indicating that this ACE is not allowed to be updated.
     *
     * @return array
     */
    public function getACL()
    {
        // define rights for the user's calendar root (which is in fact INBOX)
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
/* TODO: implement sharing support
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
*/
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

}
