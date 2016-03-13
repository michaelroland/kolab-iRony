<?php

use Kolab\CardDAV\ContactsBackend;

class ContactsBackendTest extends PHPUnit_Framework_TestCase
{
    function setUp()
    {
    }


    /**
     */
    function test_T1082()
    {
        $backend = new ContactsBackend;
        $contact = array(
            'uid'   => '',
            'name'  => 'Test',
            'photo' => base64_decode('R0lGODlhDwAPAIAAAMDAwAAAACH5BAEAAAAALAAAAAAPAA8AQAINhI+py+0Po5y02otnAQA7'),
        );

        $vcard = $backend->to_vcard($contact);

        $this->assertRegexp('/PHOTO;ENCODING=b;TYPE=GIF:R0l/', $vcard);
    }
}
