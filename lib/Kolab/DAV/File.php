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

use \Exception;
use \DateTime;

/**
 * File class
 */
class File extends Node implements \Sabre\DAV\IFile, \Sabre\DAV\IProperties
{

    /**
     * Updates the data
     *
     * The data argument is a readable stream resource.
     *
     * After a succesful put operation, you may choose to return an ETag. The
     * etag must always be surrounded by double-quotes. These quotes must
     * appear in the actual string you're returning.
     *
     * Clients may use the ETag from a PUT request to later on make sure that
     * when they update the file, the contents haven't changed in the mean
     * time.
     *
     * If you don't plan to store the file byte-by-byte, and you return a
     * different object on a subsequent GET you are strongly recommended to not
     * return an ETag, and just return null.
     *
     * @param resource $data
     * @throws Sabre\DAV\Exception
     * @return string|null
     */
    public function put($data)
    {
        $filedata = $this->fileData($this->path, $data);

        try {
            $this->backend->file_update($this->path, $filedata);
        }
        catch (Exception $e) {
            $this->throw_exception($e);
        }

        try {
            $this->data = $this->backend->file_info($this->path);
        }
        catch (Exception $e) {
            $this->throw_exception($e);
        }

        return $this->getETag();
    }

    /**
     * Returns the file content
     *
     * This method may either return a string or a readable stream resource
     *
     * @throws Sabre\DAV\Exception
     * @return mixed
     */
    public function get()
    {
        try {
            $fp = fopen('php://temp', 'bw+');
            $this->backend->file_get($this->path, array(), $fp);
            rewind($fp);
        }
        catch (Exception $e) {
            $this->throw_exception($e);
        }

        return $fp;
    }

    /**
     * Delete the current file
     *
     * @throws Sabre\DAV\Exception
     * @return void
     */
    public function delete()
    {
        try {
            $this->backend->file_delete($this->path);
        }
        catch (Exception $e) {
            $this->throw_exception($e);
        }

        // reset cache
        if ($this->parent) {
            $this->parent->children = null;
        }
    }

    /**
     * Returns the size of the node, in bytes
     *
     * @return int
     */
    public function getSize()
    {
        return $this->data['size'];
    }

    /**
     * Returns the ETag for a file
     *
     * An ETag is a unique identifier representing the current version of the file. If the file changes, the ETag MUST change.
     * The ETag is an arbitrary string, but MUST be surrounded by double-quotes.
     *
     * Return null if the ETag can not effectively be determined
     *
     * @return mixed
     */
    public function getETag()
    {
        return sprintf('"%s-%d"', substr(md5($this->path . ':' . $this->data['size']), 0, 16), $this->data['modified']);
    }

    /**
     * Returns the mime-type for a file
     *
     * If null is returned, we'll assume application/octet-stream
     *
     * @return mixed
     */
    public function getContentType()
    {
        return $this->data['type'];
    }

    /**
     * Updates properties on this node.
     *
     * This method received a PropPatch object, which contains all the
     * information about the update.
     *
     * To update specific properties, call the 'handle' method on this object.
     * Read the PropPatch documentation for more information.
     *
     * @param PropPatch $propPatch
     * @return void
     */
    function propPatch(\Sabre\DAV\PropPatch $propPatch)
    {
        // not supported
        return false;
    }

    /**
     * Returns a list of properties for this node.
     *
     * The properties list is a list of propertynames the client requested,
     * encoded in clark-notation {xmlnamespace}tagname
     *
     * If the array is empty, it means 'all properties' were requested.
     *
     * Note that it's fine to liberally give properties back, instead of
     * conforming to the list of requested properties.
     * The Server class will filter out the extra.
     *
     * @param array $properties
     * @return void
     */
    function getProperties($properties)
    {
        $result = array();

        if ($this->data['created']) {
            $result['{DAV:}creationdate'] = \Sabre\HTTP\toDate(new DateTime('@'.$this->data['created']));
        }

        return $result;
    }

}
