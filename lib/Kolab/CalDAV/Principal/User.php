<?php

/**
 * SabreDAV Principal User derived class for the Kolab service.
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2014, Kolab Systems AG <contact@kolabsys.com>
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

namespace Kolab\CalDAV\Principal;

class User extends \Sabre\CalDAV\Principal\User
{
    /**
     * Returns a list of ACE's for this node.
     *
     * Adds {DAV:}read for {DAV:}authenticated to enable access to
     * principal records which are listed as owner of shared folders.
     *
     * @return array
     */
    public function getACL()
    {
        $acl = parent::getACL();
        $acl[] = array(
            'privilege' => '{DAV:}read',
            'principal' => '{DAV:}authenticated',
            'protected' => true,
        );
        return $acl;
    }
}