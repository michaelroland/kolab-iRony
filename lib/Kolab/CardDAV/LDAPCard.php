<?php

/**
 * Class that represents a single vCard node from an LDAP directory
 * with limited permissions (read-only).
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

namespace Kolab\CardDAV;

use Sabr\DAV;

/**
 * Represents a single vCard from an LDAP directory
 */
class LDAPCard extends \Sabre\CardDAV\Card
{
    /**
     * Updates the VCard-formatted object
     *
     * @param string $cardData
     * @return string|null
     */
    public function put($cardData)
    {
        throw new DAV\Exception\MethodNotAllowed('Modifying directory entries is not allowed');
    }

    /**
     * Deletes the card
     *
     * @return void
     */
    public function delete()
    {
        throw new DAV\Exception\MethodNotAllowed('Deleting directory entries is not allowed');
    }

    /**
     * Returns a list of ACE's for directory entries.
     *
     * @return array
     */
    public function getACL() {

        return array(
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->addressBookInfo['principaluri'],
                'protected' => true,
            ),
        );

    }
}

