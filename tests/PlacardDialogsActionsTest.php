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
        $taken = 'A room with this name already exists.';

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

            'delete: no id' => array('delete', array(), array(), array(), 400, $noId),
            'delete: valid' => array('delete', array('1'), array(), array(), 200, null),
        );
    }
}
