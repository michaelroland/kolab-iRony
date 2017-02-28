<?php

use Kolab\CardDAV\ContactsBackend;

class ContactsBackendTest extends PHPUnit_Framework_TestCase
{
    function setUp()
    {
    }


    /**
     * Test vCard PHOTO (T1082)
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

    /**
     * Test vCard PHOTO (T2043)
     *
     * @dataProvider data_T2043
     */
    function test_T2043($input, $output, $version)
    {
        $backend = new ContactsBackend;
        $vcard   = "BEGIN:VCARD\nVERSION:$version\nN:Thompson;Default;;;\nUID:1\n$input\nEND:VCARD";
        $contact = $backend->parse_vcard($vcard);

        $this->assertSame($output, $contact['photo']);
    }

    function data_T2043()
    {
        return array(
            array('PHOTO;JPEG;ENCODING=BASE64:' . base64_encode('abc'), 'abc', '2.1'),
            array('PHOTO;TYPE=JPEG;ENCODING=b:' . base64_encode('abc'), 'abc', '3.0'),
            array('PHOTO:data:image/jpeg;base64,'. base64_encode('abc'), 'abc', '4.0'),
            // As we do not support photo URLs yet we expect NULL here
            //array('PHOTO;JPEG:http://example.com/photo.jpg', null, '2.1'), // invalid?
            array('PHOTO;TYPE=JPEG;VALUE=URI:http://example.com/photo.jpg', null, '3.0'),
            array('PHOTO;MEDIATYPE=image/jpeg:http://example.com/photo.jpg', null, '4.0'),
        );
    }
}
