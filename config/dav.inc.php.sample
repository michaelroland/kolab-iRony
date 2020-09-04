<?php

/*
 +-------------------------------------------------------------------------+
 | Configuration for the Kolab DAV server                                  |
 |                                                                         |
 | Copyright (C) 2013, Kolab Systems AG                                    |
 |                                                                         |
 | This program is free software: you can redistribute it and/or modify    |
 | it under the terms of the GNU Affero General Public License as          |
 | published by the Free Software Foundation, either version 3 of the      |
 | License, or (at your option) any later version.                         |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the            |
 | GNU Affero General Public License for more details.                     |
 |                                                                         |
 | You should have received a copy of the GNU Affero General Public License|
 | along with this program. If not, see <http://www.gnu.org/licenses/>.    |
 |                                                                         |
 +-------------------------------------------------------------------------+
*/

$config = array();

// The HTTP path to the iRony root directory.
// Set to / if the service is registered as document root for a virtual host
$config['base_uri'] = '/iRony/';

// User agent string written to kolab storage MIME messages
$config['useragent'] = 'Kolab DAV Server libkolab/' . RCUBE_VERSION;

// Type of Auth cache. Supported values: 'db', 'apc' and 'memcache'.
// Note: This is only for username canonification map.
$config['kolabdav_auth_cache'] = 'db';

// lifetime of the Auth cache, possible units: s, m, h, d, w
$config['kolabdav_auth_cache_ttl'] = '1h';

// enable debug console showing the internal function calls triggered
// by http requests. This will write log to /var/log/iRony/console
$config['kolabdav_console'] = false;

// enable logging of full HTTP payload
// (bitmask of these values: 2 = HTTP Requests, 4 = HTTP Responses)
$config['kolabdav_http_log'] = 0;

// expose iTip invitations from email inbox in CalDAV scheduling inbox.
// this will make capable CalDAV clients process event invitations and
// as a result, the invitation messages are removed from the email inbox.
// WARNING: this feature is still experimental and not fully implemented.
// See https://git.kolab.org/T93 for details and implementation status.
$config['kolabdav_caldav_inbox'] = false;

// Enables the CardDAV Directory Gateway Extension by exposing an
// LDAP-based address book in the pricipals address book collection.
// Properties of this option are the same as for $config['ldap_public'] entries.
// NOTE: Mapping of (additional) 'uid' and 'changed' fields is required!
/*
$config['kolabdav_ldap_directory'] = array(
    'name'           => 'Global Address Book',
    'hosts'          => 'localhost',
    'port'           => 389,
    'use_tls'        => false,
    // If true the base_dn, bind_dn and bind_pass default to the user's credentials.
    'user_specific'  => false,
    // It's possible to bind with the current user's credentials for individual address books.
    // The login name is used to search for the DN to bind with
    'search_base_dn'            => 'ou=People,dc=example,dc=org',
    'search_bind_dn'            => 'uid=kolab-service,ou=Special Users,dc=example,dc=org',
    'search_bind_pw'            => 'Welcome2KolabSystems',
    'search_filter'             => '(&(objectClass=inetOrgPerson)(mail=%fu))',
    // When 'user_specific' is enabled following variables can be used in base_dn/bind_dn config:
    // %fu - The full username provided, assumes the username is an email
    //       address, uses the username_domain value if not an email address.
    // %u  - The username prior to the '@'.
    // %d  - The domain name after the '@'.
    // %dc - The domain name hierarchal string e.g. "dc=test,dc=domain,dc=com"
    // %dn - DN found by ldap search when search_filter/search_base_dn are used
    'base_dn'        => 'ou=People,dc=example,dc=org',
    'bind_dn'        => 'uid=kolab-service,ou=Special Users,dc=example,dc=org',
    'bind_pass'      => 'Welcome2KolabSystems',
    'ldap_version'   => 3,
    'filter'         => '(objectClass=inetOrgPerson)',
    'search_fields'  => array('displayname', 'mail'),
    'sort'           => array('displayname', 'sn', 'givenname', 'cn'),
    'scope'          => 'sub',
    'searchonly'     => true,  // Set to false to enable listing
    'sizelimit'      => '1000',
    'timelimit'      => '0',
    'fieldmap'       => array(
        // Roundcube        => LDAP
        'name'              => 'displayName',
        'surname'           => 'sn',
        'firstname'         => 'givenName',
        'middlename'        => 'initials',
        'prefix'            => 'title',
        'email:work'        => 'mail',
        'email:other'       => 'alias',
        'phone:main'        => 'telephoneNumber',
        'phone:work'        => 'alternateTelephoneNumber',
        'phone:mobile'      => 'mobile',
        'phone:work2'       => 'blackberry',
        'street'            => 'street',
        'zipcode'           => 'postalCode',
        'locality'          => 'l',
        'organization'      => 'o',
        'jobtitle'          => 'title',
        'photo'             => 'jpegphoto',
        // required for internal handling and caching
        'uid'               => 'nsuniqueid',
        'changed'           => 'modifytimestamp',
    ),
);

// Expose all resources as an LDAP-based address book in the pricipals address book collection.
// This enables Non-Kolab-Clients to add resources to an event.
// Properties of this option are the same as for $config['kolabdav_ldap_directory'] entries.
$config['kolabdav_ldap_resources']  = array(
    'name'           => 'Global Resources',
    'hosts'          => 'localhost',
    'port'           => 389,
    'use_tls'        => false,
    'user_specific'  => false,
    'search_base_dn' => 'ou=People,dc=example,dc=org',
    'search_bind_dn' => 'uid=kolab-service,ou=Special Users,dc=example,dc=org',
    'search_bind_pw' => 'Welcome2KolabSystems',
    'search_filter'  => '(&(objectClass=inetOrgPerson)(mail=%fu))',
    'base_dn'        => 'ou=Resources,dc=example,dc=org',
    'bind_dn'        => 'uid=kolab-service,ou=Special Users,dc=example,dc=org',
    'bind_pass'      => 'Welcome2KolabSystems',
    'ldap_version'   => 3,
    'filter'         => '(|(objectclass=groupofuniquenames)(objectclass=groupofurls)(objectclass=kolabsharedfolder))',
    'search_fields'  => array('displayname', 'mail'),
    'sort'           => array('displayname', 'sn', 'givenname', 'cn'),
    'scope'          => 'sub',
    'searchonly'     => false,  // Set to false to enable listing
    'sizelimit'      => '1000',
    'timelimit'      => '0',
    'fieldmap'       => array(
        // Internal         => LDAP
        'name'              => 'cn',
        'email'             => 'mail',
        'owner'             => 'owner',
        'description'       => 'description',
        'attributes'        => 'kolabdescattribute',
        'members'           => 'uniquemember',
        // these mappings are required for owner display
        'phone'             => 'telephoneNumber',
        'mobile'            => 'mobile',
    ),
);

*/

// Enable caching for LDAP directory data.
// This is recommended with 'searchonly' => false to speed-up sychronization of multiple clients
// $config['kolabdav_ldap_cache'] = 'memcache';
// $config['kolabdav_ldap_cache_ttl'] = 600;   // in seconds
