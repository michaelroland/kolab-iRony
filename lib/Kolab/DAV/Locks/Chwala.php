<?php

/**
 * Chwala-based lock manager for the Kolab WebDAV service.
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
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

namespace Kolab\DAV\Locks;

use \file_storage;
use \Kolab\DAV\Backend;
use Sabre\DAV\Server;
use Sabre\DAV\Locks\LockInfo;
use Sabre\DAV\Locks\Backend\AbstractBackend;
use \Exception;

/**
 * The Lock manager that maintains a lock file per user in the local file system.
 */
class Chwala extends AbstractBackend
{
    /**
     * The base Path
     *
     * @var string
     */
    protected $basePath;

    /**
     * The file API backend class
     *
     * @var file_api_storage
     */
    protected $backend;


    /**
     * Constructor
     *
     * @param string $path Base path
     */
    public function __construct($path = null)
    {
        $this->backend  = Backend::get_instance()->get_backend();
        $this->basePath = $path;
    }

    /**
     * Returns a list of Sabre\DAV\Locks\LockInfo objects
     *
     * This method should return all the locks for a particular uri, including
     * locks that might be set on a parent uri.
     *
     * If returnChildLocks is set to true, this method should also look for
     * any locks in the subtree of the uri for locks.
     *
     * @param string $uri
     * @param bool $returnChildLocks
     * @return array
     */
    public function getLocks($uri, $returnChildLocks)
    {
        console(__METHOD__, $uri, $returnChildLocks);

        $path = $this->uri2path($uri);

        if (!strlen($path)) {
            return array();
        }

        // @TODO: when using Dolphin I've found that this method
        // is called for every file in a folder, this might become
        // a performance issue.

        $list = $this->backend->lock_list($path, $returnChildLocks);
        $list = array_map(array($this, 'to_lockinfo'), (array) $list);

        return $list;
    }

    /**
     * Locks a uri
     *
     * @param string $uri
     * @param LockInfo $lockInfo
     * @return bool
     */
    public function lock($uri, LockInfo $lockInfo)
    {
        console(__METHOD__, $uri, $lockInfo);

        try {
            $this->backend->lock($this->uri2path($uri), $this->from_lockinfo($lockInfo));
        }
        catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Removes a lock from a uri
     *
     * @param string $uri
     * @param LockInfo $lockInfo
     * @return bool
     */
    public function unlock($uri, LockInfo $lockInfo)
    {
        console(__METHOD__, $uri, $lockInfo);

        try {
            $this->backend->unlock($this->uri2path($uri), $this->from_lockinfo($lockInfo));
        }
        catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Converts URI according to root directory
     */
    protected function uri2path($uri)
    {
        if ($this->basePath) {
            if (strpos($uri, $this->basePath . '/') === 0) {
                $uri = substr($uri, strlen($this->basePath) + 1);
            }
        }

        return $uri;
    }

    /**
     * Converts URI according to root directory
     */
    protected function path2uri($path)
    {
        if ($this->basePath) {
            $path = $this->basePath . '/' . $path;
        }

        return $path;
    }

    /**
     * Convert LockInfo object into Chwala's lock data array
     */
    public function from_lockinfo(LockInfo $lockInfo)
    {
        $lock = (array) $lockInfo;

        // map to Chwala scope/depth values
        $lock['scope'] = $lock['scope'] == LockInfo::SHARED ? file_storage::LOCK_SHARED : file_storage::LOCK_EXCLUSIVE;
        $lock['depth'] = $lock['depth'] == Server::DEPTH_INFINITY ? file_storage::LOCK_INFINITE : $record['depth'];

        return $lock;
    }

    /**
     * Convert Chwala's lock data array into SabreDav LockInfo object
     */
    public function to_lockinfo(array $lock)
    {
        $lockInfo = new LockInfo;

        $lockInfo->uri     = $this->path2uri($lock['uri']);
        $lockInfo->owner   = $lock['owner'];
        $lockInfo->scope   = $lock['scope'] == file_storage::LOCK_SHARED ? LockInfo::SHARED : LockInfo::EXCLUSIVE;
        $lockInfo->depth   = $lock['depth'] == file_storage::LOCK_INFINITE ? Server::DEPTH_INFINITY : $lock['depth'];
        $lockInfo->token   = $lock['token'];
        $lockInfo->timeout = $lock['timeout'];
        $lockInfo->created = $lock['created'];

        return $lockInfo;
    }
}
