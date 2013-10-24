<?php

/**
 * CalDAV scheduling inbox for the Kolab.
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

namespace Kolab\CalDAV\Schedule;

use Sabre\DAV;
use Sabre\CalDAV;
use Sabre\DAVACL;

/**
 * The CalDAV scheduling inbox
 */
class Inbox extends DAV\Collection implements DAV\ICollection, DAVACL\IACL
{
    /**
     * The principal Uri
     *
     * @var string
     */
    protected $principalUri;

    protected $principalId;

    /**
     * CalDAV backend
     *
     * @var Sabre\CalDAV\Backend\BackendInterface
     */
    protected $caldavBackend;

    /**
     * Constructor
     *
     * @param string $principalUri
     */
    public function __construct(CalDAV\Backend\BackendInterface $caldavBackend, $principalUri)
    {
        $this->caldavBackend = $caldavBackend;

        $principal = explode('/', $principalUri);
        $this->principalId = end($principal);
        $this->principalUri = $principalUri;

        $this->calendarInfo = array(
            'id' => 'inbox',
            'principaluri' => $principalUri,
        );
    }

    /**
     * Provide the URL for the default calendar for event scheduling
     */
    public function schedule_default_calendar_url()
    {
        if ($cal = $this->caldavBackend->get_default_calendar()) {
            return $this->principalId . '/' . $cal['uri'];
        }

        return false;
    }

    /**
     * Returns the name of the node.
     *
     * This is used to generate the url.
     *
     * @return string
     */
    public function getName()
    {
        console(__METHOD__);
        return 'inbox';
    }

    /**
     * Returns an array with all the child nodes
     *
     * @return \Sabre\DAV\INode[]
     */
    public function getChildren()
    {
        console(__METHOD__);

        $children = array();
        $objs = $this->caldavBackend->getSchedulingInboxObjects();
        foreach ($objs as $obj) {
            $children[] = new CalDAV\CalendarObject($this->caldavBackend, $this->calendarInfo, $obj);
        }

        return $children;
    }

    /**
     * Returns a child object, by its name.
     *
     * @param string $name
     * @throws Exception\NotFound
     * @return INode
     */
    public function getChild($name)
    {
        console(__METHOD__, $name);

        // TODO: improve this
        $objs = $this->caldavBackend->getSchedulingInboxObjects();
        foreach ($objs as $obj) {
            if ($obj['uri'] == $name) {
                return new CalDAV\CalendarObject($this->caldavBackend, $this->calendarInfo, $obj);
            }
        }

        throw new DAV\Exception\NotFound('File not found: ' . $name);
    }

    /**
     * Checks is a child-node exists.
     *
     * @param string $name
     * @return bool
     */
    public function childExists($name)
    {
        console(__METHOD__, $name);

        // TODO: improve this
        $objs = $this->caldavBackend->getSchedulingInboxObjects();
        foreach ($objs as $obj) {
            if ($obj['uri'] == $name) {
                return true;
            }
        }

        return false;
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
        return $this->principalUri;
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
    public function getACL() {

        return array(
            array(
                'privilege' => '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-deliver-invite',
                'principal' => $this->getOwner(),
                'protected' => true,
            ),
            array(
                'privilege' => '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-deliver-reply',
                'principal' => $this->getOwner(),
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->getOwner(),
                'protected' => true,
            ),
            array(
                'privilege' => '{DAV:}unbind',
                'principal' => $this->getOwner(),
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
        throw new DAV\Exception\MethodNotAllowed('You\'re not allowed to update the ACL');
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
        $default = DAVACL\Plugin::getDefaultSupportedPrivilegeSet();

        $default['aggregates'][] = array(
            'privilege' => '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-deliver-invite',
        );
        $default['aggregates'][] = array(
            'privilege' => '{' . CalDAV\Plugin::NS_CALDAV . '}schedule-deliver-reply',
        );

        return $default;
    }

}
