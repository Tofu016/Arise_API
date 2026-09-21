<?php
// Exhaustive pins for the two repeated request shapes, so a call site
// can't silently lose a field from its list:
//   - every required field of every "Missing field: X" action, alone;
//   - every field an update accepts, alone (and nothing outside the list).
class RequiredFieldsAndWhitelistsTest extends ActionTestCase
{
    private function accepting()
    {
        return array(
            'Buildings_Model' => array('find' => true),
            'Nodes_Model' => array('isValidMarkerType' => true),
            'PlacardDialogs_Model' => array('roomNameExists' => false),
        );
    }

    // ---- required fields ------------------------------------------

    /** @dataProvider requiredFieldSites */
    public function testEachRequiredFieldIsRequired($harness, $action, array $fields, $missing)
    {
        $body = array_fill_keys($fields, 1);
        unset($body[$missing]);

        $this->assertReply($this->call($harness, $action, array(), $body, $this->accepting()), 400, "Missing field: {$missing}");
    }

    /** @dataProvider requiredFieldSites */
    public function testAllRequiredFieldsTogetherAreAccepted($harness, $action, array $fields)
    {
        $body = array_fill_keys($fields, 1);

        $this->assertReply($this->call($harness, $action, array(), $body, $this->accepting()), 200);
    }

    public function requiredFieldSites()
    {
        $sites = array(
            array('NodesApiHarness', 'addNeighbor', array('node_id', 'neighbor_id', 'yaw', 'pitch', 'reverse_yaw', 'reverse_pitch')),
            array('NodesApiHarness', 'updateNeighborAngle', array('node_id', 'neighbor_id', 'yaw', 'pitch')),
            array('NodesApiHarness', 'addMarker', array('node_id', 'type', 'label', 'yaw', 'pitch')),
            array('TourStopsApiHarness', 'addNeighbor', array('stop_id', 'neighbor_id', 'yaw', 'pitch', 'reverse_yaw', 'reverse_pitch')),
            array('TourStopsApiHarness', 'updateNeighborAngle', array('stop_id', 'neighbor_id', 'yaw', 'pitch')),
            array('TourStopsApiHarness', 'addMarker', array('stop_id', 'label', 'yaw', 'pitch')),
        );

        $cases = array();
        foreach ($sites as $site) {
            foreach ($site[2] as $missing) {
                $cases["{$site[0]}::{$site[1]} without {$missing}"] = array($site[0], $site[1], $site[2], $missing);
            }
        }
        return $cases;
    }

    // ---- update whitelists ----------------------------------------

    /** @dataProvider whitelistSites */
    public function testEachAllowedFieldAloneIsAValidUpdate($harness, $action, array $params, $field, $value)
    {
        $this->assertReply($this->call($harness, $action, $params, array($field => $value), $this->accepting()), 200);
    }

    /** @dataProvider whitelistSites */
    public function testFieldsOutsideTheListNeverReachTheModel($harness, $action, array $params, $field, $value)
    {
        $this->call($harness, $action, $params, array($field => $value, 'id' => 'hijack', 'created_at' => 'x'), $this->accepting());

        foreach ($this->controller->modelNames as $model) {
            foreach ($this->controller->$model->calls as $call) {
                foreach ($call[1] as $arg) {
                    if (is_array($arg) && !array_key_exists('photos', $arg)) {
                        $this->assertArrayNotHasKey('id', $arg);
                        $this->assertArrayNotHasKey('created_at', $arg);
                    }
                }
            }
        }
    }

    public function whitelistSites()
    {
        $sites = array(
            array('BuildingsApiHarness', 'update', array('gd1'), array('name' => 'x', 'floor_count' => 2, 'lat' => 1.5, 'lng' => 2.5)),
            array('TourSectionsApiHarness', 'update', array('1'), array('label' => 'x', 'cover_photo_path' => 'tourcover/a.jpg')),
            array('NodesApiHarness', 'update', array('a'), array(
                'name' => 'x', 'building' => 'gd1', 'floor' => 2, 'type' => 'hallway', 'photo_path' => 'panoramas/gd1/a.jpg',
                'leads_to_floor' => 3, 'flowchart_position_x' => 1, 'flowchart_position_y' => 2, 'is_starting_node' => 1,
            )),
            array('NodesApiHarness', 'updateMarker', array('7'), array('type' => 'room', 'label' => 'x', 'yaw' => 1, 'pitch' => 2)),
            array('TourStopsApiHarness', 'update', array('a'), array('name' => 'x', 'section_id' => '1', 'photo_path' => 'tourpanorama/a.jpg', 'description' => 'd')),
            array('TourStopsApiHarness', 'updateMarker', array('7'), array('label' => 'x', 'yaw' => 1, 'pitch' => 2)),
            array('PlacardDialogsApiHarness', 'update', array('1'), array(
                'room_name' => 'x', 'description' => 'd', 'department' => 'd', 'use' => 'u',
                'photo_path' => 'roomphoto/gd1/a.jpg', 'photo_360_path' => 'room360/gd1/a.webp', 'link' => 'l',
            )),
        );

        $cases = array();
        foreach ($sites as $site) {
            foreach ($site[3] as $field => $value) {
                $cases["{$site[0]}::{$site[1]} accepts {$field}"] = array($site[0], $site[1], $site[2], $field, $value);
            }
        }
        return $cases;
    }
}
