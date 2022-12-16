<?php

/**
 * SabreDAV UserAddressBooks derived class for the Kolab.
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

namespace Kolab\CardDAV;

use \rcube;
use Sabre\DAV;
use Sabre\DAVACL;
use Sabre\CardDAV;

/**
 * UserAddressBooks class
 *
 * The UserAddressBooks collection contains a list of addressbooks associated with a user
 */
class UserAddressBooks extends \Sabre\CardDAV\AddressBookHome implements DAV\IExtendedCollection, DAV\IProperties, DAVACL\IACL
{
    // pseudo-singleton instance
    private $ldap_directory;
    private $ldap_resources;

    /**
     * Returns a list of addressbooks
     *
     * @return array
     */
    public function getChildren()
    {
        $addressbooks = $this->carddavBackend->getAddressbooksForUser($this->principalUri);
        $objs = array();
        foreach($addressbooks as $addressbook) {
            $objs[] = new AddressBook($this->carddavBackend, $addressbook);
        }

        if (rcube::get_instance()->config->get('kolabdav_ldap_directory')) {
            $objs[] = $this->getLDAPDirectory();
        }

        if (rcube::get_instance()->config->get('kolabdav_ldap_resources')) {
            $objs[] = $this->getLDAPResources();
        }

        return $objs;
    }

    /**
     * Returns a single addressbook, by name
     *
     * @param string $name
     * @return \AddressBook
     */
    public function getChild($name)
    {
        if ($name == LDAPDirectory::DIRECTORY_NAME) {
            return $this->getLDAPDirectory();
        }
        if ($name == LDAPResources::DIRECTORY_NAME) {
            return $this->getLDAPResources();
        }

        if ($addressbook = $this->carddavBackend->getAddressBookByName($name)) {
            $addressbook['principaluri'] = $this->principalUri;
            return new AddressBook($this->carddavBackend, $addressbook);
        }

        throw new DAV\Exception\NotFound('Addressbook with name \'' . $name . '\' could not be found');
    }

    /**
     * Getter for the singleton instance of the LDAP directory
     */
    private function getLDAPDirectory()
    {
        if (!$this->ldap_directory) {
            $rcube = rcube::get_instance();
            $config = $rcube->config->get('kolabdav_ldap_directory');
            $config['debug'] = $rcube->config->get('ldap_debug');
            $this->ldap_directory = new LDAPDirectory($config, $this->principalUri, $this->carddavBackend);
        }

        return $this->ldap_directory;
    }

    /**
     * Getter for the singleton instance of the LDAP resources
     */
    private function getLDAPResources()
    {
        if (!$this->ldap_resources) {
            $rcube = rcube::get_instance();
            $config = $rcube->config->get('kolabdav_ldap_resources');
            $config['debug'] = $rcube->config->get('ldap_debug');
            $this->ldap_resources = new LDAPResources($config, $this->principalUri, $this->carddavBackend);
        }

        return $this->ldap_resources;
    }


    /**
     * Returns the list of properties
     *
     * @param array $requestedProperties
     * @return array
     */
    public function getProperties($requestedProperties)
    {
        // console(__METHOD__, $requestedProperties);

        $response = array();

        foreach ($requestedProperties as $prop) {
            switch($prop) {
                case '{urn:ietf:params:xml:ns:carddav}supported-address-data':
                    $response[$prop] = new CardDAV\Xml\Property\SupportedAddressData(ContactsBackend::$supported_address_data);
                    break;
            }
        }

        return $response;
    }

    /**
     * Updates properties on this node.
     *
     * @param PropPatch $propPatch
     * @return void
     */
    public function propPatch(DAV\PropPatch $propPatch)
    {
        console(__METHOD__, $propPatch);
        // NOP
    }
}
