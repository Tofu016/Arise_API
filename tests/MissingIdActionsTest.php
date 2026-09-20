<?php
// Every action that takes an id from the URL refuses a missing one the
// same way — "missing" meaning PHP's empty(), so an id of "0" or "" is
// refused too. Pinned per action so no call site can drift.
class MissingIdActionsTest extends ActionTestCase
{
    /** @dataProvider sites */
    public function testAMissingIdIsRefused($harness, $action, $message, $id)
    {
        $params = $id === null ? array() : array($id);

        $this->assertReply($this->call($harness, $action, $params, array('name' => 'x', 'label' => 'x', 'role' => 'user')), 400, $message);
    }

    public function sites()
    {
        $sites = array(
            array('NodesApiHarness', 'update', 'Missing node id.'),
            array('NodesApiHarness', 'delete', 'Missing node id.'),
            array('NodesApiHarness', 'updateMarker', 'Missing marker id.'),
            array('NodesApiHarness', 'deleteMarker', 'Missing marker id.'),
            array('NodesApiHarness', 'removeRoom', 'Missing room id.'),
            array('TourStopsApiHarness', 'update', 'Missing stop id.'),
            array('TourStopsApiHarness', 'delete', 'Missing stop id.'),
            array('TourStopsApiHarness', 'updateMarker', 'Missing marker id.'),
            array('TourStopsApiHarness', 'deleteMarker', 'Missing marker id.'),
            array('BuildingsApiHarness', 'update', 'Missing building id.'),
            array('BuildingsApiHarness', 'delete', 'Missing building id.'),
            array('UsersApiHarness', 'updateRole', 'Missing user id.'),
            array('UsersApiHarness', 'delete', 'Missing user id.'),
            array('TourSectionsApiHarness', 'update', 'Missing section id.'),
            array('TourSectionsApiHarness', 'delete', 'Missing section id.'),
            array('PlacardDialogsApiHarness', 'update', 'Missing dialog id.'),
            array('PlacardDialogsApiHarness', 'delete', 'Missing dialog id.'),
        );

        $cases = array();
        foreach ($sites as $site) {
            foreach (array('none' => null, 'empty string' => '', 'zero' => '0') as $label => $id) {
                $cases["{$site[0]}::{$site[1]} with {$label}"] = array($site[0], $site[1], $site[2], $id);
            }
        }
        return $cases;
    }
}
