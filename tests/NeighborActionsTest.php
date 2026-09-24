<?php
// Nodes_API and TourStops_API get their neighbour-link actions from
// the Neighbor_actions trait. The URLs must not change: trait methods must
// still be reachable through _remap, and the two hooks must not be.
class NeighborActionsTest extends ActionTestCase
{
    const BASES = array('MY_Controller', 'CI_Controller');

    public function controllers()
    {
        return array(
            'nodes' => array('NodesApiHarness', 'node_id', 'Nodes_Model'),
            'tour stops' => array('TourStopsApiHarness', 'stop_id', 'TourStops_Model'),
        );
    }

    /** @dataProvider controllers */
    public function testTheActionsAreStillReachableByUrl($harness)
    {
        foreach (array('addNeighbor', 'removeNeighbor', 'updateNeighborAngle', 'updateNeighborDefaultView', 'clearNeighborDefaultView') as $action) {
            $this->assertTrue(Api_response::isAction(new $harness(), $action, self::BASES), $action);
        }
    }

    /** @dataProvider controllers */
    public function testTheHooksAreNotReachableByUrl($harness)
    {
        foreach (array('neighborOwnerField', 'neighborModel') as $hook) {
            $this->assertFalse(Api_response::isAction(new $harness(), $hook, self::BASES), $hook);
        }
    }

    /** @dataProvider controllers */
    public function testEachControllerNamesItsOwnOwnerFieldInTheMessage($harness, $field)
    {
        $reply = $this->call($harness, 'removeNeighbor', array(), array());

        $this->assertReply($reply, 400, "{$field} and neighbor_id are both required.");
    }

    /** @dataProvider controllers */
    public function testEachControllerCallsItsOwnModel($harness, $field, $model)
    {
        $this->call($harness, 'addNeighbor', array(), array($field => 'a', 'neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2, 'reverse_yaw' => 3, 'reverse_pitch' => 4));

        $this->assertSame(array('addNeighbor', array('a', 'b', 1, 2, 3, 4)), $this->controller->$model->calls[0]);
    }

    /** @dataProvider controllers */
    public function testTheOtherControllersFieldIsNotAccepted($harness, $field)
    {
        $wrong = $field === 'node_id' ? 'stop_id' : 'node_id';

        $reply = $this->call($harness, 'addNeighbor', array(), array($wrong => 'a', 'neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2, 'reverse_yaw' => 3, 'reverse_pitch' => 4));

        $this->assertReply($reply, 400, "Missing field: {$field}");
    }
}
