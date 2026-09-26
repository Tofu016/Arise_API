<?php
// A record's photo_path must be a Photo path the Photo store produced, not
// a guessed file name: the web admin once auto-filled "<id>.jpg" into it,
// saving stops and nodes that pointed at nothing and left the public tour
// stuck on its loading screen. Pins the shape check itself, and that
// TourStops_API/Nodes_API apply it on create and on a *changed* photo only.
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
            'flat, valid' => array('tourpanorama/stop_01.webp', 'tourpanorama', true),
            'per-building, valid' => array('panoramas/gd1/gd1_f1_hall01.webp', 'panoramas', true),
            'bare file name' => array('tour_test01.jpg', 'tourpanorama', false),
            'other category' => array('tourcover/stop_01.webp', 'tourpanorama', false),
            'flat category given a building' => array('tourpanorama/gd1/stop.webp', 'tourpanorama', false),
            'per-building missing the building' => array('panoramas/stop.webp', 'panoramas', false),
            'traversal' => array('panoramas/../x.webp', 'panoramas', false),
            'unsafe characters' => array('tourpanorama/a b.webp', 'tourpanorama', false),
            'empty file name' => array('tourpanorama/', 'tourpanorama', false),
            'unknown category' => array('whatever/a.webp', 'whatever', false),
            'not a string' => array(array('tourpanorama/a.webp'), 'tourpanorama', false),
        );
    }

    /** @dataProvider replies */
    public function testReplies($harness, $action, array $params, array $body, array $returns, $status)
    {
        $reply = $this->call($harness, $action, $params, $body, $returns);
        $this->assertReply($reply, $status, $status === 400 ? $reply->body()['error'] : null);
        if ($status === 400) {
            $this->assertStringContainsString('photo_path must be an uploaded photo', $reply->body()['error']);
        }
    }

    public function replies()
    {
        $stops = function (array $returns = array()) {
            return array('TourStops_Model' => $returns);
        };
        $nodes = function (array $returns = array()) {
            return array('Nodes_Model' => $returns, 'Buildings_Model' => array('find' => true), 'Elevators_Model' => array());
        };
        $node = array('name' => 'Hall', 'building' => 'gd1', 'floor' => 1, 'type' => 'hallway');
        $storedStop = array('find' => array('id' => 'a', 'photo_path' => 'legacy_a.jpg'));
        $storedNode = array('find' => array('id' => 'a', 'photo_path' => 'legacy_a.jpg'));

        return array(
            'stop create: guessed name refused' => array('TourStopsApiHarness', 'create', array(), array('name' => 'S', 'photo_path' => 'tour_test01.jpg'), $stops(), 400),
            'stop create: uploaded photo' => array('TourStopsApiHarness', 'create', array(), array('name' => 'S', 'photo_path' => 'tourpanorama/s.webp'), $stops(), 200),
            'stop create: no photo' => array('TourStopsApiHarness', 'create', array(), array('name' => 'S'), $stops(), 200),
            'stop create: empty photo' => array('TourStopsApiHarness', 'create', array(), array('name' => 'S', 'photo_path' => ''), $stops(), 200),
            'stop update: changed to a guessed name' => array('TourStopsApiHarness', 'update', array('a'), array('photo_path' => 'a.jpg'), $stops($storedStop), 400),
            'stop update: changed to an upload' => array('TourStopsApiHarness', 'update', array('a'), array('photo_path' => 'tourpanorama/a.webp'), $stops($storedStop), 200),
            'stop update: unchanged legacy path still saves' => array('TourStopsApiHarness', 'update', array('a'), array('name' => 'X', 'photo_path' => 'legacy_a.jpg'), $stops($storedStop), 200),
            'stop update: cleared' => array('TourStopsApiHarness', 'update', array('a'), array('photo_path' => ''), $stops($storedStop), 200),

            'node create: guessed name refused' => array('NodesApiHarness', 'create', array(), $node + array('photo_path' => 'gd1_f1_hallway01.jpg'), $nodes(), 400),
            'node create: tour photo refused' => array('NodesApiHarness', 'create', array(), $node + array('photo_path' => 'tourpanorama/x.webp'), $nodes(), 400),
            'node create: uploaded panorama' => array('NodesApiHarness', 'create', array(), $node + array('photo_path' => 'panoramas/gd1/h.webp'), $nodes(), 200),
            'node update: changed to a guessed name' => array('NodesApiHarness', 'update', array('a'), array('photo_path' => 'a.jpg'), $nodes($storedNode), 400),
            'node update: unchanged legacy path still saves' => array('NodesApiHarness', 'update', array('a'), array('name' => 'X', 'photo_path' => 'legacy_a.jpg'), $nodes($storedNode), 200),
            'node update: changed to an upload' => array('NodesApiHarness', 'update', array('a'), array('photo_path' => 'panoramas/gd1/a.webp'), $nodes($storedNode), 200),
        );
    }
}
