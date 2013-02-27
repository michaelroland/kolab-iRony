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

    private $events = array();
    private $imap_folder = 'INBOX/Calendar';


    /**
     * Default constructor
     */
    public function __construct(Backend\BackendInterface $caldavBackend, $calendarInfo)
    {
        parent::__construct($caldavBackend, $calendarInfo);

        $this->id = $calendarInfo['id'];
        $this->imap_folder = urldecode($calendarInfo['id']);

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
            return null;  // return $this->storage->get_owner();
        }
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
        // TODO: return ACL infor based on $this->storage->get_myrights()
        return parent::getACL();

        $rights = $this->storage->get_myrights();
        if ($rights && !PEAR::isError($rights)) {

        }
    }

}
