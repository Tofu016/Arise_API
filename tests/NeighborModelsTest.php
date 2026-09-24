<?php
use PHPUnit\Framework\TestCase;

class NodesModelHarness extends Nodes_Model
{
    public $db;

    public function __construct()
    {
    }
}

class TourStopsModelHarness extends TourStops_Model
{
    public $db;

    public function __construct()
    {
    }
}

// Pins what Nodes_Model and TourStops_Model do with neighbour links, by
// the exact queries they issue against a recording FakeDb. The two models
// are meant to behave identically here, differing only in the table and
// the owner column, so every test runs against both.
class NeighborModelsTest extends TestCase
{
    /** [model harness, edges table, owner column, owner table] */
    public function models()
    {
        return array(
            'nodes' => array('NodesModelHarness', 'node_neighbors', 'node_id', 'nodes'),
            'tour stops' => array('TourStopsModelHarness', 'tour_stop_neighbors', 'tour_stop_id', 'tour_stops'),
        );
    }

    private function model($class, array $tables = array())
    {
        $model = new $class();
        $model->db = new FakeDb();
        $model->db->tables = $tables;
        return $model;
    }

    // ---- writes ----------------------------------------------------

    /** @dataProvider models */
    public function testAddNeighborWritesBothDirectionsEachWithItsOwnAngle($class, $edges, $owner)
    {
        $model = $this->model($class);

        $model->addNeighbor('a', 'b', 10, 20, 30, 40);

        $this->assertSame(array(
            array('insert', $edges, array($owner => 'a', 'neighbor_id' => 'b', 'yaw' => 10, 'pitch' => 20)),
            array('insert', $edges, array($owner => 'b', 'neighbor_id' => 'a', 'yaw' => 30, 'pitch' => 40)),
        ), $model->db->log);
    }

    /** @dataProvider models */
    public function testRemoveNeighborDeletesBothDirections($class, $edges, $owner)
    {
        $model = $this->model($class);

        $model->removeNeighbor('a', 'b');

        $this->assertSame(array(
            array('where', $owner, 'a'),
            array('where', 'neighbor_id', 'b'),
            array('delete', $edges),
            array('where', $owner, 'b'),
            array('where', 'neighbor_id', 'a'),
            array('delete', $edges),
        ), $model->db->log);
    }

    /** @dataProvider models */
    public function testUpdateNeighborAngleTouchesOnlyTheOneDirection($class, $edges, $owner)
    {
        $model = $this->model($class);

        $result = $model->updateNeighborAngle('a', 'b', 11, 22);

        $this->assertTrue($result);
        $this->assertSame(array(
            array('where', $owner, 'a'),
            array('where', 'neighbor_id', 'b'),
            array('update', $edges, array('yaw' => 11, 'pitch' => 22)),
        ), $model->db->log);
    }

    /** @dataProvider models */
    public function testUpdateNeighborDefaultViewTouchesOnlyTheOneDirection($class, $edges, $owner)
    {
        $model = $this->model($class);

        $result = $model->updateNeighborDefaultView('a', 'b', 33, 44);

        $this->assertTrue($result);
        $this->assertSame(array(
            array('where', $owner, 'a'),
            array('where', 'neighbor_id', 'b'),
            array('update', $edges, array('default_yaw' => 33, 'default_pitch' => 44)),
        ), $model->db->log);
    }

    /** @dataProvider models */
    public function testUpdateNeighborDefaultViewAcceptsNullToClearIt($class, $edges, $owner)
    {
        $model = $this->model($class);

        $model->updateNeighborDefaultView('a', 'b', null, null);

        $this->assertSame(array(
            array('where', $owner, 'a'),
            array('where', 'neighbor_id', 'b'),
            array('update', $edges, array('default_yaw' => null, 'default_pitch' => null)),
        ), $model->db->log);
    }

    // ---- reads -----------------------------------------------------

    private function edges($owner)
    {
        return array(
            array('id' => 1, $owner => 'a', 'neighbor_id' => 'b', 'yaw' => 1.5, 'pitch' => 2.5, 'default_yaw' => 9.0, 'default_pitch' => -1.0),
            array('id' => 2, $owner => 'a', 'neighbor_id' => 'c', 'yaw' => 3.0, 'pitch' => 4.0, 'default_yaw' => null, 'default_pitch' => null),
            array('id' => 3, $owner => 'b', 'neighbor_id' => 'a', 'yaw' => 5.0, 'pitch' => 6.0, 'default_yaw' => null, 'default_pitch' => null),
        );
    }

    private function tables($edgesTable, $owner, $ownerTable)
    {
        return array(
            $ownerTable => array(array('id' => 'a'), array('id' => 'b'), array('id' => 'c')),
            $edgesTable => $this->edges($owner),
        );
    }

    /** @dataProvider models */
    public function testFindReturnsOnlyThatOwnersNeighborsWithTheEdgeFields($class, $edges, $owner, $ownerTable)
    {
        $model = $this->model($class, $this->tables($edges, $owner, $ownerTable));

        $found = $model->find('a');

        $this->assertSame(array(
            array('neighbor_id' => 'b', 'yaw' => 1.5, 'pitch' => 2.5, 'default_yaw' => 9.0, 'default_pitch' => -1.0),
            array('neighbor_id' => 'c', 'yaw' => 3.0, 'pitch' => 4.0, 'default_yaw' => null, 'default_pitch' => null),
        ), $found['neighbors']);
    }

    /** @dataProvider models */
    public function testFindGivesAnOwnerWithNoLinksAnEmptyList($class, $edges, $owner, $ownerTable)
    {
        $model = $this->model($class, $this->tables($edges, $owner, $ownerTable));

        $this->assertSame(array(), $model->find('c')['neighbors']);
    }

    /** @dataProvider models */
    public function testGetAllGroupsEveryOwnersNeighbors($class, $edges, $owner, $ownerTable)
    {
        $model = $this->model($class, $this->tables($edges, $owner, $ownerTable));

        $byId = array();
        foreach ($model->getAll() as $row) {
            $byId[$row['id']] = $row['neighbors'];
        }

        $this->assertSame(array('b', 'c'), array_column($byId['a'], 'neighbor_id'));
        $this->assertSame(array('a'), array_column($byId['b'], 'neighbor_id'));
        $this->assertSame(array(), $byId['c']);
    }

    /** @dataProvider models */
    public function testFindOfAnUnknownOwnerIsNull($class, $edges, $owner, $ownerTable)
    {
        $model = $this->model($class, $this->tables($edges, $owner, $ownerTable));

        $this->assertNull($model->find('nope'));
    }
}
