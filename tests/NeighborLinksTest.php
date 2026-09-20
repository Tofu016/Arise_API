<?php
use PHPUnit\Framework\TestCase;

// Neighbor_links knows nothing about nodes or tour stops: it works on
// whatever edge table and owner column it is given.
class NeighborLinksTest extends TestCase
{
    private $db;
    private $links;

    protected function setUp(): void
    {
        $this->db = new FakeDb();
        $this->links = new Neighbor_links($this->db, 'trail_edges', 'trail_id');
    }

    public function testLinkWritesBothDirectionsIntoTheGivenTableAndColumn()
    {
        $this->links->link('a', 'b', 1, 2, 3, 4);

        $this->assertSame(array(
            array('insert', 'trail_edges', array('trail_id' => 'a', 'neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2)),
            array('insert', 'trail_edges', array('trail_id' => 'b', 'neighbor_id' => 'a', 'yaw' => 3, 'pitch' => 4)),
        ), $this->db->log);
    }

    public function testEachDirectionKeepsItsOwnAngleEvenWhenTheyDiffer()
    {
        $this->links->link('a', 'b', 90, 0, 270, -5);

        $this->assertSame(array(90, 0), array($this->db->log[0][2]['yaw'], $this->db->log[0][2]['pitch']));
        $this->assertSame(array(270, -5), array($this->db->log[1][2]['yaw'], $this->db->log[1][2]['pitch']));
    }

    public function testUnlinkRemovesBothDirectionsAndNothingElse()
    {
        $this->links->unlink('a', 'b');

        $deletes = array_values(array_filter($this->db->log, function ($entry) {
            return $entry[0] === 'delete';
        }));
        $this->assertSame(array(array('delete', 'trail_edges'), array('delete', 'trail_edges')), $deletes);
        $this->assertSame(
            array(array('where', 'trail_id', 'a'), array('where', 'neighbor_id', 'b'), array('where', 'trail_id', 'b'), array('where', 'neighbor_id', 'a')),
            array_values(array_filter($this->db->log, function ($entry) {
                return $entry[0] === 'where';
            }))
        );
    }

    public function testSetAngleUpdatesOnlyTheOneDirectionAndReturnsTheResult()
    {
        $this->assertTrue($this->links->setAngle('a', 'b', 5, 6));

        $this->assertSame(array(
            array('where', 'trail_id', 'a'),
            array('where', 'neighbor_id', 'b'),
            array('update', 'trail_edges', array('yaw' => 5, 'pitch' => 6)),
        ), $this->db->log);
    }

    public function testGroupedByOwnerGroupsEveryEdgeUnderItsOwner()
    {
        $this->db->tables['trail_edges'] = array(
            array('id' => 1, 'trail_id' => 'a', 'neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2),
            array('id' => 2, 'trail_id' => 'a', 'neighbor_id' => 'c', 'yaw' => 3, 'pitch' => 4),
            array('id' => 3, 'trail_id' => 'b', 'neighbor_id' => 'a', 'yaw' => 5, 'pitch' => 6),
        );

        $this->assertSame(array(
            'a' => array(
                array('neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2),
                array('neighbor_id' => 'c', 'yaw' => 3, 'pitch' => 4),
            ),
            'b' => array(array('neighbor_id' => 'a', 'yaw' => 5, 'pitch' => 6)),
        ), $this->links->groupedByOwner());
    }

    public function testGroupedByOwnerCanBeScopedToOneOwner()
    {
        $this->db->tables['trail_edges'] = array(
            array('id' => 1, 'trail_id' => 'a', 'neighbor_id' => 'b', 'yaw' => 1, 'pitch' => 2),
            array('id' => 3, 'trail_id' => 'b', 'neighbor_id' => 'a', 'yaw' => 5, 'pitch' => 6),
        );

        $this->assertSame(array('b'), array_keys($this->links->groupedByOwner('b')));
    }

    public function testAnOwnerWithNoEdgesIsSimplyAbsent()
    {
        $this->assertSame(array(), $this->links->groupedByOwner('nobody'));
    }
}
