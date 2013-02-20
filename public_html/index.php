<?php

/**
 * iRony, the Kolab WebDAV/CalDAV/CardDAV Server
 *
 * This is the public API to provide *DAV-based access to the Kolab Groupware backend
 *
 * @version 0.1.0
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

// define som environment variables used thoughout the app and libraries
define('KOLAB_DAV_ROOT', realpath('../'));

define('RCUBE_INSTALL_PATH', KOLAB_DAV_ROOT . '/');
define('RCUBE_CONFIG_DIR',   KOLAB_DAV_ROOT . '/config/');
define('RCUBE_PLUGINS_DIR',  KOLAB_DAV_ROOT . '/lib/plugins/');

// suppress error notices
ini_set('error_reporting', E_ALL &~ E_NOTICE &~ E_STRICT);

// UTC is easy to work with, and usually recommended for any application.
date_default_timezone_set('UTC');


/**
 * Mapping PHP errors to exceptions.
 *
 * While this is not strictly needed, it makes a lot of sense to do so. If an
 * E_NOTICE or anything appears in your code, this allows SabreDAV to intercept
 * the issue and send a proper response back to the client (HTTP/1.1 500).
 */
function exception_error_handler($errno, $errstr, $errfile, $errline ) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
//set_error_handler("exception_error_handler");

// use composer's autoloader for both dependencies and local lib
require_once KOLAB_DAV_ROOT . '/vendor/autoload.php';

// load the Roundcube framework
require_once KOLAB_DAV_ROOT . '/lib/Roundcube/bootstrap.php';

// Roundcube framework initialization
$rcube = rcube::get_instance(rcube::INIT_WITH_DB | rcube::INIT_WITH_PLUGINS);
$rcube->plugins->init($rcube);
$rcube->plugins->load_plugins(array('libkolab'));

// convenience function, you know it well :-)
function console() { call_user_func_array(array('rcube', 'console'), func_get_args()); }


// quick & dirty request debugging
if ($debug = $rcube->config->get('kolab_dav_debug')) {
    $http_headers = $_SERVER['SERVER_PROTOCOL'] . ' ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n";
    foreach (apache_request_headers() as $hdr => $value) {
        $http_headers .= "$hdr: $value\n";
    }
    $rcube->write_log('davdebug', $http_headers . "\n" . $HTTP_RAW_POST_DATA);
    ob_start();  // turn on output buffering
}


// Make sure this setting is turned on and reflects the root url of the *DAV server.
$base_uri = slashify(substr(dirname($_SERVER['SCRIPT_FILENAME']), strlen($_SERVER['DOCUMENT_ROOT'])));


// create the various backend instances
$auth_backend      = new \Kolab\DAV\Auth\HTTPBasic();
$principal_backend = new \Kolab\DAVACL\PrincipalBackend();
//$carddav_backend   = new \Kolab\CardDAV\ContactsBackend();
$caldav_backend    = new \Kolab\CalDAV\CalendarBackend();


// Build the directory tree
// This is an array which contains the 'top-level' directories in the WebDAV server.
$nodes = array(
    // /principals
    new \Sabre\CalDAV\Principal\Collection($principal_backend),
    // /calendars
    new \Kolab\CalDAV\CalendarRootNode($principal_backend, $caldav_backend),
    // /addressbook
    // new \Sabre\CardDAV\AddressBookRoot($principalBackend, $carddavBackend),
);

// the object tree needs in turn to be passed to the server class
$server = new \Sabre\DAV\Server($nodes);
$server->setBaseUri($base_uri);

// register some plugins
$server->addPlugin(new \Sabre\DAV\Auth\Plugin($auth_backend, 'KolabDAV'));
//$server->addPlugin(new \Sabre\DAVACL\Plugin());  // we'll add that later
$server->addPlugin(new \Sabre\CalDAV\Plugin());
//$server->addPlugin(new \Sabre\CardDAV\Plugin());
$server->addPlugin(new \Sabre\DAV\Browser\Plugin());

// finally, process the request
$server->exec();


// catch server response in debug log
if ($debug) {
    $rcube->write_log('davdebug', "RESPONSE:\n" . ob_get_contents());
    ob_end_flush();
}

