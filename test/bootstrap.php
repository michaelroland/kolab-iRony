<?php

/**
 * iRony, the Kolab WebDAV/CalDAV/CardDAV Server
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2013-2016, Kolab Systems AG <contact@kolabsys.com>
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

if (php_sapi_name() != 'cli') {
    die("Not in shell mode (php-cli)");
}

// define some environment variables used throughout the app and libraries
define('TESTS_DIR', __DIR__ . '/');
define('KOLAB_DAV_ROOT', realpath('../'));
define('KOLAB_DAV_VERSION', '0.4-dev');
define('KOLAB_DAV_START', microtime(true));

define('RCUBE_INSTALL_PATH', KOLAB_DAV_ROOT . '/');
define('RCUBE_CONFIG_DIR',   KOLAB_DAV_ROOT . '/config/');
define('RCUBE_PLUGINS_DIR',  KOLAB_DAV_ROOT . '/lib/plugins/');

// suppress error notices
ini_set('error_reporting', E_ALL &~ E_NOTICE &~ E_STRICT);

// use composer's autoloader for dependencies
$loader = require_once(KOLAB_DAV_ROOT . '/vendor/autoload.php');
$loader->set('Kolab', array(KOLAB_DAV_ROOT . '/lib'));  // register iRony namespace(s)
$loader->setUseIncludePath(true);  // enable include_path to load PEAR classes from their default location

// load the Roundcube framework with its autoloader
require_once KOLAB_DAV_ROOT . '/lib/Roundcube/bootstrap.php';
