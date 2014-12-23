<?php

/**
 * Extended CalDAV scheduling inbox for the Kolab DAV server
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

namespace Kolab\CalDAV;

use Sabre\DAV;
use Sabre\CalDAV;

/**
 * Extended CalDAV scheduling inbox
 */
class ScheduleInbox extends CalDAV\Schedule\Inbox implements DAV\IProperties
{
    /**
     * Updates properties on this node.
     *
     * @param PropPatch $propPatch
     * @return void
     * @see \Sabre\DAV\IProperties::propPatch()
     */
    function propPatch(DAV\PropPatch $propPatch)
    {
        // not implemented
        throw new DAV\Exception\Forbidden("The Scheduling inbox doesn't allow property changes'");
    }

    /**
     * Returns a list of properties for this nodes.
     *
     * The properties list is a list of propertynames the client requested,
     * encoded in clark-notation {xmlnamespace}tagname
     *
     * @param array $properties
     * @return array
     */
    function getProperties($properties)
    {
        $response = [];
        foreach ($properties as $propname) {
            // getctag is the only property we support
            if ($propname == '{http://calendarserver.org/ns/}getctag') {
                $response[$propname] = $this->caldavBackend->getSchedulingInboxCtag($this->principalUri);
            }
        }

        return $response;
    }
}
