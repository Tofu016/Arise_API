<?php
use PHPUnit\Framework\TestCase;

// Flagging a campus entrance clears the flag on the rest of the same campus.
// A campus is the built-in GD1/GD2/GD3 cluster plus whatever an admin grouped
// through buildings.campus_id. NodesModelHarness is declared in
// NeighborModelsTest.php and shared across the suite.
class CampusEntranceTest extends TestCase
{
    private function clearedBuildings(array $buildings, $nodeBuilding)
    {
        $model = new NodesModelHarness();
        $model->db = new FakeDb();
        $model->db->tables = array(
            'buildings' => $buildings,
            'nodes' => array(array('id' => 'n1', 'building' => $nodeBuilding)),
        );

        $model->update('n1', array('is_campus_entrance' => 1));

        foreach ($model->db->log as $entry) {
            if ($entry[0] === 'where_in' && $entry[1] === 'building') {
                return $entry[2];
            }
        }
        return null;
    }

    public function testBuiltInMainCampusStaysOneCampusWithNoBuildingRows()
    {
        $this->assertSame(array('gd1', 'gd2', 'gd3'), $this->clearedBuildings(array(), 'gd2'));
    }

    public function testUngroupedBuildingIsItsOwnCampus()
    {
        $buildings = array(array('id' => 'dc', 'campus_id' => null));

        $this->assertSame(array('dc'), $this->clearedBuildings($buildings, 'dc'));
    }

    public function testBuildingsGroupedByCampusIdShareOneCampus()
    {
        $buildings = array(
            array('id' => 'dc', 'campus_id' => null),
            array('id' => 'dc2', 'campus_id' => 'dc'),
        );

        $this->assertEqualsCanonicalizing(array('dc', 'dc2'), $this->clearedBuildings($buildings, 'dc2'));
        $this->assertEqualsCanonicalizing(array('dc', 'dc2'), $this->clearedBuildings($buildings, 'dc'));
    }
}
