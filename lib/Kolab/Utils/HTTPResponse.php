<?php

/**
 * Utility class representing a HTTP response with logging capabilities
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

/**
 * This class represents a HTTP response.
 */
class HTTPResponse extends \Sabre\HTTP\Response
{
    /**
     * Dump the response data for logging
     */
    public function dump()
    {
        $result_headers = '';
        foreach ($this->headers as $hdr => $value) {
            $result_headers .= "\n$value[0]: " . $this->getHeader($hdr);
        }

        $body = $this->body;

        // get response body as string for text/* data
        if (is_resource($this->body) && strpos($this->getHeader('content-type'), 'text/') === 0) {
            @fseek($this->body, 0);
            $body = stream_get_contents($this->body);
        }

        return $this->status . " " . $this->statusText . $result_headers . "\n\n" . $body;
    }
}