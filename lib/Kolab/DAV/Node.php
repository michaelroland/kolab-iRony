<?php

/**
 * SabreDAV File Backend implementation for Kolab.
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

namespace Kolab\DAV;

use \rcube;
use \rcube_mime;
use \Exception;

/**
 * Node class
 */
class Node implements \Sabre\DAV\INode
{
    /**
     * The path to the current node
     *
     * @var string
     */
    protected $path;

    /**
     * The file API backend class
     *
     * @var file_api_storage
     */
    protected $backend;

    /**
     * Internal node data (e.g. file parameters)
     *
     * @var array
     */
    protected $data;

    /**
     * Parent node
     *
     * @var Kolab\DAV\Node
     */
    protected $parent;


    /**
     * @brief Sets up the node, expects a full path name
     * @param string         $path   Node name with path
     * @param Kolab\DAV\Node $parent Parent node
     * @param array          $data   Node data
     *
     * @return void
     */
    public function __construct($path, $parent = null, $data = array())
    {
        $this->data    = $data;
        $this->path    = $path;
        $this->parent  = $parent;
        $this->backend = Backend::get_instance()->get_backend();

        if ($this->path == Collection::ROOT_DIRECTORY) {
            $this->path = '';
        }
        else if (strpos($this->path, Collection::ROOT_DIRECTORY . '/') === 0) {
            $this->path = substr($this->path, strlen(Collection::ROOT_DIRECTORY . '/'));
        }
    }

    /**
     * Returns the last modification time
     *
     * In this case, it will simply return the current time
     *
     * @return int
     */
    public function getLastModified()
    {
        return $this->data['modified'] ? $this->data['modified'] : null;
    }

    /**
     * Deletes the current node (folder)
     *
     * @throws Sabre\DAV\Exception\Forbidden
     * @return void
     */
    public function delete()
    {
        try {
            $this->storage->folder_delete($this->path);
        }
        catch (Exception $e) {
            throw new \Sabre\DAV\Exception\Forbidden($e->getMessage());
        }

        // reset cache
        if ($this->parent) {
            $this->parent->children = null;
        }
    }

    /**
     * Renames the node
     *
     * @throws Sabre\DAV\Exception\Forbidden
     * @param string $name The new name
     * @return void
     */
    public function setName($name)
    {
        $path = explode('/', $this->path);
        array_pop($path);
        $newname = implode('/', $path) . '/' . $name;

        $method = (is_a($this, 'File') ? 'file' : 'folder') . '_move';

        try {
            $this->backend->$method($this->path, $newname);
        }
        catch (Exception $e) {
            throw new \Sabre\DAV\Exception\Forbidden($e->getMessage());
        }

        // reset cache
        if ($this->parent) {
            $this->parent->children = null;
        }
    }

    /**
     * @brief Returns the name of the node
     * @return string
     */
    public function getName()
    {
        if ($this->path === '') {
            return Collection::ROOT_DIRECTORY;
        }

        return array_pop(explode('/', $this->path));
    }

    /**
     * Build file data array to pass into backend
     */
    protected function fileData($name, $data = null)
    {
        $rcube     = rcube::get_instance();
        $temp_dir  = unslashify($rcube->config->get('temp_dir'));
        $file_path = tempnam($temp_dir, 'davFile');

        // @TODO: support $data as a resource in the backend
        // @TODO: support $data as string in the backend
        file_put_contents($file_path, $data);

        $filedata = array(
            'path' => $file_path,
            'size' => filesize($file_path),
            'type' => rcube_mime::file_content_type($file_path, $name),
        );

        return $filedata;
    }
}
