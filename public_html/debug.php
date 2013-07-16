<?php

/**
 * Kolab WebDAV/CalDAV/CardDAV Debug Pipe
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

// define some environment variables used throughout the app and libraries
define('KOLAB_DAV_ROOT', realpath('../'));
define('KOLAB_DAV_START', microtime(true));

define('RCUBE_INSTALL_PATH', KOLAB_DAV_ROOT . '/');
define('RCUBE_CONFIG_DIR',   KOLAB_DAV_ROOT . '/config/');

ini_set('error_reporting', E_ALL &~ E_NOTICE &~ E_STRICT);

require_once KOLAB_DAV_ROOT . '/vendor/autoload.php';
require_once KOLAB_DAV_ROOT . '/lib/Roundcube/bootstrap.php';

// Roundcube framework initialization
$rcube = rcube::get_instance();
$rcube->config->load_from_file(RCUBE_CONFIG_DIR . 'dav.inc.php');

// quick & dirty request debugging
$http_headers = array();
foreach (apache_request_headers() as $hdr => $value) {
    $http_headers[] = "$hdr: $value";
}
// read HTTP request body
$in = fopen('php://input', 'r');
$http_body = stream_get_contents($in);
fclose($in);

$rcube->write_log('davdebug', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . $_SERVER['SERVER_PROTOCOL'] . "\n" .
    join("\n", $http_headers) . "\n\n" . $http_body);


// forward the full request to index.php
$base_uri = $rcube->config->get('base_uri', slashify(substr(dirname($_SERVER['SCRIPT_FILENAME']), strlen($_SERVER['DOCUMENT_ROOT']))));
$rel_url = substr($_SERVER['REQUEST_URI'], strlen($base_uri));
$host = $_SERVER['HTTP_HOST'];
$port = 80;
$path = $base_uri . 'index.php/' . $rel_url;

// remove Host: header
array_shift($http_headers);
$response_headers = array();

// re-send using curl
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,            "http://$host:$port$path");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  $_SERVER['REQUEST_METHOD']);
curl_setopt($ch, CURLOPT_POSTFIELDS,     $http_body);
curl_setopt($ch, CURLOPT_HTTPHEADER,     $http_headers);
curl_setopt($ch, CURLOPT_HEADER,         0);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$response_headers){
    list($key, $val) = explode(": ", rtrim($header), 2);
    if (!empty($val)) $response_headers[$key] = $val;
    return strlen($header);
});

$result = str_replace('index.php/', '', curl_exec($ch));
$info = curl_getinfo($ch);

// send recieved HTTP status code
$result_headers = $_SERVER['SERVER_PROTOCOL'] . " " . $info['http_code'] . " " . HTTP_Request2_Response::getDefaultReasonPhrase($info['http_code']);
header($result_headers, true);

// forward response headers
unset($response_headers['Transfer-Encoding']);
foreach ($response_headers as $hdr => $value) {
    $result_headers .= "\n$hdr: " . $value;
    header("$hdr: " . $value, true);
}

// log response
$rcube->write_log('davdebug', "RESPONSE:\n" . $result_headers . "\n\n" . (strpos($info['content_type'], 'image/') === false ? $result : ''));

// send response body back to client
echo $result;

