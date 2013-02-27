<?php

/**
 * SabreDAV Principals Backend implementation for Kolab.
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

namespace Kolab\DAVACL;

use Sabre\DAV\Exception;
use Sabre\DAV\URLUtil;
use Kolab\DAV\Auth\HTTPBasic;

/**
 * Kolab Principal Backend
 */
class PrincipalBackend implements \Sabre\DAVACL\PrincipalBackend\BackendInterface
{
    protected $fieldmap = array(
         // The users' real name.
        '{DAV:}displayname' => 'displayname',

         // The users' primary email-address.
        '{http://sabredav.org/ns}email-address' => 'email',

        /**
         * This property is actually used by the CardDAV plugin, where it gets
         * mapped to {http://calendarserver.orgi/ns/}me-card.
         */
        '{http://sabredav.org/ns}vcard-url' => 'vcardurl',
    );

    /**
     * Sets up the backend.
     */
    public function __construct()
    {

    }

    /**
     * Returns a pricipal record for the currently authenticated user
     */
    public function getCurrentUser()
    {
        console(__METHOD__, HTTPBasic::$current_user);

        if (HTTPBasic::$current_user) {
            return array(
                'uri' => 'principals/' . HTTPBasic::$current_user,
                '{DAV:}displayname' => HTTPBasic::$current_user,
            );
        }

        return false;
    }


    /**
     * Returns a list of principals based on a prefix.
     *
     * This prefix will often contain something like 'principals'. You are only
     * expected to return principals that are in this base path.
     *
     * You are expected to return at least a 'uri' for every user, you can
     * return any additional properties if you wish so. Common properties are:
     *   {DAV:}displayname
     *   {http://sabredav.org/ns}email-address - This is a custom SabreDAV
     *     field that's actualy injected in a number of other properties. If
     *     you have an email address, use this property.
     *
     * @param string $prefixPath
     * @return array
     */
    public function getPrincipalsByPrefix($prefixPath)
    {
        console(__METHOD__, $prefixPath);

        $principals = array();

        if ($prefixPath == 'principals') {
            // TODO: list users from LDAP

            // we currently only advertise the authenticated user
            if ($user = $this->getCurrentUser()) {
                $principals[] = $user;
            }
        }

        return $principals;
    }

    /**
     * Returns a specific principal, specified by it's path.
     * The returned structure should be the exact same as from
     * getPrincipalsByPrefix.
     *
     * @param string $path
     * @return array
     */
    public function getPrincipalByPath($path)
    {
        console(__METHOD__, $path);

        list($prefix,$name) = explode('/', $path);

        if ($prefix == 'principals' && $name == HTTPBasic::$current_user) {
            return $this->getCurrentUser();
        }

        return null;
    }

    /**
     * Returns the list of members for a group-principal
     *
     * @param string $principal
     * @return array
     */
    public function getGroupMemberSet($principal)
    {
        // TODO: for now the group principal has only one member, the user itself
        list($prefix, $name) = URLUtil::splitPath($principal);

        $principal = $this->getPrincipalByPath($prefix);
        if (!$principal) throw new Exception('Principal not found');

        return array(
            $prefix
        );
    }

    /**
     * Returns the list of groups a principal is a member of
     *
     * @param string $principal
     * @return array
     */
    public function getGroupMembership($principal)
    {
        list($prefix,$name) = URLUtil::splitPath($principal);

        $group_membership = array();
        if ($prefix == 'principals') {
            $principal = $this->getPrincipalByPath($principal);
            if (!$principal) throw new Exception('Principal not found');

            // TODO: for now the user principal has only its own groups
            return array(
                'principals/'.$name.'/calendar-proxy-read',
                'principals/'.$name.'/calendar-proxy-write',
                // The addressbook groups are not supported in Sabre,
                // see http://groups.google.com/group/sabredav-discuss/browse_thread/thread/ef2fa9759d55f8c#msg_5720afc11602e753
                //'principals/'.$name.'/addressbook-proxy-read',
                //'principals/'.$name.'/addressbook-proxy-write',
            );
        }
        return $group_membership;
    }

    /**
     * Updates the list of group members for a group principal.
     *
     * The principals should be passed as a list of uri's.
     *
     * @param string $principal
     * @param array $members
     * @return void
     */
    public function setGroupMemberSet($principal, array $members)
    {
        throw new Exception('Setting members of the group is not supported yet');
    }

    function updatePrincipal($path, $mutations)
    {
        return 0;
    }

    function searchPrincipals($prefixPath, array $searchProperties)
    {
        return 0;
    }

}
