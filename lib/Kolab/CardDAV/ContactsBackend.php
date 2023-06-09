<?php

/**
 * SabreDAV Contacts backend for Kolab.
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
use \rcube_mime;
use \rcube_charset;
use \kolab_storage;
use Sabre\DAV;
use Sabre\CardDAV;
use Sabre\VObject;
use Sabre\VObject\DateTimeParser;
use Kolab\Utils\DAVBackend;
use Kolab\Utils\VObjectUtils;

/**
 * Kolab Contacts backend.
 *
 * Checkout the Sabre\CardDAV\Backend\BackendInterface for all the methods that must be implemented.
 */
class ContactsBackend extends CardDAV\Backend\AbstractBackend
{
    public static $supported_address_data = array(
        array('contentType' => 'text/vcard', 'version' => '3.0'),
        array('contentType' => 'text/vcard', 'version' => '4.0'),
    );

    public $ldap_directory;
    public $ldap_resources;

    private $sources;
    private $folders;
    private $aliases;
    private $useragent;
    private $subscribed = null;

    // mapping of labelled X-AB properties to known vcard fields
    private $xab_labelled_map = array(
        'X-ABDATE' => array(
            'anniversary' => 'X-ANNIVERSARY',
        ),
        'X-ABRELATEDNAMES' => array(
            'child'     => 'X-CHILDREN',
            'spouse'    => 'X-SPOUSE',
            'manager'   => 'X-MANAGER',
            'assistant' => 'X-ASSISTANT',
        ),
    );
    // known labels need to be quoted specially with _$!< >!$_
    private $xab_known_labels = array('anniversary','child','parent','mother','father','brother','sister','friend','spouse','manager','assistant','partner','other');

    // mapping of related types to internal contact properties
    private $related_map = array(
        'child'     => 'children',
        'spouse'    => 'spouse',
        'manager'   => 'manager',
        'assistant' => 'assistant',
    );

    private $phonetypes = array(
        'main'    => 'voice',
        'homefax' => 'home,fax',
        'workfax' => 'work,fax',
        'mobile'  => 'cell',
        'other'   => 'textphone',
    );

    private $improtocols = array(
        'jabber' => 'xmpp',
    );


    /**
     * Read available contact folders from server
     */
    private function _read_sources()
    {
        // already read sources
        if (isset($this->sources))
            return $this->sources;

        // get all folders that have "contact" type
        $folders = kolab_storage::get_folders('contact', $this->subscribed);
        $this->sources = $this->folders = $this->aliases = array();

        foreach (kolab_storage::sort_folders($folders) as $folder) {
            $id = $folder->get_uid();
            $fdata = $folder->get_imap_data();  // fetch IMAP folder data for CTag generation
            $this->folders[$id] = $folder;
            $this->sources[$id] = array(
                'id' => $id,
                'uri' => $id,
                '{DAV:}displayname' => html_entity_decode($folder->get_name(), ENT_COMPAT, RCUBE_CHARSET),
                '{http://calendarserver.org/ns/}getctag' => sprintf('%d-%d-%d', $fdata['UIDVALIDITY'], $fdata['HIGHESTMODSEQ'], $fdata['UIDNEXT']),
                '{urn:ietf:params:xml:ns:carddav}supported-address-data' => new CardDAV\Xml\Property\SupportedAddressData(self::$supported_address_data),
            );
            $this->aliases[$folder->name] = $id;

            // map default folder to the magic 'all' resource
            if ($folder->default)
                $this->aliases['__all__'] = $id;
        }

        return $this->sources;
    }

    /**
     * Getter for a kolab_storage_folder representing the address book for the given ID
     *
     * @param string Folder ID
     * @return object kolab_storage_folder instance
     */
    public function get_storage_folder($id)
    {
        // resolve alias name
        if ($this->aliases[$id]) {
            $id = $this->aliases[$id];
        }

        if ($this->folders[$id]) {
            DAVBackend::check_storage_folder($this->folders[$id]);
            return $this->folders[$id];
        }
        else {
            return DAVBackend::get_storage_folder($id, 'contact');
        }
    }

    /**
     * Returns the list of addressbooks for a specific user.
     *
     * @param string $principalUri
     * @return array
     */
    public function getAddressBooksForUser($principalUri)
    {
        console(__METHOD__, $principalUri, $this->useragent);

        // Reset imap cache so we work with up-to-date folders list
        // We do this only when a client requests list of address books,
        // and we assume clients do not ask for list too often (Bifrost#T175679)
        rcube::get_instance()->get_storage()->clear_cache('mailboxes', true);

        $this->_read_sources();

        // special case for the apple address book which only supports one (!) address book
        if ($this->useragent == 'macosx' && count($this->sources) > 1) {
            $source = $this->getAddressBookByName('__all__');
            $source['principaluri'] = $principalUri;
            return array($source);
        }

        $addressBooks = array();
        foreach ($this->sources as $id => $source) {
            $source['principaluri'] = $principalUri;
            $addressBooks[] = $source;
        }

        return $addressBooks;
    }

    /**
     * Returns properties for a specific node identified by name/uri
     *
     * @param string Node name/uri
     * @return array Hash array with addressbook properties or null if not found
     */
    public function getAddressBookByName($addressBookUri)
    {
        console(__METHOD__, $addressBookUri);

        $this->_read_sources();
        $id = $addressBookUri;

        // return the magic *single* address book for Apple's Address Book App
        if ($id == '__all__') {
            $ctags = array();
            foreach ($this->sources as $source) {
                $ctags[] = $source['{http://calendarserver.org/ns/}getctag'];
            }

            return array(
                'id' => '__all__',
                'uri' => '__all__',
                '{DAV:}displayname' => 'All',
                '{http://calendarserver.org/ns/}getctag' => join(':', $ctags),
                '{urn:ietf:params:xml:ns:carddav}supported-address-data' => new CardDAV\Xml\Property\SupportedAddressData(self::$supported_address_data),
            );
        }

        // resolve aliases (addressbook by folder name)
        if ($this->aliases[$addressBookUri]) {
            $id = $this->aliases[$addressBookUri];
        }

        // retry with subscribed = false (#2701)
        if (empty($this->sources[$id]) && $this->subscribed === null && rcube::get_instance()->config->get('kolab_use_subscriptions')) {
            $this->subscribed = false;
            unset($this->sources);
            return $this->getAddressBookByName($addressBookUri);
        }

        return $this->sources[$id];
    }

    /**
     * Updates properties for an address book.
     *
     * The list of mutations is stored in a Sabre\DAV\PropPatch object.
     * To do the actual updates, you must tell this object which properties
     * you're going to process with the handle() method.
     *
     * Calling the handle method is like telling the PropPatch object "I
     * promise I can handle updating this property".
     *
     * Read the PropPatch documenation for more info and examples.
     *
     * @param string $addressBookId
     * @param \Sabre\DAV\PropPatch $propPatch
     * @return void
     */
    public function updateAddressBook($addressBookId, \Sabre\DAV\PropPatch $propPatch)
    {
        console(__METHOD__, $addressBookId, $propPatch);

        if ($addressBookId == '__all__')
            return false;

        if ($folder = $this->get_storage_folder($addressBookId)) {
            DAVBackend::handle_proppatch($folder, $propPatch);
        }
    }

    /**
     * Creates a new address book
     *
     * @param string $principalUri
     * @param string $url Just the 'basename' of the url.
     * @param array $properties
     * @return void
     */
    public function createAddressBook($principalUri, $url, array $properties)
    {
        console(__METHOD__, $principalUri, $url, $properties);

        return DAVBackend::folder_create('contact', $properties, $url);
    }

    /**
     * Deletes an entire addressbook and all its contents
     *
     * @param int $addressBookId
     * @return void
     */
    public function deleteAddressBook($addressBookId)
    {
        console(__METHOD__, $addressBookId);

        if ($addressBookId == '__all__')
            return;

        $folder = $this->get_storage_folder($addressBookId);
        if ($folder && !kolab_storage::folder_delete($folder->name)) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error deleting calendar folder $folder->name"),
                true, false);
        }
    }

    /**
     * Returns all cards for a specific addressbook id.
     *
     * This method should return the following properties for each card:
     *   * carddata - raw vcard data
     *   * uri - Some unique url
     *   * lastmodified - A unix timestamp
     *   * etag - A unique etag. This must change every time the card changes.
     *   * size - The size of the card in bytes.
     *
     * If these last two properties are provided, less time will be spent
     * calculating them. If they are specified, you can also ommit carddata.
     * This may speed up certain requests, especially with large cards.
     *
     * @param mixed $addressBookId
     * @return array
     */
    public function getCards($addressBookId)
    {
        console(__METHOD__, $addressBookId);

        // recursively fetch contacts from all folders
        if ($addressBookId == '__all__') {
            $cards = array();
            foreach ($this->sources as $id => $source) {
                $cards = array_merge($cards, $this->getCards($id));
            }
            return $cards;
        }

        $groups_support = $this->useragent != 'thunderbird';
        $query = array(array('type', '=', $groups_support ? array('contact','distribution-list') : 'contact'));
        $cards = array();
        if ($storage = $this->get_storage_folder($addressBookId)) {
            foreach ($storage->select($query, true) as $contact) {
                $cards[] = array(
                    'id' => $contact['uid'],
                    'uri' => VObjectUtils::uid2uri($contact['uid'], '.vcf'),
                    'lastmodified' => is_a($contact['changed'], 'DateTime') ? $contact['changed']->format('U') : null,
                    'etag' => self::_get_etag($contact),
                    'size' => $contact['_size'],
                );
            }
        }

        return $cards;
    }

    /**
     * Returns a specfic card.
     *
     * The same set of properties must be returned as with getCards. The only
     * exception is that 'carddata' is absolutely required.
     *
     * @param mixed $addressBookId
     * @param string $cardUri
     * @return array
     */
    public function getCard($addressBookId, $cardUri)
    {
        console(__METHOD__, $addressBookId, $cardUri);

        $uid = VObjectUtils::uri2uid($cardUri, '.vcf');

        // search all folders for the given card
        if ($addressBookId == '__all__') {
            $contact = $this->get_card_by_uid($uid, $storage);
        }
        // read card data from LDAP directory
        else if ($addressBookId == LDAPDirectory::DIRECTORY_NAME) {
            if (is_object($this->ldap_directory)) {
                $contact = $this->ldap_directory->getContactObject($uid);
            }
        }
        // read card data from LDAP resources
        else if ($addressBookId == LDAPResources::DIRECTORY_NAME) {
            if (is_object($this->ldap_resources)) {
                $contact = $this->ldap_resources->getContactObject($uid);
            }
        }
        else {
            $storage = $this->get_storage_folder($addressBookId);
            $contact = $storage->get_object($uid, '*');
        }

        if ($contact) {
            return array(
                'id' => $contact['uid'],
                'uri' => VObjectUtils::uid2uri($contact['uid'], '.vcf'),
                'lastmodified' => is_a($contact['changed'], 'DateTime') ? $contact['changed']->format('U') : null,
                'carddata' => $this->to_vcard($contact),
                'etag' => self::_get_etag($contact),
            );
        }

        return array();
    }

    /**
     * Creates a new card.
     *
     * The addressbook id will be passed as the first argument. This is the
     * same id as it is returned from the getAddressbooksForUser method.
     *
     * The cardUri is a base uri, and doesn't include the full path. The
     * cardData argument is the vcard body, and is passed as a string.
     *
     * It is possible to return an ETag from this method. This ETag is for the
     * newly created resource, and must be enclosed with double quotes (that
     * is, the string itself must contain the double quotes).
     *
     * You should only return the ETag if you store the carddata as-is. If a
     * subsequent GET request on the same card does not have the same body,
     * byte-by-byte and you did return an ETag here, clients tend to get
     * confused.
     *
     * @param mixed $addressBookId
     * @param string $cardUri
     * @param string $cardData
     * @return string|null
     */
    public function createCard($addressBookId, $cardUri, $cardData)
    {
        console(__METHOD__, $addressBookId, $cardUri, $cardData);

        $uid = VObjectUtils::uri2uid($cardUri, '.vcf');
        $storage = $this->get_storage_folder($addressBookId);
        $object = $this->parse_vcard($cardData, $uid);

        if (empty($object) || empty($object['uid'])) {
            throw new DAV\Exception('Parse error: not a valid VCard object');
        }

        // if URI doesn't match the content's UID, the object might already exist!
        $cardUri = VObjectUtils::uid2uri($object['uid'], '.vcf');
        if ($object['uid'] != $uid && $this->getCard($addressBookId, $cardUri)) {
            Plugin::$redirect_basename = $cardUri;
            return $this->updateCard($addressBookId, $cardUri, $cardData);
        }

        $success = $storage->save($object, $object['_type']);
        if (!$success) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error saving contact object to Kolab server"),
                true, false);

            throw new DAV\Exception('Error saving contact card to backend');
        }

        // send Location: header if URI doesn't match object's UID (Bug #2109)
        if ($object['uid'] != $uid) {
            Plugin::$redirect_basename = $cardUri;
        }

        // return new Etag
        return $success ? self::_get_etag($object) : null;
    }

    /**
     * Updates a card.
     *
     * The addressbook id will be passed as the first argument. This is the
     * same id as it is returned from the getAddressbooksForUser method.
     *
     * The cardUri is a base uri, and doesn't include the full path. The
     * cardData argument is the vcard body, and is passed as a string.
     *
     * It is possible to return an ETag from this method. This ETag should
     * match that of the updated resource, and must be enclosed with double
     * quotes (that is: the string itself must contain the actual quotes).
     *
     * If you don't return an ETag, you can just return null.
     *
     * @param mixed $addressBookId
     * @param string $cardUri
     * @param string $cardData
     * @return string|null
     */
    public function updateCard($addressBookId, $cardUri, $cardData)
    {
        console(__METHOD__, $addressBookId, $cardUri, $cardData);

        $uid = VObjectUtils::uri2uid($cardUri, '.vcf');
        $object = $this->parse_vcard($cardData, $uid);

        // sanity check
        if ($object['uid'] != $uid) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error creating contact object: UID doesn't match object URI"),
                true, false);

            throw new DAV\Exception\NotFound("UID doesn't match object URI");
        }

        if ($addressBookId == '__all__') {
            $old = $this->get_card_by_uid($uid, $storage);
        }
        else {
            if ($storage = $this->get_storage_folder($addressBookId))
                $old = $storage->get_object($uid);
        }

        if (!$storage) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Unable to find storage folder for contact $addressBookId/$cardUri"),
                true, false);

            throw new DAV\Exception\NotFound("Invalid address book URI");
        }

        if (!$this->is_writeable($storage)) {
            throw new DAV\Exception\Forbidden('Insufficient privileges to update this card');
        }

        // copy meta data (starting with _) from old object
        foreach ((array)$old as $key => $val) {
            if (!isset($object[$key]) && $key[0] == '_')
                $object[$key] = $val;
        }

        // save object
        $saved = $storage->save($object, $object['_type'], $uid);
        if (!$saved) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Error saving contact object to Kolab server"),
                true, false);

            Plugin::$redirect_basename = null;
            throw new DAV\Exception('Error saving contact card to backend');
        }

        // return new Etag
        return self::_get_etag($object);
    }

    /**
     * Deletes a card
     *
     * @param mixed $addressBookId
     * @param string $cardUri
     * @return bool
     */
    public function deleteCard($addressBookId, $cardUri)
    {
        console(__METHOD__, $addressBookId, $cardUri);

        $uid = VObjectUtils::uri2uid($cardUri, '.vcf');

        if ($addressBookId == '__all__') {
            $this->get_card_by_uid($uid, $storage);
        }
        else {
            $storage = $this->get_storage_folder($addressBookId);
        }

        if (!$storage || !$this->is_writeable($storage)) {
            throw new DAV\Exception\MethodNotAllowed('Insufficient privileges to delete this card');
        }

        if ($storage) {
            return $storage->delete($uid);
        }

        return false;
    }

    /**
     * Set User-Agent string of the connected client
     */
    public function setUserAgent($uastring)
    {
        $ua_classes = array(
            'thunderbird' => 'Thunderbird/\d',
            'macosx'      => '(Mac OS X/.+)?AddressBook/\d(.+\sCardDAVPlugin)?',
            'ios'         => '(iOS/\d|[Dd]ata[Aa]ccessd/\d)',
            'vcard4'      => '[Vv][Cc]ard([/ ])?4',
        );

        foreach ($ua_classes as $class => $regex) {
            if (preg_match("!$regex!", $uastring)) {
                $this->useragent = $class;
                break;
            }
        }
    }

    /**
     * Find an object and the containing folder by UID
     *
     * @param string Object UID
     * @param object Return parameter for the kolab_storage_folder instance
     * @return array|false
     */
    private function get_card_by_uid($uid, &$storage)
    {
        $obj = kolab_storage::get_object($uid, 'contact');
        if ($obj) {
            $storage = kolab_storage::get_folder($obj['_mailbox']);
            return $obj;
        }

        return false;
    }

    /**
     * Internal helper method to determine whether the given kolab_storage_folder is writeable
     *
     */
    private function is_writeable($storage)
    {
        $rights = $storage->get_myrights();
        return (strpos($rights, 'i') !== false || $storage->get_namespace() == 'personal');
    }

    /**
     * Helper method to determine whether the connected client is an Apple device
     */
    private function is_apple()
    {
        return $this->useragent == 'macosx' || $this->useragent == 'ios';
    }

    /**
     * Helper method to determine whether the connected client supports VCard4
     */
    private function is_vcard4()
    {
        return Plugin::$vcard_version == 'vcard4' || $this->useragent == 'vcard4';
    }


    /**********  Data conversion utilities  ***********/

    /**
     * Parse the given VCard string into a hash array kolab_format_contact can handle
     *
     * @param string VCard data block
     * @param string Contact UID
     *
     * @return array Hash array with contact properties or null on failure
     */
    public function parse_vcard($cardData, $uid = null)
    {
        try {
            // use already parsed object
            if (Plugin::$parsed_vcard && Plugin::$parsed_vcard->UID == $uid) {
                $vobject = Plugin::$parsed_vcard;
            }
            else {
                $vobject = VObject\Reader::read($cardData, VObject\Reader::OPTION_FORGIVING | VObject\Reader::OPTION_IGNORE_INVALID_LINES);
            }

            if ($vobject && $vobject->name == 'VCARD') {
                $contact = $this->_to_array($vobject);
                if (!empty($contact['uid'])) {
                    return $contact;
                }
            }
        }
        catch (VObject\ParseException $e) {
            rcube::raise_error(array(
                'code' => 600, 'type' => 'php',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "VCard data parse error: " . $e->getMessage()),
                true, false);
        }

        return null;
    }

    /**
     * Build a valid VCard format block from the given contact record
     *
     * @param array Hash array with contact properties from libkolab
     * @return string VCARD string containing the contact data
     */
    public function to_vcard($contact)
    {
        $v4 = $this->is_vcard4();
        $v4_prefix = $v4 ? '' : 'X-';

        $vc = new VObject\Component\VCard();
        $vc->VERSION = '3.0';  // always set to 3.0 and let Sabre/DAV convert to 4.0 if necessary
        $vc->PRODID = '-//Kolab//iRony DAV Server ' . KOLAB_DAV_VERSION . '//Sabre//Sabre VObject ' . VObject\Version::VERSION . '//EN';

        $vc->add('UID', $contact['uid']);
        $vc->add('FN', $contact['name']);

        // distlists are KIND:group
        if ($contact['_type'] == 'distribution-list') {
            // group cards are actually vcard version 4
            if (!$this->is_apple()) {
                $vc->version = '4.0';
                $prop_prefix = '';
            }
            else {
                // prefix group properties for Apple
                $prop_prefix = 'X-ADDRESSBOOKSERVER-';
            }

            $vc->add($prop_prefix . 'KIND', 'group');

            foreach ((array)$contact['member'] as $member) {
                if ($member['uid'])
                    $value = 'urn:uuid:' . $member['uid'];
                else if ($member['email'] && $member['name'])
                    $value = 'mailto:' . urlencode(sprintf('"%s" <%s>', addcslashes($member['name'], '"'), $member['email']));
                else if ($member['email'])
                    $value = 'mailto:' . $member['email'];
                $vc->add($prop_prefix . 'MEMBER', $value);
            }
        }
        else if ($contact['surname'] . $contact['firstname'] . $contact['middlename'] . $contact['prefix'] . $contact['suffix'] != '') {
            $n = $vc->create('N');
            $n->setParts(array(strval($contact['surname']), strval($contact['firstname']), strval($contact['middlename']), strval($contact['prefix']), strval($contact['suffix'])));
            $vc->add($n);
        }

        if (!empty($contact['nickname']))
            $vc->add('NICKNAME', $contact['nickname']);
        if (!empty($contact['jobtitle']))
            $vc->add('TITLE', $contact['jobtitle']);
        if (!empty($contact['profession']))
            $vc->add('ROLE', $contact['profession']);

        if (!empty($contact['organization']) || !empty($contact['department'])) {
            $org = $vc->create('ORG');
            $org->setParts(array($contact['organization'], $contact['department']));
            $vc->add($org);
        }

        // save as RELATED for VCard 4.0
        if ($v4) {
            foreach ($this->related_map as $type => $field) {
                if (!empty($contact[$field])) {
                    foreach ((array)$contact[$field] as $value) {
                        $vc->add($vc->create('RELATED', $value, array('type' => $type)));
                    }
                }
            }
            if (is_array($contact['related'])) {
                foreach ($contact['related'] as $value) {
                    $vc->add('RELATED', $value);
                }
            }
        }
        else {
            foreach (array_values($this->related_map) as $field) {
                if (!empty($contact[$field])) {
                    $vc->add(strtoupper('X-' . $field), join(',', (array)$contact[$field]));
                }
            }
        }

        foreach ((array)$contact['email'] as $email) {
            $types = array('INTERNET');
            if (!empty($email['type']))
                $types = array_merge($types, explode(',', strtoupper($email['type'])));
            $vc->add('EMAIL', $email['address'], array('type' => $types));
        }

        foreach ((array)$contact['phone'] as $phone) {
            $type = $this->phonetypes[$phone['type']] ?: $phone['type'];
            $params = !empty($type) ? array('type' => explode(',', strtoupper($type))) : array();
            $vc->add('TEL', $phone['number'], $params);
        }

        foreach ((array)$contact['website'] as $website) {
            $params = !empty($website['type']) ? array('type' => explode(',', strtoupper($website['type']))) : array();
            $vc->add('URL', $website['url'], $params);
        }

        $improtocolmap = array_flip($this->improtocols);
        foreach ((array)$contact['im'] as $im) {
            list($prot, $val) = explode(':', $im, 2);
            if ($val && !$v4) $vc->add('x-' . ($improtocolmap[$prot] ?: $prot), $val);
            else              $vc->add('IMPP', $im);
        }

        foreach ((array)$contact['address'] as $adr) {
            $params = !empty($adr['type']) ? array('type' => strtoupper($adr['type'])) : array();
            $vadr = $vc->create('ADR', null, $params);
            $vadr->setParts(array('','', $adr['street'], $adr['locality'], $adr['region'], $adr['code'], $adr['country']));
            $vc->add($vadr);
        }

        if (!empty($contact['notes']))
            $vc->add('NOTE', $contact['notes']);

        if (!empty($contact['gender']))
            $vc->add($this->is_apple() ? 'SEX' : $v4_prefix . 'GENDER', $contact['gender']);

        // convert date cols to DateTime objects
        foreach (array('birthday','anniversary') as $key) {
            if (!empty($contact[$key]) && !$contact[$key] instanceof \DateTime) {
                try {
                    $contact[$key] = new \DateTime(\rcube_utils::clean_datestr($contact[$key]));
                }
                catch (\Exception $e) {
                    $contact[$key] = null;
                }
            }
        }

        if (!empty($contact['birthday']) && $contact['birthday'] instanceof \DateTime) {
            // FIXME: Date values are ignored by Thunderbird
            $contact['birthday']->_dateonly = true;
            $vc->add(VObjectUtils::datetime_prop($vc, 'BDAY', $contact['birthday'], false));
        }
        if (!empty($contact['anniversary']) && $contact['anniversary'] instanceof \DateTime) {
            $contact['anniversary']->_dateonly = true;
            $vc->add(VObjectUtils::datetime_prop($vc, $v4_prefix . 'ANNIVERSARY', $contact['anniversary'], false));
        }

        if (!empty($contact['categories'])) {
            $cat = $vc->create('CATEGORIES');
            $cat->setParts((array)$contact['categories']);
            $vc->add($cat);
        }

        if (!empty($contact['freebusyurl'])) {
            $vc->add('FBURL', $contact['freebusyurl']);
        }

        if (is_array($contact['lang'])) {
            foreach ($contact['lang'] as $value) {
                $vc->add('LANG', $value);
            }
        }

        if (!empty($contact['photo'])) {
            $vc->PHOTO = $contact['photo'];
            $vc->PHOTO['ENCODING'] = 'b';
            $vc->PHOTO['TYPE'] = strtoupper(substr(rcube_mime::image_content_type($contact['photo']), 6));
        }

        // add custom properties
        foreach ((array)$contact['x-custom'] as $prop) {
            $vc->add($prop[0], $prop[1]);
        }

        // send some known fields as itemN.X-AB* for Apple clients
        if ($this->is_apple()) {
            $this->_to_apple($contact, $vc);
        }

        if (!empty($contact['changed']) && is_a($contact['changed'], 'DateTime')) {
            $vc->REV = $contact['changed']->format('Ymd\\THis\\Z');
        }

        return $vc->serialize();
    }

    /**
     * Convert the given Sabre\VObject\Component\Vcard object to a libkolab compatible contact format
     *
     * @param object Vcard object to convert
     * @return array Hash array with contact properties
     */
    private function _to_array($vc)
    {
        $contact = array(
            '_type' => 'contact',
            'uid'  => strval($vc->UID),
            'name' => strval($vc->FN),
            'x-custom' => array(),
        );

        if ($vc->REV) {
            try {
                $contact['changed'] = DateTimeParser::parseDateTime(strval($vc->REV));
            }
            catch (\Exception $e) {
                // ignore
            }
        }

        // normalize apple-style properties
        $this->_from_apple($vc);

        $phonetypemap = array_flip($this->phonetypes);
        $phonetypemap['fax,home'] = 'homefax';
        $phonetypemap['fax,work'] = 'workfax';

        // map attributes to internal fields
        foreach ($vc->children() as $prop) {
            if (!($prop instanceof VObject\Property))
                continue;

            $value = strval($prop);

            switch ($prop->name) {
                case 'N':
                    list($contact['surname'], $contact['firstname'], $contact['middlename'], $contact['prefix'], $contact['suffix']) = $prop->getParts();
                    break;

                case 'NOTE':
                    $contact['notes'] = $value;
                    break;

                case 'TITLE':
                    $contact['jobtitle'] = $value;
                    break;

                case 'NICKNAME':
                    $contact[strtolower($prop->name)] = $value;
                    break;

                case 'ORG':
                    list($contact['organization'], $contact['department']) = $prop->getParts();
                    break;

                case 'CATEGORY':
                case 'CATEGORIES':
                    $contact['categories'] = $prop->getParts();
                    break;

                case 'EMAIL':
                    $types = array_values(self::prop_filter($prop->offsetGet('type'), 'internet,pref', true));
                    $contact['email'][] = array('address' => $value, 'type' => strtolower($types[0] ?: 'other'));
                    break;

                case 'URL':
                    $types = array_values(self::prop_filter($prop->offsetGet('type'), 'internet,pref', true));
                    $contact['website'][] = array('url' => $value, 'type' => strtolower($types[0]));
                    break;

                case 'TEL':
                    $types  = array_values(self::prop_filter($prop->offsetGet('type'), 'voice,pref', true));
                    $types_ = strtolower(join(',', $types));
                    $type = isset($phonetypemap[$types_]) ? $types_ : strtolower($types[0]);
                    $contact['phone'][] = array('number' => $value, 'type' => $phonetypemap[$type] ?: $type);
                    break;

                case 'ADR':
                    $types = array_values(self::prop_filter($prop->offsetGet('type'), 'pref', true));
                    $adr = array('type' => strtolower(!empty($types) ? strval($types[0]) : $prop->parameters[0]->name));
                    list(,, $adr['street'], $adr['locality'], $adr['region'], $adr['code'], $adr['country']) = $prop->getParts();
                    $contact['address'][] = $adr;
                    break;

                case 'BDAY':
                case 'ANNIVERSARY':
                case 'X-ANNIVERSARY':
                    // We use getDateTime() method to support partial date format from vCard 4
                    // and to not throw exceptions on invalid input (T2492)
                    if (method_exists($prop, 'getDateTime')) {
                        $key = $prop->name == 'BDAY' ? 'birthday' : 'anniversary';
                        try {
                            $contact[$key] = $prop->getDateTime();
                            $contact[$key]->_dateonly = true;
                        }
                        catch (\Exception $e) {
                            // ignore
                        }
                    }
                    break;

                case 'SEX':
                case 'GENDER':
                case 'X-GENDER':
                    $contact['gender'] = $value;
                    break;

                case 'ROLE':
                case 'X-PROFESSION':
                    $contact['profession'] = $value;
                    break;

                case 'X-MANAGER':
                case 'X-ASSISTANT':
                case 'X-CHILDREN':
                case 'X-SPOUSE':
                case 'X-MS-MANAGER':
                case 'X-MS-ASSISTANT':
                case 'X-MS-CHILDREN':
                case 'X-MS-SPOUSE':
                    $contact[strtolower(substr($prop->name, 2))] = explode(',', $value);
                    break;

                case 'X-JABBER':
                case 'X-ICQ':
                case 'X-MSN':
                case 'X-AIM':
                case 'X-YAHOO':
                case 'X-SKYPE':
                    $protocol = strtolower(substr($prop->name, 2));
                    $contact['im'][] = ($this->improtocols[$protocol] ?: $protocol) . ':' . preg_replace('/^[a-z]+:/i', '', $value);
                    break;

                case 'IMPP':
                    $prot = null;
                    if (preg_match('/^[a-z]+:/i', $value))
                        list($prot, $val) = explode(':', $value, 2);
                    else
                        $val = $value;
                    $type = strtolower((string)$prop->offsetGet('X-SERVICE-TYPE'));
                    $protocol = $type && (!$prot || $prot == 'aim') ? ($this->improtocols[$type] ?: $type) : $prot;
                    $contact['im'][] = ($this->improtocols[$protocol] ?: $protocol) . ':' . urldecode($val);
                    break;

                case 'PHOTO':
                    if ($prop instanceof VObject\Property\Binary && $value) {
                        $contact['photo'] = $value;
                    }
                    else if ($prop instanceof VObject\Property\Uri && preg_match('|^data:image/[a-z]+;base64,|i', $value, $m)) {
                        $contact['photo'] = base64_decode(substr($value, strlen($m[0])));
                    }
                    break;

                // VCard 4.0 properties

                case 'FBURL':
                    $contact['freebusyurl'] = $value;
                    break;

                case 'LANG':
                    $contact['lang'][] = $value;
                    break;

                case 'RELATED':
                    $type = strtolower($prop->offsetGet('type'));
                    if ($field = $this->related_map[$type]) {
                        $contact[$field][] = $value;
                    }
                    else {
                        $contact['related'][] = $value;
                    }
                    break;

                case 'KIND':
                case 'X-ADDRESSBOOKSERVER-KIND':
                    $value_ = strtolower($value);
                    if ($value_ == 'group') {
                        $contact['_type'] = 'distribution-list';
                    }
                    else if ($value_ == 'org') {
                        // store vcard 4 KIND as custom property
                        $contact['x-custom'][] = array('X-ABSHOWAS', 'COMPANY');
                    }
                    break;

                case 'MEMBER':
                case 'X-ADDRESSBOOKSERVER-MEMBER':
                    if (strpos($value, 'urn:uuid:') === 0) {
                        $contact['member'][] = array('uid' => substr($value, 9));
                    }
                    else if (strpos($value, 'mailto:') === 0) {
                        $member = reset(\rcube_mime::decode_address_list(urldecode(substr($value, 7))));
                        if ($member['mailto'])
                            $contact['member'][] = array('email' => $member['mailto'], 'name' => $member['name']);
                    }
                    break;

                // custom properties
                case 'CUSTOM1':
                case 'CUSTOM2':
                case 'CUSTOM3':
                case 'CUSTOM4':
                default:
                    if (substr($prop->name, 0, 2) == 'X-' || substr($prop->name, 0, 6) == 'CUSTOM') {
                        $prefix = $prop->group ? $prop->group . '.' : '';
                        $contact['x-custom'][] = array($prefix . $prop->name, strval($value));
                    }
                    break;
            }
        }

        if (is_array($contact['im']))
            $contact['im'] = array_unique($contact['im']);

        return $contact;
    }

    /**
     * Convert Apple-style item1.X-AB* properties to flat X-AB*-Label values
     */
    private function _from_apple($vc)
    {
        foreach ($this->xab_labelled_map as $propname => $known_map) {
            foreach ($vc->select($propname) as $prop) {
                $labelkey = $prop->group ? $prop->group . '.X-ABLABEL' : 'X-ABLABEL';
                $labels = $vc->select($labelkey);
                $field = !empty($labels) && ($label = reset($labels)) ? strtolower(trim(strval($label), '_$!<>')) : null;
                if ($field) {
                    $prop->group = null;
                    $prop->name = ($known_map[$field] ?: $propname . '-' . strtoupper($field));
                    unset($vc->{$labelkey});
                }
            }

            // must be an apple client :-)
            $this->useragent = 'macosx';
        }
    }

    /**
     * Translate custom fields back to Apple-style item1.X-AB* properties
     */
    private function _to_apple($contact, $vc)
    {
        $this->item_count = 1;

        foreach ($this->xab_labelled_map as $propname => $known_map) {
            // convert known vcard properties into labelled ones
            foreach (array_flip($known_map) as $name => $label) {
                if ($vc->{$name}) {
                    $this->_replace_with_labelled_prop($vc, $name, $propname, $label);
                }
            }

            // translate custom properties with a matching prefix to labelled items
            foreach ((array)$contact['x-custom'] as $prop) {
                $name = $prop[0];
                if ($vc->{$name} && strpos($name, $propname) === 0) {
                    $label = strtolower(substr($name, strlen($propname)+1));
                    $this->_replace_with_labelled_prop($vc, $name, $propname, $label);
                }
            }
        }
    }

    /**
     * Helper method to replace a named property with a labelled one
     */
    private function _replace_with_labelled_prop($vc, $name, $propname, $label)
    {
        $group = 'item' . ($this->item_count++);
        $prop = clone $vc->{$name};
        $prop->name = $propname;
        $prop->group = $group;
        $vc->add($prop);

        $ablabel = $vc->create('X-ABLabel');
        $ablabel->group = $group;
        $ablabel->setValue(in_array($label, $this->xab_known_labels) ? '_$!<'.ucfirst($label).'>!$_' : ucfirst($label));
        $vc->add($ablabel);

        unset($vc->{$name});
    }

    /**
     * Extract array values by a filter
     *
     * @param array Array to filter
     * @param keys Array or comma separated list of values to keep
     * @param boolean Invert key selection: remove the listed values
     *
     * @return array The filtered array
     */
    private static function prop_filter($arr, $values, $inverse = false)
    {
        if (!is_array($values)) {
            $values = explode(',', $values);
        }

        // explode single, comma-separated value
        if (count($arr) == 1 && strpos($arr[0], ',')) {
            $arr = explode(',', $arr[0]);
        }

        $result = array();
        $keep   = array_flip((array)$values);

        if (!empty($arr)) {
            foreach ($arr as $key => $val) {
                if ($inverse != isset($keep[strtolower($val)])) {
                    $result[$key] = $val;
                }
            }
        }

        return $result;
    }

    /**
     * Generate an Etag string from the given contact data
     *
     * @param array Hash array with contact properties from libkolab
     * @return string Etag string
     */
    private static function _get_etag($contact)
    {
        return sprintf('"%s-%d"', substr(md5($contact['uid']), 0, 16), $contact['_msguid']);
    }
}
