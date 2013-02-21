<?php

/**
 * SabreDAV Auth Backend implementation for Kolab.
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

namespace Kolab\DAV\Auth;

use \rcube;
use \rcube_user;
use \rcube_utils;
use Kolab\Utils\CacheAPC;

/**
 *
 */
class HTTPBasic extends \Sabre\DAV\Auth\Backend\AbstractBasic
{
    // Make the current user name availabel to all classes
    public static $current_user;

    /**
     * Validates a username and password
     *
     * This method should return true or false depending on if login
     * succeeded.
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    protected function validateUserPass($username, $password)
    {
        $rcube = rcube::get_instance();
        $cache = CacheAPC::get_instance('kolabdav:auth');

        // Here we need IDNA ASCII
        $host = rcube_utils::idn_to_ascii($rcube->config->get('default_host', 'localhost'));
        $user = rcube_utils::idn_to_ascii($username);
        $port = $rcube->config->get('default_port', 143);

        $_host = parse_url($host);
        if ($_host['host']) {
            $host = $_host['host'];
            $ssl = (isset($_host['scheme']) && in_array($_host['scheme'], array('ssl','imaps','tls'))) ? $_host['scheme'] : null;
            if (!empty($_host['port']))
                $port = $_host['port'];
            else if ($ssl && $ssl != 'tls' && (!$port || $port == 143))
                $port = 993;
        }

        // check if we already canonified this username
        if ($auth_user = $cache->get($user)) {
            $user = $auth_user;
        }
        else {  // load kolab_auth plugin to resolve the canonical username
            $rcube->plugins->load_plugin('kolab_auth');
        }

        // let plugins do their work
        $auth = $rcube->plugins->exec_hook('authenticate', array(
            'host' => $host,
            'user' => $user,
            'pass' => $password,
        ));

        // authenticate user against the IMAP server
        $imap = $rcube->get_storage();
        $success = $imap->connect($auth['host'], $auth['user'], $auth['pass'], $port, $ssl);

        if ($success) {
            self::$current_user = $auth['user'];
            if (!$auth_user) {
                $cache->set($user, $auth['user']);
            }

            // register a rcube_user object for global access
            $rcube->user = new rcube_user(null, array('username' => $auth['user'], 'mail_host' => $auth['host']));
        }

        return $success;
    }
}
