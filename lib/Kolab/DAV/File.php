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
use \Exception;

/**
 * File class
 */
class File extends Node implements \Sabre\DAV\IFile
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
     * @return string|null
     */
    public function put($data)
    {
        $filedata = $this->fileData($this->path, $data);

        try {
            $this->backend->file_update($this->path, $filedata);
        }
        catch (Exception $e) {
//            throw new \Sabre\DAV\Exception\Forbidden($e->getMessage());
        }

        try {
            $this->data = $this->backend->file_info($this->path);
        }
        catch (Exception $e) {
        }

        return $this->getETag();
    }

    /**
     * Returns the file content
     *
     * This method may either return a string or a readable stream resource
     *
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
//            throw new \Sabre\DAV\Exception\Forbidden($e->getMessage());
        }

        return $fp;
    }

    /**
     * Delete the current file
     *
     * @return void
     */
    public function delete()
    {
        try {
            $this->backend->file_delete($this->path);
        }
        catch (Exception $e) {
//            throw new \Sabre\DAV\Exception\Forbidden($e->getMessage());
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
}
