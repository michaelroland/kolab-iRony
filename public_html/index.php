<?php

/**
 * iRony, the Kolab WebDAV/CalDAV/CardDAV Server
 *
 * This is the public API to provide *DAV-based access to the Kolab Groupware backend
 *
 * @version 0.4-dev
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2013-2014, Kolab Systems AG <contact@kolabsys.com>
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

// define some environment variables used throughout the app and libraries
define('KOLAB_DAV_ROOT', realpath('../'));
define('KOLAB_DAV_VERSION', '0.4.3');
define('KOLAB_DAV_START', microtime(true));

define('RCUBE_INSTALL_PATH', KOLAB_DAV_ROOT . '/');
define('RCUBE_CONFIG_DIR',   KOLAB_DAV_ROOT . '/config/');
define('RCUBE_PLUGINS_DIR',  KOLAB_DAV_ROOT . '/lib/plugins/');

// suppress error notices
ini_set('error_reporting', E_ALL &~ E_NOTICE &~ E_STRICT);


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

// use composer's autoloader for dependencies
$loader = require_once(KOLAB_DAV_ROOT . '/vendor/autoload.php');
$loader->set('Kolab', array(KOLAB_DAV_ROOT . '/lib'));  // register iRony namespace(s)
$loader->setUseIncludePath(true);  // enable include_path to load PEAR classes from their default location

// load the Roundcube framework with its autoloader
require_once KOLAB_DAV_ROOT . '/lib/Roundcube/bootstrap.php';

// Roundcube framework initialization
$rcube = rcube::get_instance(rcube::INIT_WITH_DB | rcube::INIT_WITH_PLUGINS);
$rcube->config->load_from_file(RCUBE_CONFIG_DIR . 'dav.inc.php');

// Load plugins
$plugins  = (array)$rcube->config->get('kolabdav_plugins', array('kolab_auth'));
$required = array('libkolab', 'libcalendaring');

$rcube->plugins->init($rcube);
$rcube->plugins->load_plugins($plugins, $required);

// enable logger
if ($rcube->config->get('kolabdav_console') || $rcube->config->get('per_user_logging')) {
    $logger = new \Kolab\Utils\DAVLogger((\Kolab\Utils\DAVLogger::CONSOLE | $rcube->config->get('kolabdav_http_log', 0)));
}

// convenience function, you know it well :-)
function console()
{
    global $logger;

    if ($logger) {
        call_user_func_array(array($logger, 'console'), func_get_args());
    }
}


// Make sure this setting is turned on and reflects the root url of the *DAV server.
$base_uri = $rcube->config->get('base_uri', slashify(substr(dirname($_SERVER['SCRIPT_FILENAME']), strlen($_SERVER['DOCUMENT_ROOT']))));

// add filename to base URI when called without mod_rewrite (e.g. /dav/index.php/calendar)
if (strpos($_SERVER['REQUEST_URI'], 'index.php'))
    $base_uri .= 'index.php/';

// create the various backend instances
$auth_backend      = new \Kolab\DAV\Auth\HTTPBasic();
$principal_backend = new \Kolab\DAVACL\PrincipalBackend();

$services = array();
foreach (array('CALDAV','CARDDAV','WEBDAV') as $skey) {
    if (getenv($skey))
        $services[$skey] = 1;
}

// no config means *all* services
if (empty($services))
    $services = array('CALDAV' => 1, 'CARDDAV' => 1, 'WEBDAV' => 1);

// add chwala directories to include path for autoloading
if ($services['WEBDAV']) {
    $include_path  = ini_get('include_path') . PATH_SEPARATOR;
    $include_path .= KOLAB_DAV_ROOT . '/lib/FileAPI' . PATH_SEPARATOR;
    $include_path .= KOLAB_DAV_ROOT . '/lib/FileAPI/kolab' . PATH_SEPARATOR;
    $include_path .= KOLAB_DAV_ROOT . '/lib/FileAPI/ext';
    set_include_path($include_path);
}

// Build the directory tree
// This is an array which contains the 'top-level' directories in the WebDAV server.
if ($services['CALDAV'] || $services['CARDDAV']) {
    $nodes = array(
        new \Kolab\CalDAV\Principal\Collection($principal_backend),
    );

    if ($services['CALDAV']) {
        $caldav_backend = new \Kolab\CalDAV\CalendarBackend();
        $caldav_backend->setUserAgent($_SERVER['HTTP_USER_AGENT']);
        $nodes[] = new \Kolab\CalDAV\CalendarRootNode($principal_backend, $caldav_backend);
    }
    if ($services['CARDDAV']) {
        $carddav_backend = new \Kolab\CardDAV\ContactsBackend();
        $carddav_backend->setUserAgent($_SERVER['HTTP_USER_AGENT']);
        $nodes[] = new \Kolab\CardDAV\AddressBookRoot($principal_backend, $carddav_backend);
    }
    if ($services['WEBDAV']) {
        $nodes[] = new \Kolab\DAV\Collection(\Kolab\DAV\Collection::ROOT_DIRECTORY);
    }
}
// register WebDAV service as root
else if ($services['WEBDAV']) {
    $nodes = new \Kolab\DAV\Collection('');
}

// the object tree needs in turn to be passed to the server class
$server = new \Sabre\DAV\Server($nodes);
$server->setBaseUri($base_uri);

// connect logger
if (isset($logger) && is_object($logger)) {
    $server->addPlugin($logger);
}

// register some plugins
$server->addPlugin(new \Sabre\DAV\Auth\Plugin($auth_backend, 'KolabDAV'));
// Unauthenticated access doesn't work for us since we require credentials to get access to the data in the first place.
$aclPlugin = new \Sabre\DAVACL\Plugin();
$aclPlugin->allowUnauthenticatedAccess = false;
$server->addPlugin($aclPlugin);

if ($services['CALDAV']) {
    $server->addPlugin(new \Kolab\CalDAV\Plugin());
    $server->addPlugin(new \Kolab\CalDAV\SchedulePlugin());
    $server->addPlugin(new \Kolab\CalDAV\IMipPlugin(''));
}

if ($services['CARDDAV']) {
    $server->addPlugin(new \Kolab\CardDAV\Plugin());
}

if ($services['WEBDAV']) {
    // the lock manager is responsible for making sure users don't overwrite each others changes.
    $locks_backend = new \Kolab\DAV\Locks\Chwala(\Kolab\DAV\Collection::ROOT_DIRECTORY);
    $server->addPlugin(new \Sabre\DAV\Locks\Plugin($locks_backend));

    // intercept some of the garbage files operation systems tend to generate when mounting a WebDAV share
    $server->addPlugin(new \Kolab\DAV\TempFilesPlugin(KOLAB_DAV_ROOT . '/temp'));
}

// HTML UI for browser-based access (recommended only for development)
if (getenv('DAVBROWSER')) {
    $server->addPlugin(new \Sabre\DAV\Browser\Plugin());
}

// log exceptions in iRony error log
$server->on('exception', function($e){
    if (!($e instanceof \Sabre\DAV\Exception) || $e->getHTTPCode() == 500) {
        rcube::raise_error(array(
            'code' => 500,
            'type' => 'php',
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'message' => $e->getMessage() . " (error 500)\n" . $e->getTraceAsString(),
        ), true, false);
    }
});

// finally, process the request
$server->exec();

// trigger log
$server->emit('exit', array());
