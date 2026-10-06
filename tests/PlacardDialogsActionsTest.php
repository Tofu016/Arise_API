<?php
// Pins PlacardDialogs_API's guards, including the update rule that a body
// with only search_terms is a valid change even though no field is.
class PlacardDialogsActionsTest extends ActionTestCase
{
    private function nameTaken($taken)
    {
        return array('PlacardDialogs_Model' => array('roomNameExists' => $taken));
    }

    /** @dataProvider replies */
    public function testReplies($action, array $params, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('PlacardDialogsApiHarness', $action, $params, $body, $returns), $status, $error);
    }

    public function replies()
    {
        $noId = 'Missing dialog id.';
        $noFields = 'No valid fields to update.';
        $taken = 'A room or facility with this name already exists.';

        return array(
            'create: empty body' => array('create', array(), array(), $this->nameTaken(false), 400, 'room_name is required.'),
            'create: blank room_name' => array('create', array(), array('room_name' => '  '), $this->nameTaken(false), 400, 'room_name is required.'),
            'create: name already used' => array('create', array(), array('room_name' => 'Canteen'), $this->nameTaken(true), 409, $taken),
            'create: valid' => array('create', array(), array('room_name' => 'Canteen'), $this->nameTaken(false), 200, null),

            'update: no id' => array('update', array(), array('description' => 'd'), array(), 400, $noId),
            'update: id "0" counts as missing' => array('update', array('0'), array('description' => 'd'), array(), 400, $noId),
            'update: blank room_name' => array('update', array('1'), array('room_name' => ' '), $this->nameTaken(false), 400, 'room_name cannot be blank.'),
            'update: name used by another' => array('update', array('1'), array('room_name' => 'Canteen'), $this->nameTaken(true), 409, $taken),
            'update: empty body' => array('update', array('1'), array(), $this->nameTaken(false), 400, $noFields),
            'update: only unknown fields' => array('update', array('1'), array('bogus' => 1), $this->nameTaken(false), 400, $noFields),
            'update: search_terms alone is a change' => array('update', array('1'), array('search_terms' => array('a')), $this->nameTaken(false), 200, null),
            'update: search_terms that is not a list is no change' => array('update', array('1'), array('search_terms' => 'a'), $this->nameTaken(false), 400, $noFields),
            'update: valid field' => array('update', array('1'), array('description' => 'd'), $this->nameTaken(false), 200, null),

            'update: OCR fields alone are a change' => array('update', array('1'), array('ocr_enabled' => true), $this->nameTaken(false), 200, null),
            'update: extra_search_terms alone is a change' => array('update', array('1'), array('extra_search_terms' => array('x')), $this->nameTaken(false), 200, null),
            'update: placard_name too long' => array('update', array('1'), array('placard_name' => str_repeat('a', 256)), $this->nameTaken(false), 400, 'placard_name is too long.'),

            'delete: no id' => array('delete', array(), array(), array(), 400, $noId),
            'delete: valid' => array('delete', array('1'), array(), array(), 200, null),

            'saveOcr: no rooms' => array('saveOcr', array(), array(), array(), 400, 'rooms is required.'),
            'saveOcr: rooms not a list' => array('saveOcr', array(), array('rooms' => 'x'), array(), 400, 'rooms is required.'),
            'saveOcr: a room without a name' => array('saveOcr', array(), array('rooms' => array(array('ocr_enabled' => 1))), array(), 400, 'Each room needs a room_name.'),
            'saveOcr: too many rooms' => array('saveOcr', array(), array('rooms' => array_fill(0, 2001, array('room_name' => 'A'))), array(), 400, 'Too many rooms in one save.'),
            'saveOcr: transaction failed' => array('saveOcr', array(), array('rooms' => array(array('room_name' => 'A'))), array('PlacardDialogs_Model' => array('saveOcr' => false)), 500, "Couldn't save the OCR settings."),
            'saveOcr: valid' => array('saveOcr', array(), array('rooms' => array(array('room_name' => 'A'))), array('PlacardDialogs_Model' => array('saveOcr' => true, 'getAll' => array())), 200, null),
            'saveOcr: only the scanner message' => array('saveOcr', array(), array('scanner_message' => 'Hi'), array('PlacardDialogs_Model' => array('saveOcr' => true, 'getAll' => array())), 200, null),
            'saveOcr: scanner message too long' => array('saveOcr', array(), array('scanner_message' => str_repeat('a', 301)), array(), 400, 'scanner_message is too long.'),
            'saveOcr: an AR 360 image path too long' => array('saveOcr', array(), array('rooms' => array(array('room_name' => 'A', 'ocr_photos' => array(str_repeat('a', 501))))), array(), 400, 'An ocr_photos path is too long.'),
            'saveOcr: AR 360 images not a list' => array('saveOcr', array(), array('rooms' => array(array('room_name' => 'A', 'ocr_photos' => 'a.webp'))), array(), 400, 'ocr_photos must be a list of photo paths.'),
            'saveOcr: an AR 360 image not a path' => array('saveOcr', array(), array('rooms' => array(array('room_name' => 'A', 'ocr_photos' => array(array('x'))))), array(), 400, 'ocr_photos must be a list of photo paths.'),
            'saveOcr: too many AR 360 images' => array('saveOcr', array(), array('rooms' => array(array('room_name' => 'A', 'ocr_photos' => array_map(function ($i) { return "room360/a/$i.webp"; }, range(1, 21))))), array(), 400, 'A room can have at most 20 AR 360 images.'),
            'getOcrSettings: public' => array('getOcrSettings', array(), array(), array('PlacardDialogs_Model' => array('getOcrSettings' => array('scanner_message' => null))), 200, null),
        );
    }

    public function testSaveOcrRequiresAnAdmin()
    {
        $reply = $this->call('PlacardDialogsApiHarness', 'saveOcr', array(), array('rooms' => array(array('room_name' => 'A'))), array(), null);
        $this->assertSame(401, $reply->status());
    }

    // What reaches the model: names trimmed, flags as 0/1, a blank Placard
    // name as none, missing term lists as empty.
    public function testSaveOcrCleansEachRow()
    {
        $this->call('PlacardDialogsApiHarness', 'saveOcr', array(), array('rooms' => array(
            array('room_name' => ' GD1-101 ', 'ocr_enabled' => true, 'placard_name' => ' GD1-101 ', 'search_terms' => array('gd1-101', 'gd1101'), 'extra_search_terms' => array('rm101')),
            array('room_name' => 'Canteen', 'ocr_enabled' => false, 'placard_name' => '  '),
        )), array('PlacardDialogs_Model' => array('saveOcr' => true, 'getAll' => array())));

        $calls = $this->controller->PlacardDialogs_Model->calls;
        $this->assertSame('saveOcr', $calls[0][0]);
        $this->assertSame(array(
            array('room_name' => 'GD1-101', 'ocr_enabled' => 1, 'placard_name' => 'GD1-101', 'search_terms' => array('gd1-101', 'gd1101'), 'extra_search_terms' => array('rm101')),
            array('room_name' => 'Canteen', 'ocr_enabled' => 0, 'placard_name' => null, 'search_terms' => array(), 'extra_search_terms' => array()),
        ), $calls[0][1][0]);
    }

    // ocr_photos reaches the model only when sent (an older admin build
    // doesn't send it, which must not clear it), trimmed, without blanks or
    // repeats; scanner_message is trimmed, blank as none, and left out
    // entirely when not sent.
    public function testSaveOcrPassesTheOcrImageAndMessage()
    {
        $this->call('PlacardDialogsApiHarness', 'saveOcr', array(), array('scanner_message' => '  Look around!  ', 'rooms' => array(
            array('room_name' => 'Lab', 'ocr_photos' => array(' room360/gd1/lab.webp ', 'room360/gd1/lab2.webp', '', 'room360/gd1/lab.webp')),
            array('room_name' => 'Canteen', 'ocr_photos' => array()),
            array('room_name' => 'Library'),
        )), array('PlacardDialogs_Model' => array('saveOcr' => true, 'getAll' => array())));

        $args = $this->controller->PlacardDialogs_Model->calls[0][1];
        $this->assertSame(array('room360/gd1/lab.webp', 'room360/gd1/lab2.webp'), $args[0][0]['ocr_photos']);
        $this->assertSame(array(), $args[0][1]['ocr_photos']);
        $this->assertArrayNotHasKey('ocr_photos', $args[0][2]);
        $this->assertSame(array('scanner_message' => 'Look around!'), $args[1]);

        $this->call('PlacardDialogsApiHarness', 'saveOcr', array(), array('scanner_message' => '   '), array('PlacardDialogs_Model' => array('saveOcr' => true, 'getAll' => array())));
        $this->assertSame(array('scanner_message' => null), $this->controller->PlacardDialogs_Model->calls[0][1][1]);

        $this->call('PlacardDialogsApiHarness', 'saveOcr', array(), array('rooms' => array(array('room_name' => 'A'))), array('PlacardDialogs_Model' => array('saveOcr' => true, 'getAll' => array())));
        $this->assertNull($this->controller->PlacardDialogs_Model->calls[0][1][1], 'no scanner_message sent, settings left as they are');
    }

    public function testUpdatePassesOcrFieldsAndExtraTermsToTheModel()
    {
        $this->call('PlacardDialogsApiHarness', 'update', array('1'), array('ocr_enabled' => 0, 'placard_name' => 'Lab', 'extra_search_terms' => array('lab-a')), $this->nameTaken(false));

        $call = $this->controller->PlacardDialogs_Model->calls[0];
        $this->assertSame('update', $call[0]);
        $this->assertSame(array('ocr_enabled' => 0, 'placard_name' => 'Lab'), $call[1][1]);
        $this->assertNull($call[1][2], 'search_terms not sent, so left untouched');
        $this->assertSame(array('lab-a'), $call[1][4]);
    }
}
