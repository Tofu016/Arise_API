<?php
// Pins Nodes_API's guards: which input each action accepts, the exact
// status and message when it refuses, and the order checks run in.
class NodesActionsTest extends ActionTestCase
{
    private function models(array $nodes = array(), array $buildings = array())
    {
        return array('Nodes_Model' => $nodes, 'Buildings_Model' => $buildings);
    }

    /** @dataProvider replies */
    public function testReplies($action, array $params, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('NodesApiHarness', $action, $params, $body, $returns), $status, $error);
    }

    public function replies()
    {
        $valid = array('name' => 'Hall', 'building' => 'gd1', 'floor' => 1, 'type' => 'hallway');
        $requiredCreate = 'name, building, floor, and type are all required.';
        $bothIds = 'Both the current and new id are required.';
        $noFields = 'No valid fields to update.';
        $markerTypes = array('isValidMarkerType' => false, 'getAllowedMarkerTypes' => array('room', 'facility'));
        $neighbor = array('node_id' => 'a', 'neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2, 'reverse_yaw' => 3, 'reverse_pitch' => 4);
        $marker = array('node_id' => 'a', 'type' => 'room', 'label' => 'L', 'yaw' => 1, 'pitch' => 2);

        return array(
            // create
            'create: empty body' => array('create', array(), array(), $this->models(), 400, $requiredCreate),
            'create: no building' => array('create', array(), array_diff_key($valid, array('building' => 1)), $this->models(), 400, $requiredCreate),
            'create: no floor' => array('create', array(), array_diff_key($valid, array('floor' => 1)), $this->models(), 400, $requiredCreate),
            'create: blank type' => array('create', array(), array_merge($valid, array('type' => ' ')), $this->models(), 400, $requiredCreate),
            'create: floor 0 is allowed' => array('create', array(), array_merge($valid, array('floor' => 0)), $this->models(array(), array('find' => true)), 200, null),
            'create: unknown building' => array('create', array(), $valid, $this->models(array(), array('find' => false)), 400, "Building 'gd1' does not exist."),
            'create: requested id taken' => array('create', array(), array_merge($valid, array('id' => 'x')),
                $this->models(array('idExists' => true), array('find' => true)), 409, "ID 'x' is already used by another node."),
            'create: valid' => array('create', array(), $valid, $this->models(array(), array('find' => true)), 200, null),

            // rename
            'rename: no new id' => array('rename', array('a'), array(), $this->models(), 400, $bothIds),
            'rename: no old id' => array('rename', array(), array('new_id' => 'b'), $this->models(), 400, $bothIds),
            'rename: new id taken' => array('rename', array('a'), array('new_id' => 'b'), $this->models(array('idExists' => true)), 409, "ID 'b' is already used by another node."),
            'rename: valid' => array('rename', array('a'), array('new_id' => 'b'), $this->models(array('idExists' => false)), 200, null),

            // update
            'update: no id' => array('update', array(), array('name' => 'X'), $this->models(), 400, 'Missing node id.'),
            'update: id "0" counts as missing' => array('update', array('0'), array('name' => 'X'), $this->models(), 400, 'Missing node id.'),
            'update: empty body' => array('update', array('a'), array(), $this->models(), 400, $noFields),
            'update: only unknown fields' => array('update', array('a'), array('bogus' => 1), $this->models(), 400, $noFields),
            'update: unknown building' => array('update', array('a'), array('building' => 'zzz'), $this->models(array(), array('find' => false)), 400, "Building 'zzz' does not exist."),
            'update: valid' => array('update', array('a'), array('name' => 'X'), $this->models(), 200, null),

            // delete
            'delete: no id' => array('delete', array(), array(), $this->models(), 400, 'Missing node id.'),
            'delete: valid' => array('delete', array('a'), array(), $this->models(), 200, null),

            // addNeighbor: required fields are checked in this order, with isset()
            'addNeighbor: no node_id' => array('addNeighbor', array(), array_diff_key($neighbor, array('node_id' => 1)), $this->models(), 400, 'Missing field: node_id'),
            'addNeighbor: no neighbor_id' => array('addNeighbor', array(), array_diff_key($neighbor, array('neighbor_id' => 1)), $this->models(), 400, 'Missing field: neighbor_id'),
            'addNeighbor: no yaw' => array('addNeighbor', array(), array_diff_key($neighbor, array('yaw' => 1)), $this->models(), 400, 'Missing field: yaw'),
            'addNeighbor: no reverse_pitch' => array('addNeighbor', array(), array_diff_key($neighbor, array('reverse_pitch' => 1)), $this->models(), 400, 'Missing field: reverse_pitch'),
            'addNeighbor: first missing wins' => array('addNeighbor', array(), array('node_id' => 'a'), $this->models(), 400, 'Missing field: neighbor_id'),
            'addNeighbor: null counts as missing' => array('addNeighbor', array(), array_merge($neighbor, array('pitch' => null)), $this->models(), 400, 'Missing field: pitch'),
            'addNeighbor: empty string counts as present' => array('addNeighbor', array(), array_merge($neighbor, array('pitch' => '')), $this->models(), 200, null),
            'addNeighbor: zero counts as present' => array('addNeighbor', array(), array_merge($neighbor, array('yaw' => 0, 'pitch' => 0)), $this->models(), 200, null),
            'addNeighbor: valid' => array('addNeighbor', array(), $neighbor, $this->models(), 200, null),

            // removeNeighbor: empty() semantics, one shared message
            'removeNeighbor: empty body' => array('removeNeighbor', array(), array(), $this->models(), 400, 'node_id and neighbor_id are both required.'),
            'removeNeighbor: no neighbor_id' => array('removeNeighbor', array(), array('node_id' => 'a'), $this->models(), 400, 'node_id and neighbor_id are both required.'),
            'removeNeighbor: node_id "0" counts as missing' => array('removeNeighbor', array(), array('node_id' => '0', 'neighbor_id' => 'b'), $this->models(), 400, 'node_id and neighbor_id are both required.'),
            'removeNeighbor: empty string counts as missing' => array('removeNeighbor', array(), array('node_id' => '', 'neighbor_id' => 'b'), $this->models(), 400, 'node_id and neighbor_id are both required.'),
            'removeNeighbor: valid' => array('removeNeighbor', array(), array('node_id' => 'a', 'neighbor_id' => 'b'), $this->models(), 200, null),

            // updateNeighborAngle
            'updateNeighborAngle: no node_id' => array('updateNeighborAngle', array(), array('neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2), $this->models(), 400, 'Missing field: node_id'),
            'updateNeighborAngle: no pitch' => array('updateNeighborAngle', array(), array('node_id' => 'a', 'neighbor_id' => 'b', 'yaw' => 1), $this->models(), 400, 'Missing field: pitch'),
            'updateNeighborAngle: valid' => array('updateNeighborAngle', array(), array('node_id' => 'a', 'neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2), $this->models(), 200, null),

            // addMarker
            'addMarker: no node_id' => array('addMarker', array(), array_diff_key($marker, array('node_id' => 1)), $this->models(), 400, 'Missing field: node_id'),
            'addMarker: no label' => array('addMarker', array(), array_diff_key($marker, array('label' => 1)), $this->models(), 400, 'Missing field: label'),
            'addMarker: bad type' => array('addMarker', array(), array_merge($marker, array('type' => 'bad')), $this->models($markerTypes), 400, 'Invalid marker type. Must be one of: room, facility'),
            'addMarker: valid' => array('addMarker', array(), $marker, $this->models(array('isValidMarkerType' => true)), 200, null),

            // updateMarker: a bad type is reported before "no valid fields"
            'updateMarker: no id' => array('updateMarker', array(), array('label' => 'x'), $this->models(), 400, 'Missing marker id.'),
            'updateMarker: empty body' => array('updateMarker', array('7'), array(), $this->models(), 400, $noFields),
            'updateMarker: only unknown fields' => array('updateMarker', array('7'), array('bogus' => 1), $this->models(), 400, $noFields),
            'updateMarker: bad type' => array('updateMarker', array('7'), array('type' => 'bad'), $this->models($markerTypes), 400, 'Invalid marker type. Must be one of: room, facility'),
            'updateMarker: valid' => array('updateMarker', array('7'), array('label' => 'x'), $this->models(), 200, null),
            'updateMarker: valid type' => array('updateMarker', array('7'), array('type' => 'room'), $this->models(array('isValidMarkerType' => true)), 200, null),

            // deleteMarker
            'deleteMarker: no id' => array('deleteMarker', array(), array(), $this->models(), 400, 'Missing marker id.'),
            'deleteMarker: valid' => array('deleteMarker', array('7'), array(), $this->models(), 200, null),

            // addRoom / removeRoom
            'addRoom: empty body' => array('addRoom', array(), array(), $this->models(), 400, 'node_id and room_name are both required.'),
            'addRoom: blank room_name' => array('addRoom', array(), array('node_id' => 'a', 'room_name' => '  '), $this->models(), 400, 'node_id and room_name are both required.'),
            'addRoom: valid' => array('addRoom', array(), array('node_id' => 'a', 'room_name' => 'Lab'), $this->models(), 200, null),
            'removeRoom: no id' => array('removeRoom', array(), array(), $this->models(), 400, 'Missing room id.'),
            'removeRoom: valid' => array('removeRoom', array('3'), array(), $this->models(), 200, null),
        );
    }

    public function testUpdateOnlyPassesWhitelistedFieldsToTheModel()
    {
        $this->call('NodesApiHarness', 'update', array('a'), array('name' => 'X', 'id' => 'hijack', 'bogus' => 1, 'floor' => 2));

        $this->assertSame(array('name' => 'X', 'floor' => 2), $this->controller->Nodes_Model->calls[0][1][1]);
    }

    public function testNoModelWriteHappensWhenAGuardRefuses()
    {
        $this->call('NodesApiHarness', 'addNeighbor', array(), array('node_id' => 'a'));

        $this->assertSame(array(), $this->controller->Nodes_Model->calls);
    }
}
