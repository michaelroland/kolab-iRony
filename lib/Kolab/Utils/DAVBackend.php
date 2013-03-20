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

use \kolab_storage;
use \rcube_utils;

/**
 *
 */
class DAVBackend
{
    const IMAP_UID_KEY = '/shared/vendor/kolab/dav-uid';
    const IMAP_UID_KEY_PRIVATE = '/private/vendor/kolab/dav-uid';

    /**
     * Getter for a kolab_storage_folder with the given UID
     *
     * @param string  Folder UID (saved in annotation)
     * @param string  Kolab folder type (for selecting candidates)
     * @return object \kolab_storage_folder instance
     */
    public static function get_storage_folder($uid, $type)
    {
        foreach (kolab_storage::get_folders($type) as $folder) {
            if (self::get_uid($folder) == $uid)
                return $folder;
        }

        return null;
    }

    /**
     * Helper method to extract folder UID metadata
     *
     * @param object \kolab_storage_folder Folder to get UID for
     * @return string Folder's UID
     */
    public static function get_uid($folder)
    {
        // color is defined in folder METADATA
        $metadata = $folder->get_metadata(array(self::IMAP_UID_KEY, self::IMAP_UID_KEY_PRIVATE));
        if (($uid = $metadata[self::IMAP_UID_KEY]) || ($uid = $metadata[self::IMAP_UID_KEY_PRIVATE])) {
            return $uid;
        }

        // generate a folder UID and set it to IMAP
        $uid = rtrim(chunk_split(md5($folder->name), 12, '-'), '-');
        self::set_uid($folder, $uid);

        return $uid;
    }

    /**
     * Helper method to set an UID value to the given IMAP folder instance
     *
     * @param object \kolab_storage_folder Folder to set UID
     * @param string Folder's UID
     * @return boolean True on succes, False on failure
     */
    public static function set_uid($folder, $uid)
    {
        if (!($success = $folder->set_metadata(array(self::IMAP_UID_KEY => $uid)))) {
            $success = $folder->set_metadata(array(self::IMAP_UID_KEY_PRIVATE => $uid));
        }

        return $success;
    }

    /**
     * Build an absolute URL with the given parameters
     */
    public static function abs_url($parts = array())
    {
        $schema = 'http';
        $default_port = 80;
        if (rcube_utils::https_check()) {
            $schema = 'https';
            $default_port = 443;
        }
        $url = $schema . '://' . $_SERVER['HTTP_HOST'];

        if ($_SERVER['SERVER_PORT'] != $default_port)
            $url .= ':' . $_SERVER['SERVER_PORT'];

        if (dirname($_SERVER['SCRIPT_NAME']))
            $url .= dirname($_SERVER['SCRIPT_NAME']);

        $url .= join('/', array_map('urlencode', $parts));

        return $url;
    }

}
