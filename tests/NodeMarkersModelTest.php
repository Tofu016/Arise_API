<?php
use PHPUnit\Framework\TestCase;

// Pins Nodes_Model's own marker plumbing: markers store only an
// elevator_id, and _getMarkersGrouped reads the elevator's label and
// floors through the join, so every landing reports the same elevator.
// NodesModelHarness is declared in NeighborModelsTest.php and shared
// across the suite.
class NodeMarkersModelTest extends TestCase
{
    private function model(array $tables = array())
    {
        $model = new NodesModelHarness();
        $model->db = new FakeDb();
        $model->db->tables = $tables;
        return $model;
    }

    public function testAddMarkerOmitsElevatorIdForOrdinaryTypes()
    {
        $model = $this->model();

        $model->addMarker('a', 'room', 'Room 101', 1.0, 2.0);

        $this->assertSame(array(
            array('insert', 'node_markers', array(
                'node_id' => 'a', 'type' => 'room', 'label' => 'Room 101', 'yaw' => 1.0, 'pitch' => 2.0,
            )),
        ), $model->db->log);
    }

    public function testAddMarkerStoresOnlyTheElevatorId()
    {
        $model = $this->model();

        $model->addMarker('a', 'elevator', 'Elevator A', 1.0, 2.0, 'e1');

        $this->assertSame(array(
            array('insert', 'node_markers', array(
                'node_id' => 'a', 'type' => 'elevator', 'label' => 'Elevator A', 'yaw' => 1.0, 'pitch' => 2.0,
                'elevator_id' => 'e1',
            )),
        ), $model->db->log);
    }

    public function testUpdateMarkerWritesThePatchAsGiven()
    {
        $model = $this->model();

        $model->updateMarker(7, array('label' => 'x', 'elevator_id' => null));

        $this->assertSame(array(
            array('where', 'id', 7),
            array('update', 'node_markers', array('label' => 'x', 'elevator_id' => null)),
        ), $model->db->log);
    }

    public function testFindMarkerReturnsTheRawRowOrNull()
    {
        $row = array('id' => 7, 'node_id' => 'a', 'type' => 'elevator', 'label' => 'L', 'yaw' => 1.0, 'pitch' => 2.0, 'elevator_id' => 'e1');
        $model = $this->model(array('node_markers' => array($row)));

        $this->assertSame($row, $model->findMarker(7));
        $this->assertNull($model->findMarker(8));
    }

    private function findMarkers($nodeId, array $markerRows, array $elevators = array())
    {
        $model = $this->model(array(
            'nodes' => array(array('id' => $nodeId)),
            'node_markers' => $markerRows,
            'elevators' => $elevators,
        ));
        return $model->find($nodeId)['markers'];
    }

    public function testAnElevatorMarkerReadsItsLabelAndFloorsFromTheElevator()
    {
        $markers = $this->findMarkers('a', array(
            array('id' => 1, 'node_id' => 'a', 'type' => 'elevator', 'label' => 'stale copy', 'yaw' => 1.0, 'pitch' => 2.0, 'elevator_id' => 'e1'),
        ), array(
            array('id' => 'e1', 'label' => 'Elevator A', 'building' => 'gd1', 'accessible_floors' => '5,-1,1,2,3'),
        ));

        $this->assertSame(array(
            'id' => 1, 'type' => 'elevator', 'label' => 'Elevator A', 'yaw' => 1.0, 'pitch' => 2.0,
            'elevator_id' => 'e1', 'accessible_floors' => array(-1, 1, 2, 3, 5),
        ), $markers[0]);
    }

    public function testNonElevatorMarkersGetNullElevatorIdAndAnEmptyFloorsArray()
    {
        $markers = $this->findMarkers('a', array(
            array('id' => 1, 'node_id' => 'a', 'type' => 'room', 'label' => 'Room 101', 'yaw' => 1.0, 'pitch' => 2.0, 'elevator_id' => null),
        ));

        $this->assertSame(array(
            'id' => 1, 'type' => 'room', 'label' => 'Room 101', 'yaw' => 1.0, 'pitch' => 2.0,
            'elevator_id' => null, 'accessible_floors' => array(),
        ), $markers[0]);
    }

    public function testOnlyTheRequestedNodesMarkersAreRead()
    {
        $markers = $this->findMarkers('a', array(
            array('id' => 1, 'node_id' => 'b', 'type' => 'room', 'label' => 'Elsewhere', 'yaw' => 1.0, 'pitch' => 2.0, 'elevator_id' => null),
            array('id' => 2, 'node_id' => 'a', 'type' => 'room', 'label' => 'Here', 'yaw' => 1.0, 'pitch' => 2.0, 'elevator_id' => null),
        ));

        $this->assertSame(array('Here'), array_column($markers, 'label'));
    }
}
