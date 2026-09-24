<?php
// Pins Elevators_API's guards: which input each action accepts, the exact
// status and message when it refuses, and what reaches the model.
class ElevatorsActionsTest extends ActionTestCase
{
    private function models(array $elevators = array(), array $buildings = array())
    {
        return array('Elevators_Model' => $elevators, 'Buildings_Model' => $buildings);
    }

    // e1 stops at -1, 1, 2 and 3, with landings on 1 and 2.
    private static function elevator()
    {
        return array(
            'id' => 'e1', 'label' => 'Elevator A', 'building' => 'gd1', 'accessible_floors' => array(-1, 1, 2, 3),
            'landings' => array(
                array('marker_id' => 4, 'node_id' => 'gd1_f1_lobby', 'floor' => 1),
                array('marker_id' => 5, 'node_id' => 'gd1_f2_lobby', 'floor' => 2),
            ),
        );
    }

    /** @dataProvider replies */
    public function testReplies($action, array $params, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('ElevatorsApiHarness', $action, $params, $body, $returns), $status, $error);
    }

    public function replies()
    {
        $valid = array('id' => 'gd1-elevator-a', 'label' => 'Elevator A', 'building' => 'gd1', 'accessible_floors' => array(-1, 1, 2));
        $building = array('find' => true);
        $badFloors = 'accessible_floors must list at least 2 distinct whole-number floors.';
        $found = array('find' => self::elevator());

        return array(
            // create
            'create: no id' => array('create', array(), array_diff_key($valid, array('id' => 1)), $this->models(), 400, 'Elevator id is required.'),
            'create: blank id' => array('create', array(), array_merge($valid, array('id' => '  ')), $this->models(), 400, 'Elevator id is required.'),
            'create: id over 64 chars' => array('create', array(), array_merge($valid, array('id' => str_repeat('x', 65))), $this->models(), 400, 'Elevator id must be at most 64 characters.'),
            'create: id of exactly 64 chars' => array('create', array(), array_merge($valid, array('id' => str_repeat('x', 64))), $this->models(array(), $building), 200, null),
            'create: blank label' => array('create', array(), array_merge($valid, array('label' => ' ')), $this->models(), 400, 'Elevator label is required.'),
            'create: no building' => array('create', array(), array_diff_key($valid, array('building' => 1)), $this->models(), 400, "Building '' does not exist."),
            'create: unknown building' => array('create', array(), $valid, $this->models(array(), array('find' => null)), 400, "Building 'gd1' does not exist."),
            'create: no floors' => array('create', array(), array_diff_key($valid, array('accessible_floors' => 1)), $this->models(array(), $building), 400, $badFloors),
            'create: one floor' => array('create', array(), array_merge($valid, array('accessible_floors' => array(1))), $this->models(array(), $building), 400, $badFloors),
            'create: one floor repeated' => array('create', array(), array_merge($valid, array('accessible_floors' => '2, 2')), $this->models(array(), $building), 400, $badFloors),
            'create: non-integer floor' => array('create', array(), array_merge($valid, array('accessible_floors' => array(1, 'two'))), $this->models(array(), $building), 400, $badFloors),
            'create: fractional floor' => array('create', array(), array_merge($valid, array('accessible_floors' => array(1, 2.5))), $this->models(array(), $building), 400, $badFloors),
            'create: id taken' => array('create', array(), $valid, $this->models(array('idExists' => true), $building), 409, "Elevator id 'gd1-elevator-a' is already taken."),
            'create: comma-string floors' => array('create', array(), array_merge($valid, array('accessible_floors' => '-1,1, 2')), $this->models(array(), $building), 200, null),
            'create: valid' => array('create', array(), $valid, $this->models(array(), $building), 200, null),

            // update
            'update: no id' => array('update', array(), array('label' => 'x'), $this->models(), 400, 'Missing elevator id.'),
            'update: empty body' => array('update', array('e1'), array(), $this->models(), 400, 'No valid fields to update.'),
            'update: building is not updatable' => array('update', array('e1'), array('building' => 'gd2'), $this->models($found), 400, 'No valid fields to update.'),
            'update: unknown elevator' => array('update', array('e9'), array('label' => 'x'), $this->models(), 404, 'Elevator not found.'),
            'update: blank label' => array('update', array('e1'), array('label' => ' '), $this->models($found), 400, 'Elevator label is required.'),
            'update: one floor' => array('update', array('e1'), array('accessible_floors' => array(1)), $this->models($found), 400, $badFloors),
            'update: dropping a floor with a landing' => array(
                'update', array('e1'), array('accessible_floors' => array(-1, 1, 3)), $this->models($found), 409,
                'Remove the landing on gd1_f2_lobby (floor 2) before dropping that floor from this elevator.',
            ),
            'update: dropping two floors with landings' => array(
                'update', array('e1'), array('accessible_floors' => '-1,3'), $this->models($found), 409,
                'Remove the landing on gd1_f1_lobby (floor 1), gd1_f2_lobby (floor 2) before dropping that floor from this elevator.',
            ),
            'update: dropping a floor without a landing' => array('update', array('e1'), array('accessible_floors' => array(1, 2)), $this->models($found), 200, null),
            'update: label' => array('update', array('e1'), array('label' => 'Lift A'), $this->models($found), 200, null),

            // delete
            'delete: no id' => array('delete', array(), array(), $this->models(), 400, 'Missing elevator id.'),
            'delete: unknown elevator' => array('delete', array('e9'), array(), $this->models(array('idExists' => false)), 404, 'Elevator not found.'),
            'delete: valid' => array('delete', array('e1'), array(), $this->models(array('idExists' => true)), 200, null),
        );
    }

    public function testGetAllIsPublic()
    {
        $reply = $this->call('ElevatorsApiHarness', 'getAll', array(), array(), $this->models(array('getAll' => array(self::elevator()))), null);

        $this->assertReply($reply, 200);
        $this->assertSame(array(self::elevator()), $reply->body()['elevators']);
    }

    /** @dataProvider writes */
    public function testWritesAreAdminOnly($action, array $params)
    {
        $this->assertReply($this->call('ElevatorsApiHarness', $action, $params, array(), $this->models(), null), 401, 'Not signed in.');
        $this->assertReply($this->call('ElevatorsApiHarness', $action, $params, array(), $this->models(), array('id' => '2', 'role' => 'user')), 403, 'Admin access required.');
        $this->assertSame(array(), $this->controller->Elevators_Model->calls);
    }

    public function writes()
    {
        return array(
            'create' => array('create', array()),
            'update' => array('update', array('e1')),
            'delete' => array('delete', array('e1')),
        );
    }

    public function testCreatePassesSortedDistinctFloors()
    {
        $this->call('ElevatorsApiHarness', 'create', array(), array(
            'id' => ' e1 ', 'label' => 'Elevator A', 'building' => 'gd1', 'accessible_floors' => array('3', 1, -1, 1),
        ), $this->models(array(), array('find' => true)));

        $this->assertSame(array('create', array('e1', 'Elevator A', 'gd1', array(-1, 1, 3))), end($this->controller->Elevators_Model->calls));
    }

    public function testUpdatePassesOnlyLabelAndNormalisedFloors()
    {
        $this->call('ElevatorsApiHarness', 'update', array('e1'), array(
            'label' => 'Lift', 'accessible_floors' => '3,2,1,2', 'building' => 'gd2', 'id' => 'hijack',
        ), $this->models(array('find' => self::elevator())));

        $this->assertSame(array('update', array('e1', array('label' => 'Lift', 'accessible_floors' => array(1, 2, 3)))), end($this->controller->Elevators_Model->calls));
    }

    public function testDeleteRemovesTheElevator()
    {
        $this->call('ElevatorsApiHarness', 'delete', array('e1'), array(), $this->models(array('idExists' => true)));

        $this->assertSame(array('delete', array('e1')), end($this->controller->Elevators_Model->calls));
    }

    public function testNoModelWriteHappensWhenAFloorWouldBeStranded()
    {
        $this->call('ElevatorsApiHarness', 'update', array('e1'), array('accessible_floors' => array(1, 3)), $this->models(array('find' => self::elevator())));

        $this->assertSame(array('find'), array_column($this->controller->Elevators_Model->calls, 0));
    }
}
