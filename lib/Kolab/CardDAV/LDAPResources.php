<?php

/**
 * CardDAV Directory class providing read-only access
 * to an LDAP-based resources address book.
 */

namespace Kolab\CardDAV;

use \rcube;
use \rcube_ldap;
use \rcube_ldap_generic;
use Sabre\DAV;
use Sabre\DAVACL;
use Sabre\CardDAV\Property;
use Kolab\Utils\VObjectUtils;

class LDAPResources extends LDAPDirectory
{
    const DIRECTORY_NAME = 'ldap-resources';

    /**
     * Default constructor
     */
    function __construct($config, $principalUri, $carddavBackend = null)
    {
        $this->config = $config;
        $this->principalUri = $principalUri;

        $this->addressBookInfo = array(
            'id' => self::DIRECTORY_NAME,
            'uri' => self::DIRECTORY_NAME,
            '{DAV:}displayname' => $config['name'] ?: "LDAP Resources", 
            '{urn:ietf:params:xml:ns:carddav}supported-address-data' => new Property\SupportedAddressData(),
            'principaluri' => $principalUri,
        );

        // used for vcard serialization
        $this->carddavBackend = $carddavBackend ?: new ContactsBackend();
        $this->carddavBackend->ldap_resources = $this;

        // initialize cache. We need a different address space from GAL
        // so don't mix our caches
        $rcube = rcube::get_instance();
        if ($rcube->config->get('kolabdav_res_cache')) {
            $this->cache = $rcube->get_cache_shared('kolabdav_res');

            // expunge cache every now and then
            if (rand(0,10) === 0) {
                $this->cache->expunge();
            }
        }
    }

    /**
     * Returns the name of the node.
     *
     * This is used to generate the url.
     *
     * @return string
     */
    function getName()
    {
        return self::DIRECTORY_NAME;
    }
}
