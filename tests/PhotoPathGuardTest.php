<?php
// A record's photo_path must be a Photo path the Photo store produced, not
// a guessed file name: the web admin once auto-filled "<id>.jpg" into it,
// saving nodes that pointed at nothing and left the viewer stuck on its
// loading screen. Pins the shape check itself, and that Nodes_API applies
// it on create and on a *changed* photo only.
class PhotoPathGuardTest extends ActionTestCase
{
    /** @dataProvider shapes */
    public function testIsPhotoPath($path, $category, $expected)
    {
        $this->assertSame($expected, Photo_store::isPhotoPath($path, $category));
    }

    public function shapes()
    {
        return array(
            'flat, valid' => array('signage/slide_01.webp', 'signage', true),
            'per-building, valid' => array('panoramas/gd1/gd1_f1_hall01.webp', 'panoramas', true),
            'bare file name' => array('slide_01.jpg', 'signage', false),
            'other category' => array('room360/gd1/a.webp', 'signage', false),
            'flat category given a building' => array('signage/gd1/slide.webp', 'signage', false),
            'per-building missing the building' => array('panoramas/stop.webp', 'panoramas', false),
            'traversal' => array('panoramas/../x.webp', 'panoramas', false),
            'unsafe characters' => array('signage/a b.webp', 'signage', false),
            'empty file name' => array('signage/', 'signage', false),
            'unknown category' => array('whatever/a.webp', 'whatever', false),
            'not a string' => array(array('signage/a.webp'), 'signage', false),
        );
    }

    /** @dataProvider replies */
    public function testReplies($harness, $action, array $params, array $body, array $returns, $status)
    {
        $reply = $this->call($harness, $action, $params, $body, $returns);
        $this->assertReply($reply, $status, $status === 400 ? $reply->body()['error'] : null);
        if ($status === 400) {
            $this->assertMatchesRegularExpression('/^(cover_)?photo_path must be an uploaded photo/', $reply->body()['error']);
        }
    }

    public function replies()
    {
        $nodes = function (array $returns = array()) {
            return array('Nodes_Model' => $returns, 'Buildings_Model' => array('find' => true), 'Elevators_Model' => array());
        };
        $node = array('name' => 'Hall', 'building' => 'gd1', 'floor' => 1, 'type' => 'hallway');
        $storedNode = array('find' => array('id' => 'a', 'photo_path' => 'legacy_a.jpg'));

        return array(
            'node create: guessed name refused' => array('NodesApiHarness', 'create', array(), $node + array('photo_path' => 'gd1_f1_hallway01.jpg'), $nodes(), 400),
            'node create: photo from another category refused' => array('NodesApiHarness', 'create', array(), $node + array('photo_path' => 'signage/x.webp'), $nodes(), 400),
            'node create: uploaded panorama' => array('NodesApiHarness', 'create', array(), $node + array('photo_path' => 'panoramas/gd1/h.webp'), $nodes(), 200),
            'node update: changed to a guessed name' => array('NodesApiHarness', 'update', array('a'), array('photo_path' => 'a.jpg'), $nodes($storedNode), 400),
            'node update: unchanged legacy path still saves' => array('NodesApiHarness', 'update', array('a'), array('name' => 'X', 'photo_path' => 'legacy_a.jpg'), $nodes($storedNode), 200),
            'node update: changed to an upload' => array('NodesApiHarness', 'update', array('a'), array('photo_path' => 'panoramas/gd1/a.webp'), $nodes($storedNode), 200),
        );
    }
}
