<?php

/**
 * Utility class prividing a simple API to PHP's APC cache
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

/**
 * Utility class that provides a simple API to local APC cache
 */
class CacheAPC
{
    private static $instances = array();

    private $prefix = 'kolabdav:';
    private $ttl = 600; // Default Time To Live
    private $enabled = false; // APC enabled?
    private $local = array();  // local in-memory cache

    /**
     * Singleton getter
     *
     * @param string Cache domain used to prefix cache entries
     * @return object CacheAPC instance for the given domain
     */
    public static function get_instance($domain = '')
    {
        if (!self::$instances[$domain]) {
            self::$instances[$domain] = new CacheAPC($domain);
        }

        return self::$instances[$domain];
    }

    /**
     * Private constructor
     */
    private function CacheAPC($domain)
    {
        if (!empty($domain))
            $this->prefix = $domain . ':';

        $this->enabled = extension_loaded('apc');
    }

    /**
     * Get data from cache
     */
    function get($key)
    {
        if (isset($this->local[$key])) {
            return $this->local[$key];
        }

        if ($this->enabled) {
            $success = false;
            $data = apc_fetch($this->prefix . $key, $success);
            return $success ? $data : null;
        }
    }

    /**
     * Save data to cache
     */
    function set($key, $data, $ttl = 0)
    {
        $this->local[$key] = $data;
        if ($this->enabled) {
            return apc_store($this->prefix . $key, $data, $ttl ?: $this->ttl);
        }

        return true;
    }

    /**
     *  Deelete a cache entry
     */
    function del($key)
    {
        unset($this->local[$key]);

        if ($this->enabled) {
            apc_delete($this->prefix . $key);
        }
    }

}


