<?php

/**
 * Extended CalDAV IMip plugin for the Kolab DAV server
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

use \rcube;
use \rcube_utils;
use \Mail_mime;

use Sabre\VObject;
use Sabre\CalDAV;
use Sabre\DAV;

/**
 * iMIP plugin.
 *
 * This class is responsible for sending out iMIP messages. iMIP is the
 * email-based transport for iTIP. iTIP deals with scheduling operations for
 * iCalendar objects.
 */
class IMipPlugin extends CalDAV\Schedule\IMipPlugin
{

    /**
     * Event handler for the 'schedule' event.
     *
     * @param ITip\Message $iTipMessage
     * @return void
     */
    function schedule(VObject\ITip\Message $iTipMessage)
    {
        console(__METHOD__, $iTipMessage->method, $iTipMessage->recipient, $iTipMessage->significantChange, $iTipMessage->scheduleStatus);

        // Not sending any emails if the system considers the update insignificant.
        if (!$iTipMessage->significantChange) {
            if (!$iTipMessage->scheduleStatus) {
                $iTipMessage->scheduleStatus = '1.0;We got the message, but it\'s not significant enough to warrant an email';
            }
            return;
        }

        $recipient = preg_replace('!^mailto:!i', '', $iTipMessage->recipient);
        $summary = strval($iTipMessage->message->VEVENT->SUMMARY);

        $rcube = rcube::get_instance();
        $sender = $rcube->user->get_identity();
        $sender_email = $sender['email'] ?: $rcube->get_user_email();
        $sender_name  = $sender['name']  ?: $rcube->get_user_name();

        $subject = 'KolabDAV iTIP message';
        switch (strtoupper($iTipMessage->method)) {
            case 'REPLY' :
                $subject = 'Re: ' . $summary;
                break;
            case 'REQUEST' :
                $subject = 'Invitation: ' .$summary;
                break;
            case 'CANCEL' :
                $subject = 'Cancelled: ' . $summary;
                break;
        }

        $sender = rcube_utils::idn_to_ascii($sender_email);
        $from = format_email_recipient($sender, $sender_name);
        $mailto = rcube_utils::idn_to_ascii($recipient);
        $to = format_email_recipient($mailto, $iTipMessage->recipientName);

        // copy some missing properties from master event to make it validate in our clients
        if (Plugin::$parsed_vevent && strval(Plugin::$parsed_vevent->UID) == strval($iTipMessage->uid)) {
            if (isset(Plugin::$parsed_vevent->DTEND)) {
                $iTipMessage->message->VEVENT->DTEND = clone Plugin::$parsed_vevent->DTEND;
            }
            if (isset(Plugin::$parsed_vevent->STATUS)) {
                $iTipMessage->message->VEVENT->STATUS = strval(Plugin::$parsed_vevent->STATUS);
            }
        }

        // compose multipart message using PEAR:Mail_Mime
        $message = new Mail_mime("\r\n");
        $message->setParam('text_encoding', 'quoted-printable');
        $message->setParam('head_encoding', 'quoted-printable');
        $message->setParam('head_charset', RCUBE_CHARSET);
        $message->setParam('text_charset', RCUBE_CHARSET . ";\r\n format=flowed");

        // compose common headers array
        $headers = array(
            'To' => $to,
            'From' => $from,
            'Date' => date('r'),
            'Reply-To' => $originator,
            'Message-ID' => $rcube->gen_message_id(),
            'X-Sender' => $sender,
            'Subject' => $subject,
        );
        if ($agent = $rcube->config->get('useragent'))
            $headers['User-Agent'] = $agent;

        $message->headers($headers);
        $message->setContentType('text/calendar', array('method' => strval($iTipMessage->method), 'charset' => RCUBE_CHARSET));
        $message->setTXTBody($iTipMessage->message->serialize());

        // send message through Roundcube's SMTP feature
        if ($rcube->deliver_message($message, $sender, $mailto, $smtp_error)) {
            $iTipMessage->scheduleStatus = '1.1;Scheduling message sent via iMip';
        }
        else {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Failed to send iTIP message to " . $mailto),
                true, false);
        }
    }

}
