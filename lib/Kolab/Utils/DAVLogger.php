<?php

/**
 * Utility class logging DAV requests
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
use Sabre\DAV;
use Kolab\DAV\Auth\HTTPBasic;


/**
 * Utility class to log debug information about processed DAV requests
 */
class DAVLogger extends DAV\ServerPlugin
{
    const CONSOLE       = 1;
    const HTTP_REQUEST  = 2;
    const HTTP_RESPONSE = 4;

    private $rcube;
    private $server;
    private $method;
    private $loglevel;


    /**
     * Default constructor
     */
    public function __construct($level = 1)
    {
        $this->rcube    = rcube::get_instance();
        $this->loglevel = $level;
    }

    /**
     * This initializes the plugin.
     * This method should set up the required event subscriptions.
     *
     * @param Server $server
     */
    public function initialize(DAV\Server $server)
    {
        $this->server = $server;

        $server->on('beforeMethod', array($this, '_beforeMethod'), 15);
        $server->on('exception', array($this, '_exception'));
        $server->on('exit', array($this, '_exit'));

        // replace $server->httpResponse with a derived class that can do logging
        $server->httpResponse = new HTTPResponse();
   }

    /**
     * Handler for 'beforeMethod' events
     */
    public function _beforeMethod($request, $response)
    {
        $this->method = $request->getMethod();

        // turn on per-user http logging if the destination file exists
        if ($this->loglevel < 2 && $this->rcube->config->get('per_user_logging', false)
            && ($log_dir = $this->user_log_dir()) && file_exists($log_dir . '/httpraw')
        ) {
            $this->loglevel |= (self::HTTP_REQUEST | self::HTTP_RESPONSE);
        }

        // log full HTTP request data
        if ($this->loglevel & self::HTTP_REQUEST) {
            $content_type = $request->getHeader('Content-Type');
            if (strpos($content_type, 'text/') === 0 || strpos($content_type, 'application/xml') === 0) {
                $http_body = $request->getBodyAsString();

                // Hack for reading php:://input because that stream can only be read once.
                // This is why we re-populate the request body with the existing data.
                $request->setBody($http_body);
            }
            else if (!empty($content_type)) {
                $http_body = '[binary data]';
            }

            // catch all headers
            $http_headers = array();
            foreach ($this->get_request_headers() as $hdr => $value) {
                if (strtolower($hdr) == 'authorization') {
                    $method = preg_match('/^((basic|digest)\s+)/i', $value, $m) ? $m[1] : '';
                    $value = $method . str_repeat('*', strlen($value) - strlen($method));
                }
                $http_headers[$hdr] = "$hdr: $value";
            }

            rcube::write_log('httpraw', $request->getMethod() . ' ' . $request->getUrl() . ' ' . $_SERVER['SERVER_PROTOCOL'] . "\n" .
               join("\n", $http_headers) . "\n\n" . $http_body);
        }

        // log to console
        if ($this->loglevel & self::CONSOLE) {
            rcube::write_log('console', $this->method . ' ' . $request->getUrl());
        }
    }

    /*
     * Wrapper function in case apache_request_headers() is not available
     *
     * @return array
     */
    public function get_request_headers()
    {
        if (function_exists('apache_request_headers')) {
            return apache_request_headers();
        }

        $return = array();
        foreach ($_SERVER as $key => $value) {
            if (preg_match('/^HTTP_(.*)/',$key,$regs)) {
                // restore original letter case
                $key = str_replace('_',' ',$regs[1]);
                $key = ucwords(strtolower($key));
                $key = str_replace(' ','-',$key);

                // build return array
                $return[$key] = $value;
            }
        }
        return $return;
    }

    /**
     * Handler for 'exception' events
     */
    public function _exception($e)
    {
        // log to console
        $this->console(get_class($e) . ' (EXCEPTION)', $e->getMessage() /*, $e->getTraceAsString()*/);
    }

    /**
     * Handler for 'exit' events
     */
    public function _exit()
    {
        // log full HTTP reponse
        if ($this->loglevel & self::HTTP_RESPONSE) {
            rcube::write_log('httpraw', "RESPONSE: " . $this->server->httpResponse->dump());
        }

        if (($this->loglevel & self::CONSOLE) || $this->rcube->config->get('performance_stats')) {
            $time = microtime(true) - KOLAB_DAV_START;

            if (function_exists('memory_get_usage'))
                $mem = round(memory_get_usage() / 1024 / 1024, 1);
            if (function_exists('memory_get_peak_usage'))
                $mem .= '/' . round(memory_get_peak_usage() / 1024 / 1024, 1);

            // we have to disable per_user_logging to make sure stats end up in the main console log
            $this->rcube->config->set('per_user_logging', false);

            rcube::write_log('console', sprintf("%s:%s [%s] %0.4f sec",
                $this->method ?: $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $mem, $time));
        }
    }

    /**
     * Wrapper for rcube::cosole() to write per-user logs
     */
    public function console(/* ... */)
    {
        if ($this->loglevel & self::CONSOLE) {
            $msg = array();
            foreach (func_get_args() as $arg) {
                $msg[] = !is_string($arg) ? var_export($arg, true) : $arg;
            }

            rcube::write_log('console', join(";\n", $msg));
        }
    }

    /**
     * Get the per-user log directory
     */
    private function user_log_dir()
    {
        $log_dir = $this->rcube->config->get('log_dir', RCUBE_INSTALL_PATH . 'logs');
        $user_log_dir = $log_dir . '/' . HTTPBasic::$current_user;

        return HTTPBasic::$current_user && is_writable($user_log_dir) ? $user_log_dir : false;
    }
}
