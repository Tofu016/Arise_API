<?php
use PHPUnit\Framework\TestCase;

// Pins Elevators_Model against FakeDb: floors stored as a sorted comma
// string and read back as ints, and landings read through the
// node_markers -> nodes join.
class ElevatorsModelTest extends TestCase
{
    private function model(array $tables = array())
    {
        $model = new ElevatorsModelHarness();
        $model->db = new FakeDb();
        $model->db->tables = $tables;
        return $model;
    }

    private function tables()
    {
        return array(
            'elevators' => array(
                array('id' => 'e2', 'label' => 'Elevator B', 'building' => 'gd2', 'accessible_floors' => '1,2', 'created_at' => 'c', 'updated_at' => 'u'),
                array('id' => 'e1', 'label' => 'Elevator A', 'building' => 'gd1', 'accessible_floors' => '3,-1,1', 'created_at' => 'c', 'updated_at' => 'u'),
            ),
            'nodes' => array(
                array('id' => 'n3', 'building' => 'gd1', 'floor' => 3),
                array('id' => 'n1', 'building' => 'gd1', 'floor' => -1),
                array('id' => 'r1', 'building' => 'gd1', 'floor' => 1),
            ),
            'node_markers' => array(
                array('id' => 10, 'node_id' => 'n3', 'type' => 'elevator', 'label' => 'x', 'yaw' => 0, 'pitch' => 0, 'elevator_id' => 'e1'),
                array('id' => 11, 'node_id' => 'n1', 'type' => 'elevator', 'label' => 'x', 'yaw' => 0, 'pitch' => 0, 'elevator_id' => 'e1'),
                array('id' => 12, 'node_id' => 'r1', 'type' => 'room', 'label' => 'Room', 'yaw' => 0, 'pitch' => 0, 'elevator_id' => null),
            ),
        );
    }

    public function testGetAllShapesEveryElevatorWithItsLandings()
    {
        $this->assertSame(array(
            array(
                'id' => 'e1', 'label' => 'Elevator A', 'building' => 'gd1', 'accessible_floors' => array(-1, 1, 3),
                'landings' => array(
                    array('marker_id' => 11, 'node_id' => 'n1', 'floor' => -1),
                    array('marker_id' => 10, 'node_id' => 'n3', 'floor' => 3),
                ),
                'created_at' => 'c', 'updated_at' => 'u',
            ),
            array(
                'id' => 'e2', 'label' => 'Elevator B', 'building' => 'gd2', 'accessible_floors' => array(1, 2),
                'landings' => array(), 'created_at' => 'c', 'updated_at' => 'u',
            ),
        ), $this->model($this->tables())->getAll());
    }

    public function testFindReturnsNullForAnUnknownElevator()
    {
        $this->assertNull($this->model($this->tables())->find('nope'));
    }

    public function testElevatorsLandingAtFindsTheElevatorOfALandingNode()
    {
        $model = $this->model($this->tables());

        $this->assertSame(array('e1'), array_column($model->elevatorsLandingAt('n3'), 'id'));
        $this->assertSame(array(), $model->elevatorsLandingAt('r1'));
    }

    public function testCreateStoresSortedDistinctFloors()
    {
        $model = $this->model();

        $model->create('e1', ' Elevator A ', 'gd1', array(3, -1, 1, 3));

        $insert = $model->db->log[0];
        $this->assertSame('insert', $insert[0]);
        $this->assertSame('elevators', $insert[1]);
        $this->assertSame(
            array('id' => 'e1', 'label' => 'Elevator A', 'building' => 'gd1', 'accessible_floors' => '-1,1,3'),
            array_intersect_key($insert[2], array_flip(array('id', 'label', 'building', 'accessible_floors')))
        );
    }

    public function testUpdateJoinsFloorsAndTouchesUpdatedAt()
    {
        $model = $this->model($this->tables());

        $model->update('e1', array('accessible_floors' => array(2, -1, 3)));

        $this->assertSame('-1,2,3', $model->db->tables['elevators'][1]['accessible_floors']);
        $this->assertNotSame('u', $model->db->tables['elevators'][1]['updated_at']);
    }

    public function testDeleteRemovesTheRow()
    {
        $model = $this->model($this->tables());

        $model->delete('e1');

        $this->assertSame(array('e2'), array_column($model->db->tables['elevators'], 'id'));
    }
}
