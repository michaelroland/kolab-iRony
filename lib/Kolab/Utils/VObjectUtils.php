<?php

/**
 * Utility class providing functions for VObject data encoding
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

namespace Kolab\Utils;

use Sabre\VObject\Property;

/**
 * Helper class proviting utility functions for VObject data encoding
 */
class VObjectUtils
{
    /**
     * Convert an object URI into a valid UID value
     */
    public static function uri2uid($uri, $suffix = '')
    {
        $base = basename($uri, $suffix);
        $uid = strtr($base, array('%2F' => '/'));

        // assume full URL encoding
        if (preg_match('/%[A-F0-9]{2}/', $uid)) {
            return urldecode($base);
        }

        return $uid;
    }

    /**
     * Encode an object UID into a valid URI
     */
    public static function uid2uri($uid, $suffix = '')
    {
        $encode = strpos($uid, '/') !== false;
        return ($encode ? urlencode($uid) : $uid) . $suffix;
    }

    /**
     * Create a Sabre\VObject\Property instance from a PHP DateTime object
     *
     * @param string Property name
     * @param object DateTime
     */
    public static function datetime_prop($root, $name, $dt, $utc = false)
    {
        if ($utc) {
            $dt->setTimeZone(new \DateTimeZone('UTC'));
        }

        $vdt = $root->createProperty($name, null, null, $dt->_dateonly ? 'DATE' : 'DATE-TIME');
        $value = $dt;

        if ($dt->_dateonly) {
            // $vdt['VALUE'] = 'DATE';
            // set date value as string as a temporary fix for
            // https://github.com/fruux/sabre-vobject/issues/217
            $value = $dt->format('Y-m-d');
        }

        $vdt->setVAlue($value);

        return $vdt;
    }

    /**
     * Copy values from one hash array to another using a key-map
     */
    public static function map_keys($values, $map)
    {
        $out = array();
        foreach ($map as $from => $to) {
            if (isset($values[$from]))
                $out[$to] = $values[$from];
        }
        return $out;
    }

}