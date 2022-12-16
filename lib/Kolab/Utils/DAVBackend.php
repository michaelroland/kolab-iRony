<?php

/**
 * Utility class providing a simple API to PHP's APC cache
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

use \rcube;
use \kolab_storage;
use \rcube_utils;
use \rcube_charset;
use Sabre\DAV;

/**
 *
 */
class DAVBackend
{
    public static $caldav_type_component_map = array('event' => 'VEVENT', 'task' => 'VTODO');

    /**
     * Getter for a kolab_storage_folder with the given UID
     *
     * @param string Folder UID (saved in annotation)
     * @param string Kolab folder type (for selecting candidates)
     *
     * @return object \kolab_storage_folder instance
     */
    public static function get_storage_folder($uid, $type)
    {
        foreach (kolab_storage::get_folders($type, false) as $folder) {
            if ($folder->get_uid() == $uid) {
                self::check_storage_folder($folder);
                return $folder;
            }
        }

        throw new DAV\Exception\NotFound('The requested collection was not found');
    }

    /**
     * Check the given storage folder instance for validity and throw
     * the right exceptions according to the error state.
     */
    public static function check_storage_folder($folder)
    {
        if (empty($folder)) {
            throw new DAV\Exception\NotFound('The requested collection was not found');
        }

        if (!$folder->valid || $folder->get_error()) {
            $error = $folder->get_error();
            if ($error === kolab_storage::ERROR_IMAP_CONN) {
                throw new DAV\Exception\ServiceUnavailable('The service is temporarily unavailable (Storage failure)');
            }
            else if ($error === kolab_storage::ERROR_CACHE_DB) {
                throw new DAV\Exception\ServiceUnavailable('The service is temporarily unavailable (Cache failure)');
            }
            else if ($error === kolab_storage::ERROR_NO_PERMISSION) {
                throw new DAV\Exception\Forbidden('Access to this collection is not permitted');
            }
            else if ($error === kolab_storage::ERROR_INVALID_FOLDER) {
                throw new DAV\Exception\NotFound('The requested collection was not found');
            }

            throw new DAV\Exception('Internal Server Error');
        }
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

        if (dirname($_SERVER['SCRIPT_NAME']) != '/')
            $url .= dirname($_SERVER['SCRIPT_NAME']);

        $url .= '/' . join('/', array_map('urlencode', $parts));

        return $url;
    }

    /**
     * Callback handler for property changes on the given folder
     *
     * @param kolab_storage_folder $folder    Folder object
     * @param \Sabre\DAV\PropPatch $propPatch Property updates
     */
    public static function handle_proppatch($folder, \Sabre\DAV\PropPatch $propPatch)
    {
        $propPatch->handle(
            array('{DAV:}displayname','{http://apple.com/ns/ical/}calendar-color'),
            function($mutations) use ($folder) {
                $result = DAVBackend::folder_update($folder, $mutations);
                if (is_array($result)) {
                    $ret = array();
                    foreach ($result as $code => $props) {
                        foreach (array_keys($props) as $prop) {
                            $ret[$prop] = $code;
                        }
                    }
                }
                else {
                    $ret = $result;
                }
                return $ret;
            });

        // silently accept the other/non-supported properties
        $propPatch->setRemainingResultCode(204);
    }

    /**
     * Updates properties for a recourse (kolab folder)
     *
     * The mutations array uses the propertyName in clark-notation as key,
     * and the array value for the property value. In the case a property
     * should be deleted, the property value will be null.
     *
     * This method must be atomic. If one property cannot be changed, the
     * entire operation must fail.
     *
     * If the operation was successful, true is returned.
     * If the operation failed, detailed information about any
     * failures is returned.
     *
     * @param object $folder kolab_storage_folder instance to operate on
     * @param object $mutations Hash array with propeties to change
     *
     * @return bool|array
     */
    public static function folder_update($folder, array $mutations)
    {
        $errors = array();
        $updates = array();

        foreach ($mutations as $prop => $val) {
            switch ($prop) {
                case '{DAV:}displayname':
                    // abort if name didn't change
                    if ($val == html_entity_decode($folder->get_name(), ENT_COMPAT, RCUBE_CHARSET)) {
                        break;
                    }

                    // This is to fix potential MacOS client bug where
                    // it sets the calendar name to the folder uid
                    if ($val === $folder->get_uid()) {
                        break;
                    }

                    // restrict renaming to personal folders only
                    if ($folder->get_namespace() == 'personal') {
                        // Sanity check, displayname can't be deleted
                        if ($val === null) {
                            break;
                        }

                        $parts = preg_split('!(\s*/\s*|\s+[»:]\s+)!', $val);
                        $updates['oldname'] = $folder->name;
                        $updates['name'] = array_pop($parts);
                        $updates['parent'] = join('/', $parts);
                    }
                    else {
                        $updates['displayname'] = $val;
                    }
                    break;

                case '{http://apple.com/ns/ical/}calendar-color':
                    $newcolor = substr(trim($val, '#'), 0, 6);
                    if (strcasecmp($newcolor, $folder->get_color())) {
                        $updates['color'] = $newcolor;
                    }
                    break;

                case '{urn:ietf:params:xml:ns:caldav}calendar-description':
                default:
                    // unsupported property
                    $errors[403][$prop] = null;
            }
        }

        // execute folder update
        if (!empty($updates)) {
            // 'name' and 'parent' properties are always required
            if (empty($updates['name'])) {
                $parts = explode('/', $folder->name);
                $updates['name'] = rcube_charset::convert(array_pop($parts), 'UTF7-IMAP');
                $updates['parent'] = join('/', $parts);
                $updates['oldname'] = $folder->name;
            }

            if (!kolab_storage::folder_update($updates)) {
                rcube::raise_error(array(
                    'code' => 600, 'type' => 'php',
                    'file' => __FILE__, 'line' => __LINE__,
                    'message' => "Error updating properties for folder $folder->name:" . kolab_storage::$last_error),
                    true, false);

                throw new DAV\Exception('Internal Server Error');
            }
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Creates a new resource (i.e. IMAP folder) of a given type
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this resource in other methods.
     *
     * @param array  $properties
     * @param string $type
     * @param string $uid
     *
     * @return false|string
     */
    public static function folder_create($type, array $properties, $uid)
    {
        $props = array(
            'type' => $type,
            'name' => '',
            'subscribed' => true,
        );

        foreach ($properties as $prop => $val) {
            switch ($prop) {
                case '{DAV:}displayname':
                    $parts = explode('/', $val);
                    $props['name'] = array_pop($parts);
                    $props['parent'] = join('/', $parts);
                    break;

                case '{http://apple.com/ns/ical/}calendar-color':
                    $props['color'] = substr(trim($val, '#'), 0, 6);
                    break;

                case '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set':
                    $type_map = array_flip(self::$caldav_type_component_map);
                    $comp_types = $val->getValue();
                    $comp_type = $comp_types[0];
                    if (!empty($type_map[$comp_type]))
                        $props['type'] = $type = $type_map[$comp_type];
                    break;

                case '{urn:ietf:params:xml:ns:caldav}calendar-description':
                default:
                    // unsupported property
            }
        }

        // use UID as name if it doesn't seem to be a real UID
        // TODO: append number to default "Untitled" folder name if one already exists
        if (empty($props['name'])) {
            $props['name'] = strlen($uid) < 16 ? $uid : 'Untitled';
        }

        if (!($fname = kolab_storage::folder_update($props))) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error creating a new $type folder '$props[name]':" . kolab_storage::$last_error),
                true, false);

            throw new DAV\Exception('Internal Server Error');
        }

        // save UID in folder annotations
        if ($folder = kolab_storage::get_folder($fname)) {
            $folder->set_uid($uid);
        }

        return $uid;
    }
}
