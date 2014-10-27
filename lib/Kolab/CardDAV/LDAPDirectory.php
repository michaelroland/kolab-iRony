<?php

/**
 * CardDAV Directory class providing read-only access
 * to an LDAP-based global address book.
 *
 * This implements the CardDAV Directory Gateway Extension suggested by Apple Inc.
 * http://tools.ietf.org/html/draft-daboo-carddav-directory-gateway-02
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

namespace Kolab\CardDAV;

use \rcube;
use \rcube_ldap;
use \rcube_ldap_generic;
use Sabre\DAV;
use Sabre\DAVACL;
use Sabre\CardDAV\Property;
use Kolab\Utils\VObjectUtils;

/**
 * CardDAV Directory Gateway implementation
 */
class LDAPDirectory extends DAV\Collection implements \Sabre\CardDAV\IDirectory, DAV\IProperties, DAVACL\IACL
{
    const DIRECTORY_NAME = 'ldap-directory';

    private $config;
    private $ldap;
    private $carddavBackend;
    private $principalUri;
    private $addressBookInfo = array();
    private $cache;
    private $query;
    private $filter;

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
            '{DAV:}displayname' => $config['name'] ?: "LDAP Directory",
            '{urn:ietf:params:xml:ns:caldav}supported-address-data' => new Property\SupportedAddressData(),
            'principaluri' => $principalUri,
        );

        // used for vcard serialization
        $this->carddavBackend = $carddavBackend ?: new ContactsBackend();
        $this->carddavBackend->ldap_directory = $this;

        // initialize cache
        $rcube = rcube::get_instance();
        if ($rcube->config->get('kolabdav_ldap_cache')) {
            $this->cache = $rcube->get_cache_shared('kolabdav_ldap');

            // expunge cache every now and then
            if (rand(0,10) === 0) {
                $this->cache->expunge();
            }
        }
    }

    private function connect()
    {
      if (!isset($this->ldap)) {
        $this->ldap = new rcube_ldap($this->config, $this->config['debug']);
        $this->ldap->set_pagesize($this->config['sizelimit'] ?: 10000);
      }

      return $this->ldap->ready ? $this->ldap : null;
    }

    /**
     * Set parsed addressbook-query object for filtering
     */
    function setAddressbookQuery($query)
    {
        $this->query = $query;
        $this->filter = $this->addressbook_query2ldap_filter($query);
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

    /**
     * Returns a specific child node, referenced by its name
     *
     * This method must throw Sabre\DAV\Exception\NotFound if the node does not
     * exist.
     *
     * @param string $name
     * @return DAV\INode
     */
    function getChild($cardUri)
    {
        console(__METHOD__, $cardUri);

        $uid = VObjectUtils::uri2uid($cardUri, '.vcf');
        $record = null;

        // get from cache
        $cache_key = $uid;
        if ($this->cache && ($cached = $this->cache->get($cache_key))) {
            return new LDAPCard($this->carddavBackend, $this->addressBookInfo, $cached);
        }

        if ($contact = $this->getContactObject($uid)) {
            $obj = array(
                'id' => $contact['uid'],
                'uri' => VObjectUtils::uid2uri($contact['uid'], '.vcf'),
                'lastmodified' => $contact['_timestamp'],
                'carddata' => $this->carddavBackend->to_vcard($contact),
                'etag' => self::_get_etag($contact),
            );

            // cache this object
            if ($this->cache) {
                $this->cache->set($cache_key, $obj);
            }

            return new LDAPCard($this->carddavBackend, $this->addressBookInfo, $obj);
        }

        throw new DAV\Exception\NotFound('Card not found');
    }

    /**
     * Read contact object from LDAP
     */
    function getContactObject($uid)
    {
        $contact = null;

        if ($ldap = $this->connect()) {
            $ldap->reset();

            // used cached uid mapping
            $cached_index = $this->cache ? $this->cache->get('index') : array();
            if ($cached_index[$uid]) {
                $contact = $ldap->get_record($cached_index[$uid][0], true);
            }
            else {  // query for uid
                $result = $ldap->search('uid', $uid, 1, true, true);
                if ($result->count) {
                    $contact = $result[0];
                }
            }

            if ($contact) {
                $this->_normalize_contact($contact);
            }
        }

        return $contact;
    }

    /**
     * Returns an array with all the child nodes
     *
     * @return DAV\INode[]
     */
    function getChildren()
    {
        console(__METHOD__, $this->query, $this->filter);

        $children = array();

        // return cached index
        if (!$this->query && !$this->config['searchonly'] && $this->cache && ($cached_index = $this->cache->get('index'))) {
            foreach ($cached_index as $uid => $c) {
                $obj = array(
                    'id'   => $uid,
                    'uri'  => VObjectUtils::uid2uri($uid, '.vcf'),
                    'etag' => $c[1],
                    'lastmodified' => $c[2],
                );
                $children[] = new LDAPCard($this->carddavBackend, $this->addressBookInfo, $obj);
            }

            return $children;
        }

        // query LDAP if we have a search query or listing is allowed
        if (($this->query || !$this->config['searchonly']) && ($ldap = $this->connect())) {
            // set pagesize from query limit attribute
            if ($this->query && $this->query->limit) {
                $this->ldap->set_pagesize(intval($this->query->limit));
            }

            // set the prepared LDAP filter derived from the addressbook-query
            if ($this->query && !empty($this->filter)) {
                $ldap->set_search_set($this->filter);
            }
            else {
                $ldap->set_search_set(null);
            }

            $results = $ldap->list_records(null);
            $directory_index = array();

            // convert results into vcard blocks
            foreach ($results as $contact) {
                $this->_normalize_contact($contact);

                $obj = array(
                    'id'  => $contact['uid'],
                    'uri' => VObjectUtils::uid2uri($contact['uid'], '.vcf'),
                    'lastmodified' => $contact['_timestamp'],
                    'carddata' => $this->carddavBackend->to_vcard($contact),
                    'etag' => self::_get_etag($contact),
                );

                // cache record
                $cache_key = $contact['uid'];
                if ($this->cache) {
                    $this->cache->set($cache_key, $obj);
                }

                $directory_index[$contact['uid']] = array($contact['ID'], $obj['etag'], $contact['_timestamp']);

                // add CardDAV node
                $children[] = new LDAPCard($this->carddavBackend, $this->addressBookInfo, $obj);
            }

            // cache the full listing
            if (empty($this->filter) && $this->cache) {
                $this->cache->set('index', $directory_index);
            }
        }

        return $children;
    }

    /**
     * Returns a list of properties for this node.
     *
     * The properties list is a list of propertynames the client requested,
     * encoded in clark-notation {xmlnamespace}tagname
     *
     * If the array is empty, it means 'all properties' were requested.
     *
     * @param array $properties
     * @return array
     */
    public function getProperties($properties)
    {
        console(__METHOD__, $properties);

        $response = array();
        foreach ($properties as $propertyName) {
            if (isset($this->addressBookInfo[$propertyName])) {
                $response[$propertyName] = $this->addressBookInfo[$propertyName];
            }
            else if ($propertyName == '{DAV:}getlastmodified') {
                $response[$propertyName] = new DAV\Property\GetLastModified($this->getLastModified());
            }
        }

        return $response;

    }

    /**
     * Returns the last modification time, as a unix timestamp
     *
     * @return int
     */
    function getLastModified()
    {
        console(__METHOD__);
        return time();
    }

    /**
     * Deletes the entire addressbook.
     *
     * @return void
     */
    public function delete()
    {
        throw new DAV\Exception\MethodNotAllowed('Deleting directories is not allowed');
    }

    /**
     * Renames the addressbook
     *
     * @param string $newName
     * @return void
     */
    public function setName($newName)
    {
        throw new DAV\Exception\MethodNotAllowed('Renaming directories not allowed');
    }

    /**
     * Returns the owner principal
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    public function getOwner()
    {
        return $this->principalUri;
    }

    /**
     * Returns a group principal
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    function getGroup()
    {
        return null;
    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   * 'privilege', a string such as {DAV:}read or {DAV:}write
     *   * 'principal', a url to the principal who owns the node
     *   * 'protected' (optional), indicating that this ACE is not allowed to be updated.
     *
     * @return array
     */
    public function getACL()
    {
        $acl = array(
            array(
                'privilege' => '{DAV:}read',
                'principal' => $this->principalUri,
                'protected' => true,
            ),
        );

        return $acl;
    }

    /**
     * Updates the ACL
     *
     * @param array $acl
     * @return void
     */
    function setACL(array $acl)
    {
        throw new DAV\Exception\MethodNotAllowed('Changing ACL for directories is not allowed');
    }

    /**
     * Returns the list of supported privileges for this node.
     *
     * If null is returned from this method, the default privilege set is used,
     * which is fine for most common usecases.
     *
     * @return array|null
     */
    function getSupportedPrivilegeSet()
    {
        return null;
    }

    /**
     * Updates properties on this node,
     *
     * @param array $mutations
     * @return bool|array
     */
    function updateProperties($mutations)
    {
        console(__METHOD__, $mutations);
        return false;
    }

    /**
     * Post-process the given contact record from rcube_ldap
     */
    private function _normalize_contact(&$contact)
    {
        if (is_numeric($contact['changed'])) {
            $contact['_timestamp'] = intval($contact['changed']);
            $contact['changed'] = new \DateTime('@' . $contact['changed']);
        }
        else if (!empty($contact['changed'])) {
            try {
                $contact['changed'] = new \DateTime($contact['changed']);
                $contact['_timestamp'] = intval($contact['changed']->format('U'));
            }
            catch (Exception $e) {
                $contact['changed'] = null;
            }
        }

        // map col:subtype fields to a list that the vcard serialization function understands
        foreach (array('email' => 'address', 'phone' => 'number', 'website' => 'url') as $col => $prop) {
            foreach (rcube_ldap::get_col_values($col, $contact) as $type => $values) {
                foreach ((array)$values as $value) {
                    $contact[$col][] = array($prop => $value, 'type' => $type);
                }
            }
            unset($contact[$col.':'.$type]);
        }

        $addresses = array();
        foreach (rcube_ldap::get_col_values('address', $contact) as $type => $values) {
            foreach ((array)$values as $adr) {
                // skip empty address
                $adr = array_filter($adr);
                if (empty($adr))
                    continue;

                $addresses[] = array(
                    'type'     => $type,
                    'street'   => $adr['street'],
                    'locality' => $adr['locality'],
                    'code'     => $adr['zipcode'],
                    'region'   => $adr['region'],
                    'country'  => $adr['country'],
                );
            }

            unset($contact['address:'.$type]);
        }

        $contact['address'] = $addresses;
    }

    /**
     * Translate the given AddressBookQueryParser object into an LDAP filter
     */
    private function addressbook_query2ldap_filter($query)
    {
        $criterias = array();

        foreach ($query->filters as $filter) {
            $ldap_attrs = $this->map_property2ldap($filter['name']);
            $ldap_filter = ''; $count = 0;

            // unknown attribute, skip
            if (empty($ldap_attrs)) {
                continue;
            }

            foreach ((array)$filter['text-matches'] as $matcher) {
                // case-insensitive matching
                if (in_array($matcher['collation'], array('i;unicode-casemap', 'i;ascii-casemap'))) {
                    $matcher['value'] = mb_strtolower($matcher['value']);
                }
                $value = rcube_ldap_generic::quote_string($matcher['value']);
                $ldap_match = '';

                // this assumes fuzzy search capabilities of the LDAP backend
                switch ($matcher['match-type']) {
                    case 'contains':
                        $wp = $ws = '*';
                        break;
                    case 'starts-with':
                        $ws = '*';
                        break;
                    case 'ends-with':
                        $wp = '*';
                        break;
                    default:
                        $wp = $ws = '';
                }

                // OR query for all attributes involved
                if (count($ldap_attrs) > 1) {
                    $ldap_match .= '(|';
                }
                foreach ($ldap_attrs as $attr) {
                    $ldap_match .= "($attr=$wp$value$ws)";
                }
                if (count($ldap_attrs) > 1) {
                    $ldap_match .= ')';
                }

                // negate the filter
                if ($matcher['negate-condition']) {
                    $ldap_match = '(!' . $ldap_match . ')';
                }

                $ldap_filter .= $ldap_match;
                $count++;
            }

            if ($count > 1) {
                $criterias[] = '(' . ($filter['test'] == 'allof' ? '&' : '|') . $ldap_filter . ')';
            }
            else if (!empty($ldap_filter)) {
                $criterias[] = $ldap_filter;
            }
        }

        return empty($criterias) ? '' : sprintf('(%s%s)', $query->test == 'allof' ? '&' : '|', join('', $criterias));
    }

    /**
     * Map a vcard property to an LDAP attribute
     */
    private function map_property2ldap($propname)
    {
        $attribs = array();

        // LDAP backend not available, abort
        if (!($ldap = $this->connect())) {
            return $attribs;
        }

        $vcard_fieldmap = array(
            'FN'    => array('name'),
            'N'     => array('surname','firstname','middlename'),
            'ADR'   => array('street','locality','region','code','country'),
            'TITLE' => array('jobtitle'),
            'ORG'   => array('organization','department'),
            'TEL'   => array('phone'),
            'URL'   => array('website'),
            'ROLE'  => array('profession'),
            'BDAY'  => array('birthday'),
            'IMPP'  => array('im'),
        );

        $fields = $vcard_fieldmap[$propname] ?: array(strtolower($propname));
        foreach ($fields as $field) {
            if ($ldap->coltypes[$field]) {
                $attribs = array_merge($attribs, (array)$ldap->coltypes[$field]['attributes']);
            }
        }

        return $attribs;
    }

    /**
     * Generate an Etag string from the given contact data
     *
     * @param array Hash array with contact properties from libkolab
     * @return string Etag string
     */
    private static function _get_etag($contact)
    {
        return sprintf('"%s-%d"', substr(md5($contact['uid']), 0, 16), $contact['_timestamp']);
    }
}
