<?php

/**
 * SabreDAV Calendar derived class to encapsulate a Kolab storage folder
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
use \kolab_storage;
use Sabre\CalDAV\Backend;

/**
 * This object represents a CalDAV calendar.
 *
 * A calendar can contain multiple TODO and or Events. These are represented
 * as \Sabre\CalDAV\CalendarObject objects.
 */
class Calendar extends \Sabre\CalDAV\Calendar
{
    public $id;
    public $storage;
    public $ready = false;


    /**
     * Default constructor
     */
    public function __construct(Backend\BackendInterface $caldavBackend, $calendarInfo)
    {
        parent::__construct($caldavBackend, $calendarInfo);

        $this->id = $calendarInfo['id'];
        $this->storage = $caldavBackend->get_storage_folder($this->id);
        $this->ready = is_object($this->storage) && is_a($this->storage, 'kolab_storage_folder');
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
        if ($this->storage->get_namespace() == 'personal') {
            return $this->calendarInfo['principaluri'];
        }
        else {
            return 'principals/' . $this->storage->get_owner();
        }
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
        // return ACL information based on IMAP MYRIGHTS
        $rights = $this->storage->get_myrights();
        if ($rights && !PEAR::isError($rights)) {
            // user has at least read access to calendar folders listed
            $acl = array(
                array(
                    'privilege' => '{DAV:}read',
                    'principal' => $this->calendarInfo['principaluri'],
                    'protected' => true,
                ),
            );

            $owner = $this->getOwner();
            $is_owner = $owner == $this->calendarInfo['principaluri'];

            if ($is_owner || strpos($rights, 'i') !== false) {
                $acl[] = array(
                    'privilege' => '{DAV:}write',
                    'principal' => $this->calendarInfo['principaluri'],
                    'protected' => true,
                );
            }

            return $acl;
        }
        else {
            // fallback to default ACL rules based on ownership
            return parent::getACL();
        }
    }

    /**
     * This method returns the ACL's for calendar objects in this calendar.
     * The result of this method automatically gets passed to the
     * calendar-object nodes in the calendar.
     *
     * @return array
     */
    function getChildACL()
    {
        static $myacl;

        $acl = [
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->calendarInfo['principaluri'],
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-read',
                'protected' => true,
            ],
        ];

        if (empty($this->calendarInfo['{http://sabredav.org/ns}read-only'])) {
            // check write privileges of address book node
            if (!$myacl) {
                $myacl = $this->getACL();
            }

            $write = false;
            array_walk($myacl, function($acl, $i) use (&$write) {
                if ($acl['privilege'] == '{DAV:}write') {
                    $write = true;
                }
            });

            if ($write) {
                $acl[] = [
                    'privilege' => '{DAV:}write',
                    'principal' => $this->calendarInfo['principaluri'],
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}write',
                    'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                    'protected' => true,
                ];
            }
        }
        return $acl;
    }
}
