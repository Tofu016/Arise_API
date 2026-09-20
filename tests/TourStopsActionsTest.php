<?php
// Pins TourStops_API's guards, including the two update rules that a body
// with only `photos` is a valid marker change, and that an empty-string
// section_id is stored as NULL.
class TourStopsActionsTest extends ActionTestCase
{
    private function stops(array $returns = array())
    {
        return array('TourStops_Model' => $returns);
    }

    /** @dataProvider replies */
    public function testReplies($action, array $params, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('TourStopsApiHarness', $action, $params, $body, $returns), $status, $error);
    }

    public function replies()
    {
        $noFields = 'No valid fields to update.';
        $bothIds = 'Both the current and new id are required.';
        $neighbor = array('stop_id' => 'a', 'neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2, 'reverse_yaw' => 3, 'reverse_pitch' => 4);
        $marker = array('stop_id' => 'a', 'label' => 'L', 'yaw' => 1, 'pitch' => 2);

        return array(
            'create: empty body' => array('create', array(), array(), $this->stops(), 400, 'Stop name is required.'),
            'create: blank name' => array('create', array(), array('name' => '  '), $this->stops(), 400, 'Stop name is required.'),
            'create: requested id taken' => array('create', array(), array('name' => 'S', 'id' => 'x'), $this->stops(array('idExists' => true)), 409, "ID 'x' is already used by another stop."),
            'create: valid' => array('create', array(), array('name' => 'S'), $this->stops(), 200, null),

            'rename: no new id' => array('rename', array('a'), array(), $this->stops(), 400, $bothIds),
            'rename: no old id' => array('rename', array(), array('new_id' => 'b'), $this->stops(), 400, $bothIds),
            'rename: new id taken' => array('rename', array('a'), array('new_id' => 'b'), $this->stops(array('idExists' => true)), 409, "ID 'b' is already used by another stop."),
            'rename: valid' => array('rename', array('a'), array('new_id' => 'b'), $this->stops(array('idExists' => false)), 200, null),

            'update: no id' => array('update', array(), array('name' => 'X'), $this->stops(), 400, 'Missing stop id.'),
            'update: id "0" counts as missing' => array('update', array('0'), array('name' => 'X'), $this->stops(), 400, 'Missing stop id.'),
            'update: empty body' => array('update', array('a'), array(), $this->stops(), 400, $noFields),
            'update: only unknown fields' => array('update', array('a'), array('bogus' => 1), $this->stops(), 400, $noFields),
            'update: valid' => array('update', array('a'), array('name' => 'X'), $this->stops(), 200, null),
            'update: a blank section_id alone is still a change' => array('update', array('a'), array('section_id' => ''), $this->stops(), 200, null),

            'delete: no id' => array('delete', array(), array(), $this->stops(), 400, 'Missing stop id.'),
            'delete: valid' => array('delete', array('a'), array(), $this->stops(), 200, null),

            'addNeighbor: no stop_id' => array('addNeighbor', array(), array_diff_key($neighbor, array('stop_id' => 1)), $this->stops(), 400, 'Missing field: stop_id'),
            'addNeighbor: no yaw' => array('addNeighbor', array(), array_diff_key($neighbor, array('yaw' => 1)), $this->stops(), 400, 'Missing field: yaw'),
            'addNeighbor: no reverse_pitch' => array('addNeighbor', array(), array_diff_key($neighbor, array('reverse_pitch' => 1)), $this->stops(), 400, 'Missing field: reverse_pitch'),
            'addNeighbor: first missing wins' => array('addNeighbor', array(), array('stop_id' => 'a'), $this->stops(), 400, 'Missing field: neighbor_id'),
            'addNeighbor: null counts as missing' => array('addNeighbor', array(), array_merge($neighbor, array('pitch' => null)), $this->stops(), 400, 'Missing field: pitch'),
            'addNeighbor: empty string counts as present' => array('addNeighbor', array(), array_merge($neighbor, array('pitch' => '')), $this->stops(), 200, null),
            'addNeighbor: valid' => array('addNeighbor', array(), $neighbor, $this->stops(), 200, null),

            'removeNeighbor: empty body' => array('removeNeighbor', array(), array(), $this->stops(), 400, 'stop_id and neighbor_id are both required.'),
            'removeNeighbor: stop_id "0" counts as missing' => array('removeNeighbor', array(), array('stop_id' => '0', 'neighbor_id' => 'b'), $this->stops(), 400, 'stop_id and neighbor_id are both required.'),
            'removeNeighbor: valid' => array('removeNeighbor', array(), array('stop_id' => 'a', 'neighbor_id' => 'b'), $this->stops(), 200, null),

            'updateNeighborAngle: no stop_id' => array('updateNeighborAngle', array(), array('neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2), $this->stops(), 400, 'Missing field: stop_id'),
            'updateNeighborAngle: no pitch' => array('updateNeighborAngle', array(), array('stop_id' => 'a', 'neighbor_id' => 'b', 'yaw' => 1), $this->stops(), 400, 'Missing field: pitch'),
            'updateNeighborAngle: valid' => array('updateNeighborAngle', array(), array('stop_id' => 'a', 'neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2), $this->stops(), 200, null),

            'addMarker: no stop_id' => array('addMarker', array(), array_diff_key($marker, array('stop_id' => 1)), $this->stops(), 400, 'Missing field: stop_id'),
            'addMarker: no label' => array('addMarker', array(), array_diff_key($marker, array('label' => 1)), $this->stops(), 400, 'Missing field: label'),
            'addMarker: valid' => array('addMarker', array(), $marker, $this->stops(), 200, null),
            'addMarker: valid with photos' => array('addMarker', array(), array_merge($marker, array('photos' => array('tourmarker/a.jpg'))), $this->stops(), 200, null),

            'updateMarker: no id' => array('updateMarker', array(), array('label' => 'x'), $this->stops(), 400, 'Missing marker id.'),
            'updateMarker: empty body' => array('updateMarker', array('7'), array(), $this->stops(), 400, $noFields),
            'updateMarker: only unknown fields' => array('updateMarker', array('7'), array('bogus' => 1), $this->stops(), 400, $noFields),
            'updateMarker: photos that are not a list are no change' => array('updateMarker', array('7'), array('photos' => 'x'), $this->stops(), 400, $noFields),
            'updateMarker: valid field' => array('updateMarker', array('7'), array('label' => 'x'), $this->stops(), 200, null),
            'updateMarker: photos alone is a change' => array('updateMarker', array('7'), array('photos' => array('tourmarker/a.jpg')), $this->stops(), 200, null),
            'updateMarker: an empty photos list clears them' => array('updateMarker', array('7'), array('photos' => array()), $this->stops(), 200, null),

            'deleteMarker: no id' => array('deleteMarker', array(), array(), $this->stops(), 400, 'Missing marker id.'),
            'deleteMarker: valid' => array('deleteMarker', array('7'), array(), $this->stops(), 200, null),
        );
    }

    public function testABlankSectionIdIsStoredAsNull()
    {
        $this->call('TourStopsApiHarness', 'update', array('a'), array('section_id' => '', 'name' => 'X'));

        $this->assertSame(array('section_id' => null, 'name' => 'X'), $this->controller->TourStops_Model->calls[0][1][1]);
    }

    public function testUpdateOnlyPassesWhitelistedFieldsToTheModel()
    {
        $this->call('TourStopsApiHarness', 'update', array('a'), array('name' => 'X', 'id' => 'hijack', 'bogus' => 1));

        $this->assertSame(array('name' => 'X'), $this->controller->TourStops_Model->calls[0][1][1]);
    }

    public function testUpdateMarkerHandsTheModelThePatchAndThePhotoList()
    {
        $this->call('TourStopsApiHarness', 'updateMarker', array('7'), array('label' => 'x', 'bogus' => 1, 'photos' => array('p.jpg')));

        $this->assertSame(array('7', array('label' => 'x'), array('p.jpg')), $this->controller->TourStops_Model->calls[0][1]);
    }
}
