<?php
// Pins SavedRooms_API: approved accounts only, the caller's own list only,
// the per-account limit, and saving/removing twice being harmless.
class SavedRoomsActionsTest extends ActionTestCase
{
    const APPROVED = array('id' => '7', 'role' => 'user');
    const PENDING = array('id' => '8', 'role' => 'pending');

    private function returns($roomExists, $alreadySaved = false, $count = 0)
    {
        return array(
            'PlacardDialogs_Model' => array('find' => $roomExists ? array('id' => '3', 'room_name' => 'Drawing Room') : null),
            'SavedRooms_Model' => array('isSaved' => $alreadySaved, 'countForUser' => $count, 'getForUser' => array()),
        );
    }

    private function callAs($user, $action, array $params = array(), array $body = array(), array $returns = array())
    {
        return $this->call('SavedRoomsApiHarness', $action, $params, $body, $returns ?: $this->returns(true), $user);
    }

    /** @dataProvider replies */
    public function testReplies($user, $action, array $params, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->callAs($user, $action, $params, $body, $returns), $status, $error);
    }

    public function replies()
    {
        $required = 'placard_dialog_id is required.';
        $full = 'You can save up to 20 rooms. Remove one to save another.';

        return array(
            'getMine: anonymous' => array(null, 'getMine', array(), array(), array(), 401, 'Not signed in.'),
            'getMine: pending account' => array(self::PENDING, 'getMine', array(), array(), array(), 403, 'Your account is waiting for approval.'),
            'getMine: approved' => array(self::APPROVED, 'getMine', array(), array(), array(), 200, null),
            'getMine: admin' => array(array('id' => '1', 'role' => 'admin'), 'getMine', array(), array(), array(), 200, null),

            'save: anonymous' => array(null, 'save', array(), array('placard_dialog_id' => 3), array(), 401, 'Not signed in.'),
            'save: pending account' => array(self::PENDING, 'save', array(), array('placard_dialog_id' => 3), array(), 403, 'Your account is waiting for approval.'),
            'save: no id' => array(self::APPROVED, 'save', array(), array(), array(), 400, $required),
            'save: id 0' => array(self::APPROVED, 'save', array(), array('placard_dialog_id' => 0), array(), 400, $required),
            'save: negative id' => array(self::APPROVED, 'save', array(), array('placard_dialog_id' => -3), array(), 400, $required),
            'save: not a number' => array(self::APPROVED, 'save', array(), array('placard_dialog_id' => 'abc'), array(), 400, $required),
            'save: room does not exist' => array(self::APPROVED, 'save', array(), array('placard_dialog_id' => 99), $this->returns(false), 404, 'Room not found.'),
            'save: list full' => array(self::APPROVED, 'save', array(), array('placard_dialog_id' => 3), $this->returns(true, false, 20), 409, $full),
            'save: already saved, even with a full list' => array(self::APPROVED, 'save', array(), array('placard_dialog_id' => 3), $this->returns(true, true, 20), 200, null),
            'save: one under the limit' => array(self::APPROVED, 'save', array(), array('placard_dialog_id' => 3), $this->returns(true, false, 19), 200, null),
            'save: id as a numeric string' => array(self::APPROVED, 'save', array(), array('placard_dialog_id' => '3'), array(), 200, null),

            'remove: anonymous' => array(null, 'remove', array('3'), array(), array(), 401, 'Not signed in.'),
            'remove: pending account' => array(self::PENDING, 'remove', array('3'), array(), array(), 403, 'Your account is waiting for approval.'),
            'remove: no id' => array(self::APPROVED, 'remove', array(), array(), array(), 400, 'Missing room id.'),
            'remove: valid' => array(self::APPROVED, 'remove', array('3'), array(), array(), 200, null),
        );
    }

    public function testGetMineReadsOnlyTheCallersList()
    {
        $this->callAs(self::APPROVED, 'getMine');
        $this->assertContains(array('getForUser', array('7')), $this->controller->SavedRooms_Model->calls);
    }

    public function testGetMineReportsTheLimit()
    {
        $reply = $this->callAs(self::APPROVED, 'getMine');
        $this->assertSame(20, $reply->body()['limit']);
    }

    public function testSaveStoresTheRoomForTheCaller()
    {
        $this->callAs(self::APPROVED, 'save', array(), array('placard_dialog_id' => '3'));
        $this->assertContains(array('save', array('7', 3)), $this->controller->SavedRooms_Model->calls);
    }

    public function testSavingAnAlreadySavedRoomStoresNothing()
    {
        $this->callAs(self::APPROVED, 'save', array(), array('placard_dialog_id' => 3), $this->returns(true, true));
        $methods = array_column($this->controller->SavedRooms_Model->calls, 0);
        $this->assertNotContains('save', $methods);
    }

    public function testAFullListStoresNothing()
    {
        $this->callAs(self::APPROVED, 'save', array(), array('placard_dialog_id' => 3), $this->returns(true, false, 20));
        $methods = array_column($this->controller->SavedRooms_Model->calls, 0);
        $this->assertNotContains('save', $methods);
    }

    public function testRemoveOnlyTouchesTheCallersList()
    {
        $this->callAs(self::APPROVED, 'remove', array('3'));
        $this->assertContains(array('remove', array('7', 3)), $this->controller->SavedRooms_Model->calls);
    }
}
