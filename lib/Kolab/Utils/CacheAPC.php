<?php

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


