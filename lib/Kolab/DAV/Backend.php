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

use Kolab\DAV\Auth\HTTPBasic;

class Backend extends \file_api_lib
{
    protected $configured = false;
    protected static $instance;

    /**
     * This implements the 'singleton' design pattern
     *
     * @return Backend The one and only instance
     */
    static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new Backend();
        }

        return self::$instance;
    }

    /**
     * Private constructor
     */
    protected function __construct()
    {
    }

    /**
     * Configure main (authentication) driver
     */
    protected function init()
    {
        // We currently support only one auth driver which is Kolab driver.
        // Because of that we don't need to authenticate in Kolab again,
        // we need only to configure it to use current username/password.
        // This is required if we want to use external storage drivers.

        if (!$this->configured && !empty(HTTPBasic::$current_user)) {
            // configure authentication driver
            $config = array(
                'username' => HTTPBasic::$current_user,
                'password' => HTTPBasic::$current_pass,
            );

            $backend = $this->get_backend();
            $backend->configure($config, '');

            $this->configured = true;
        }
    }
}
