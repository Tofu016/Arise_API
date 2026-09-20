<?php
// Pins Buildings_API's guards: which input each action accepts, and the
// exact status and message it replies with when it refuses.
class BuildingsActionsTest extends ActionTestCase
{
    /** @dataProvider replies */
    public function testReplies($action, array $params, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('BuildingsApiHarness', $action, $params, $body, $returns), $status, $error);
    }

    public function replies()
    {
        $name = 'Building name is required.';
        $floors = 'Floor count must be a positive number.';
        $noId = 'Missing building id.';
        $noFields = 'No valid fields to update.';

        return array(
            'create: empty body' => array('create', array(), array(), array(), 400, $name),
            'create: blank name' => array('create', array(), array('name' => '   ', 'floor_count' => 2), array(), 400, $name),
            'create: no floor count' => array('create', array(), array('name' => 'A'), array(), 400, $floors),
            'create: zero floors' => array('create', array(), array('name' => 'A', 'floor_count' => 0), array(), 400, $floors),
            'create: non-numeric floors' => array('create', array(), array('name' => 'A', 'floor_count' => 'x'), array(), 400, $floors),
            'create: valid' => array('create', array(), array('name' => 'A', 'floor_count' => 3), array(), 200, null),

            'update: no id' => array('update', array(), array('name' => 'X'), array(), 400, $noId),
            'update: empty-string id' => array('update', array(''), array('name' => 'X'), array(), 400, $noId),
            'update: id "0" counts as missing' => array('update', array('0'), array('name' => 'X'), array(), 400, $noId),
            'update: empty body' => array('update', array('gd1'), array(), array(), 400, $noFields),
            'update: only unknown fields' => array('update', array('gd1'), array('bogus' => 1), array(), 400, $noFields),
            'update: valid' => array('update', array('gd1'), array('name' => 'X'), array(), 200, null),

            'delete: no id' => array('delete', array(), array(), array(), 400, $noId),
            'delete: still has nodes' => array('delete', array('gd1'), array(), array('Buildings_Model' => array('hasNodes' => true)), 409,
                'This building still has nodes assigned to it. Reassign or delete those nodes first.'),
            'delete: valid' => array('delete', array('gd1'), array(), array('Buildings_Model' => array('hasNodes' => false)), 200, null),
        );
    }

    public function testUpdateOnlyPassesWhitelistedFieldsToTheModel()
    {
        $this->call('BuildingsApiHarness', 'update', array('gd1'), array('name' => 'X', 'lat' => 1, 'id' => 'hijack', 'bogus' => 2));

        $this->assertSame(array('name' => 'X', 'lat' => 1), $this->controller->Buildings_Model->calls[0][1][1]);
    }
}
