<?php

/**
 * Extended CalDAV Schedule plugin for the Kolab DAV server
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2014, Kolab Systems AG <contact@kolabsys.com>
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

namespace Kolab\CalDAV;

use Sabre\DAV;
use Sabre\CalDAV;
use Sabre\VObject;
use Sabre\HTTP;

/**
 * Extended CalDAV Schedule plugin
 */
class SchedulePlugin extends CalDAV\Schedule\Plugin
{
    /**
     * Returns free-busy information for a specific address. The returned
     * data is an array containing the following properties:
     *
     * calendar-data : A VFREEBUSY VObject
     * request-status : an iTip status code.
     * href: The principal's email address, as requested
     *
     * @param string $email address
     * @param \DateTime $start
     * @param \DateTime $end
     * @param VObject\Component $request
     * @return array
     */
    protected function getFreeBusyForEmail($email, \DateTime $start, \DateTime $end, VObject\Component $request)
    {
        console(__METHOD__, $email, $start, $end);

        $email = preg_replace('!^mailto:!i', '', $email);

        // pass-through the pre-generatd free/busy feed from Kolab's free/busy service
        if ($fburl = \kolab_storage::get_freebusy_url($email)) {
            try {
                $rcube = \rcube::get_instance();
                $client = new HTTP\Client();
                $client->addCurlSetting(CURLOPT_SSL_VERIFYPEER, $rcube->config->get('kolab_ssl_verify_peer', true));

                // authentication required
                $client->on('error:401', function($request, $response, &$retry, $retryCount) {
                    if ($retryCount <= 1) {
                        // We're only going to retry exactly once.
                        $request->setHeader('Authorization', 'Basic ' . base64_encode(HTTPBasic::$current_user . ':' . HTTPBasic::$current_pass));
                        $retry = true;
                    }
                });

                $response = $client->send(new HTTP\Request('GET', $fburl));

                // success!
                if ($response->getStatus() == 200) {
                    $vcalendar = VObject\Reader::read($response->getBodyAsString(), VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);
                    return array(
                        'calendar-data' => $vcalendar,
                        'request-status' => '2.0;Success',
                        'href' => 'mailto:' . $email,
                    );
                }
            }
            catch (\Exception $e) {
                // log failures
                \rcube::raise_error($e, true, false);
            }
        }
        else {
            // generate free/busy data from this user's calendars
            return parent::getFreeBusyForEmail($email, $start, $end, $request);
        }

        // return "not found"
        return array(
            'request-status' => '3.7;Could not find principal',
            'href' => 'mailto:' . $email,
        );
    }
}