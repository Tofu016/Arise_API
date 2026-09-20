<?php
// Pins TourSections_API's guards.
class TourSectionsActionsTest extends ActionTestCase
{
    /** @dataProvider replies */
    public function testReplies($action, array $params, array $body, $status, $error)
    {
        $this->assertReply($this->call('TourSectionsApiHarness', $action, $params, $body), $status, $error);
    }

    public function replies()
    {
        $noId = 'Missing section id.';

        return array(
            'create: empty body' => array('create', array(), array(), 400, 'Section name is required.'),
            'create: blank label' => array('create', array(), array('label' => '  '), 400, 'Section name is required.'),
            'create: valid' => array('create', array(), array('label' => 'Lobby'), 200, null),

            'update: no id' => array('update', array(), array('label' => 'X'), 400, $noId),
            'update: id "0" counts as missing' => array('update', array('0'), array('label' => 'X'), 400, $noId),
            'update: empty body' => array('update', array('1'), array(), 400, 'No valid fields to update.'),
            'update: only unknown fields' => array('update', array('1'), array('bogus' => 1), 400, 'No valid fields to update.'),
            'update: valid' => array('update', array('1'), array('label' => 'X'), 200, null),
            'update: cover path alone is enough' => array('update', array('1'), array('cover_photo_path' => 'tourcover/a.jpg'), 200, null),

            'delete: no id' => array('delete', array(), array(), 400, $noId),
            'delete: valid' => array('delete', array('1'), array(), 200, null),
        );
    }
}
