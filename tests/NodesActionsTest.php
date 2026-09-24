<?php
// Pins Nodes_API's guards: which input each action accepts, the exact
// status and message when it refuses, and the order checks run in.
class NodesActionsTest extends ActionTestCase
{
    private function models(array $nodes = array(), array $buildings = array(), array $elevators = array())
    {
        return array('Nodes_Model' => $nodes, 'Buildings_Model' => $buildings, 'Elevators_Model' => $elevators);
    }

    // Elevator e1 in gd1 stops at -1, 1 and 2, with a landing already on 2.
    private static function elevator()
    {
        return array(
            'id' => 'e1', 'label' => 'Elevator A', 'building' => 'gd1', 'accessible_floors' => array(-1, 1, 2),
            'landings' => array(array('marker_id' => 5, 'node_id' => 'gd1_f2_lobby', 'floor' => 2)),
        );
    }

    private static function node($building, $floor)
    {
        return array('id' => 'a', 'building' => $building, 'floor' => $floor);
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
        $elevatorMarker = array('node_id' => 'a', 'type' => 'elevator', 'yaw' => 1, 'pitch' => 2);
        $elevatorFound = array('find' => self::elevator());
        $landing = array('id' => 7, 'node_id' => 'a', 'type' => 'elevator', 'elevator_id' => 'e1');
        $elevatorWithA = self::elevator();
        $elevatorWithA['landings'][] = array('marker_id' => 7, 'node_id' => 'a', 'floor' => 1);
        $landingAtA = array('elevatorsLandingAt' => array($elevatorWithA));

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

            // update: a node holding an elevator landing (node a, floor 1 of e1)
            'update: landing node to a floor the elevator skips' => array(
                'update', array('a'), array('floor' => 3), $this->models(array(), array(), $landingAtA), 409,
                "This node holds a landing of elevator 'e1', which doesn't stop at floor 3. Remove the landing first.",
            ),
            'update: landing node to another building' => array(
                'update', array('a'), array('building' => 'gd2'), $this->models(array(), array('find' => true), $landingAtA), 409,
                "This node holds a landing of elevator 'e1', which is in building 'gd1'. Remove the landing first.",
            ),
            'update: landing node onto a floor that already has a landing' => array(
                'update', array('a'), array('floor' => '2'), $this->models(array(), array(), $landingAtA), 409,
                "This node holds a landing of elevator 'e1', which already has a landing on floor 2 (node gd1_f2_lobby).",
            ),
            'update: landing node to another stop of its elevator' => array(
                'update', array('a'), array('floor' => -1, 'building' => 'gd1'), $this->models(array(), array('find' => true), $landingAtA), 200, null,
            ),
            'update: landing node, unrelated field' => array(
                'update', array('a'), array('name' => 'X'), $this->models(array(), array(), $landingAtA), 200, null,
            ),

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

            // updateNeighborDefaultView
            'updateNeighborDefaultView: no node_id' => array('updateNeighborDefaultView', array(), array('neighbor_id' => 'b', 'default_yaw' => 1, 'default_pitch' => 2), $this->models(), 400, 'Missing field: node_id'),
            'updateNeighborDefaultView: no default_pitch' => array('updateNeighborDefaultView', array(), array('node_id' => 'a', 'neighbor_id' => 'b', 'default_yaw' => 1), $this->models(), 400, 'Missing field: default_pitch'),
            'updateNeighborDefaultView: valid' => array('updateNeighborDefaultView', array(), array('node_id' => 'a', 'neighbor_id' => 'b', 'default_yaw' => 1, 'default_pitch' => 2), $this->models(), 200, null),

            // clearNeighborDefaultView: empty() semantics, one shared message
            'clearNeighborDefaultView: empty body' => array('clearNeighborDefaultView', array(), array(), $this->models(), 400, 'node_id and neighbor_id are both required.'),
            'clearNeighborDefaultView: no neighbor_id' => array('clearNeighborDefaultView', array(), array('node_id' => 'a'), $this->models(), 400, 'node_id and neighbor_id are both required.'),
            'clearNeighborDefaultView: valid' => array('clearNeighborDefaultView', array(), array('node_id' => 'a', 'neighbor_id' => 'b'), $this->models(), 200, null),

            // addMarker
            'addMarker: no node_id' => array('addMarker', array(), array_diff_key($marker, array('node_id' => 1)), $this->models(), 400, 'Missing field: node_id'),
            'addMarker: no label' => array('addMarker', array(), array_diff_key($marker, array('label' => 1)), $this->models(), 400, 'Missing field: label'),
            'addMarker: bad type' => array('addMarker', array(), array_merge($marker, array('type' => 'bad')), $this->models($markerTypes), 400, 'Invalid marker type. Must be one of: room, facility'),
            'addMarker: valid' => array('addMarker', array(), $marker, $this->models(array('isValidMarkerType' => true)), 200, null),

            // addMarker: elevator — a landing of an existing elevator, checked
            // after the shared fields. label is optional for this type only.
            'addMarker: elevator missing elevator_id' => array(
                'addMarker', array(), $elevatorMarker, $this->models(array('isValidMarkerType' => true)), 400, 'elevator_id is required for an elevator marker.',
            ),
            'addMarker: elevator blank elevator_id' => array(
                'addMarker', array(), array_merge($elevatorMarker, array('elevator_id' => '  ')),
                $this->models(array('isValidMarkerType' => true)), 400, 'elevator_id is required for an elevator marker.',
            ),
            'addMarker: elevator unknown elevator' => array(
                'addMarker', array(), array_merge($elevatorMarker, array('elevator_id' => 'e9')),
                $this->models(array('isValidMarkerType' => true), array(), array('find' => null)), 400, "Elevator 'e9' does not exist.",
            ),
            'addMarker: elevator unknown node' => array(
                'addMarker', array(), array_merge($elevatorMarker, array('elevator_id' => 'e1')),
                $this->models(array('isValidMarkerType' => true, 'find' => null), array(), $elevatorFound), 400, "Node 'a' does not exist.",
            ),
            'addMarker: elevator wrong building' => array(
                'addMarker', array(), array_merge($elevatorMarker, array('elevator_id' => 'e1')),
                $this->models(array('isValidMarkerType' => true, 'find' => self::node('gd2', 1)), array(), $elevatorFound), 400,
                "Node 'a' is in building 'gd2', but elevator 'e1' is in 'gd1'.",
            ),
            'addMarker: elevator floor not accessible' => array(
                'addMarker', array(), array_merge($elevatorMarker, array('elevator_id' => 'e1')),
                $this->models(array('isValidMarkerType' => true, 'find' => self::node('gd1', 3)), array(), $elevatorFound), 400,
                "Floor 3 is not one of elevator 'e1's floors (-1, 1, 2).",
            ),
            'addMarker: elevator duplicate landing on a floor' => array(
                'addMarker', array(), array_merge($elevatorMarker, array('elevator_id' => 'e1')),
                $this->models(array('isValidMarkerType' => true, 'find' => self::node('gd1', 2)), array(), $elevatorFound), 409,
                "Elevator 'e1' already has a landing on floor 2 (node gd1_f2_lobby).",
            ),
            'addMarker: elevator valid, UG floor' => array(
                'addMarker', array(), array_merge($elevatorMarker, array('elevator_id' => 'e1')),
                $this->models(array('isValidMarkerType' => true, 'find' => self::node('gd1', -1)), array(), $elevatorFound), 200, null,
            ),
            'addMarker: non-elevator still requires label' => array(
                'addMarker', array(), array_diff_key(array_merge($marker, array('elevator_id' => 'e1')), array('label' => 1)),
                $this->models(), 400, 'Missing field: label',
            ),

            // updateMarker: a bad type is reported before "no valid fields"
            'updateMarker: no id' => array('updateMarker', array(), array('label' => 'x'), $this->models(), 400, 'Missing marker id.'),
            'updateMarker: empty body' => array('updateMarker', array('7'), array(), $this->models(), 400, $noFields),
            'updateMarker: only unknown fields' => array('updateMarker', array('7'), array('bogus' => 1), $this->models(), 400, $noFields),
            'updateMarker: bad type' => array('updateMarker', array('7'), array('type' => 'bad'), $this->models($markerTypes), 400, 'Invalid marker type. Must be one of: room, facility'),
            'updateMarker: valid' => array('updateMarker', array('7'), array('label' => 'x'), $this->models(), 200, null),
            'updateMarker: valid type' => array('updateMarker', array('7'), array('type' => 'room'), $this->models(array('isValidMarkerType' => true)), 200, null),
            'updateMarker: label only, no elevator checks' => array('updateMarker', array('7'), array('label' => 'x'), $this->models(), 200, null),
            'updateMarker: per-marker floors are no longer accepted' => array('updateMarker', array('7'), array('accessible_floors' => array(1, 2), 'elevator_group_id' => 'e1'), $this->models(), 400, $noFields),
            'updateMarker: elevator_id on a missing marker' => array('updateMarker', array('7'), array('elevator_id' => 'e1'), $this->models(), 404, 'Marker not found.'),
            'updateMarker: elevator_id on a room marker' => array(
                'updateMarker', array('7'), array('elevator_id' => 'e1'),
                $this->models(array('findMarker' => array('id' => 7, 'node_id' => 'a', 'type' => 'room', 'elevator_id' => null))), 400,
                'elevator_id only applies to elevator markers.',
            ),
            'updateMarker: same elevator_id skips the landing checks' => array(
                'updateMarker', array('7'), array('elevator_id' => 'e1'), $this->models(array('findMarker' => $landing), array(), array('find' => null)), 200, null,
            ),
            'updateMarker: move to an unknown elevator' => array(
                'updateMarker', array('7'), array('elevator_id' => 'e9'), $this->models(array('findMarker' => $landing), array(), array('find' => null)), 400,
                "Elevator 'e9' does not exist.",
            ),
            'updateMarker: move to an elevator already landing on that floor' => array(
                'updateMarker', array('7'), array('elevator_id' => 'e2'),
                $this->models(array('findMarker' => $landing, 'find' => self::node('gd1', 2)), array(), array('find' => array_merge(self::elevator(), array('id' => 'e2')))), 409,
                "Elevator 'e2' already has a landing on floor 2 (node gd1_f2_lobby).",
            ),
            'updateMarker: its own landing is not a collision' => array(
                'updateMarker', array('5'), array('elevator_id' => 'e2'),
                $this->models(array('findMarker' => array_merge($landing, array('id' => 5)), 'find' => self::node('gd1', 2)), array(), array('find' => array_merge(self::elevator(), array('id' => 'e2')))), 200, null,
            ),
            'updateMarker: room becoming an elevator needs elevator_id' => array(
                'updateMarker', array('7'), array('type' => 'elevator'),
                $this->models(array('isValidMarkerType' => true, 'findMarker' => array('id' => 7, 'node_id' => 'a', 'type' => 'room', 'elevator_id' => null))), 400,
                'elevator_id is required for an elevator marker.',
            ),

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

    private function addElevatorMarker(array $extra)
    {
        $body = array_merge(array('node_id' => 'a', 'type' => 'elevator', 'yaw' => 1, 'pitch' => 2, 'elevator_id' => 'e1'), $extra);
        $this->call('NodesApiHarness', 'addMarker', array(), $body, $this->models(
            array('isValidMarkerType' => true, 'find' => self::node('gd1', 1)), array(), array('find' => self::elevator())
        ));
        foreach ($this->controller->Nodes_Model->calls as $call) {
            if ($call[0] === 'addMarker') {
                return $call[1];
            }
        }
        $this->fail('addMarker never reached the model.');
    }

    public function testAnElevatorMarkerWithoutALabelTakesTheElevatorsLabel()
    {
        $this->assertSame(array('a', 'elevator', 'Elevator A', 1, 2, 'e1'), $this->addElevatorMarker(array()));
    }

    public function testTheOldPerMarkerElevatorFieldsAreIgnored()
    {
        $args = $this->addElevatorMarker(array('label' => 'Lift', 'elevator_group_id' => 'x', 'accessible_floors' => array(1, 9)));

        $this->assertSame(array('a', 'elevator', 'Lift', 1, 2, 'e1'), $args);
    }

    public function testAnOrdinaryMarkerNeverStoresAnElevatorId()
    {
        $this->call('NodesApiHarness', 'addMarker', array(), array('node_id' => 'a', 'type' => 'room', 'label' => 'L', 'yaw' => 1, 'pitch' => 2, 'elevator_id' => 'e1'),
            $this->models(array('isValidMarkerType' => true)));

        $this->assertSame(array('addMarker', array('a', 'room', 'L', 1, 2, null)), end($this->controller->Nodes_Model->calls));
    }

    public function testChangingAnElevatorMarkerToAnotherTypeUnlinksIt()
    {
        $this->call('NodesApiHarness', 'updateMarker', array('7'), array('type' => 'room'), $this->models(array('isValidMarkerType' => true)));

        $this->assertSame(array('updateMarker', array('7', array('type' => 'room', 'elevator_id' => null))), end($this->controller->Nodes_Model->calls));
    }

    public function testNoModelWriteHappensWhenAGuardRefuses()
    {
        $this->call('NodesApiHarness', 'addNeighbor', array(), array('node_id' => 'a'));

        $this->assertSame(array(), $this->controller->Nodes_Model->calls);
    }
}
