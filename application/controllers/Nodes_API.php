<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Neighbor_actions.php';

// Same conventions throughout — getAll() public, writes behind
// requireAdmin(). Two extra validation steps exist here specifically
// because nodes has real constraints tour_stops didn't: create()
// checks the building actually exists before inserting (a required FK,
// unlike tour_stops' optional section_id), and addMarker() checks the
// type against the schema's real ENUM before inserting — both to turn
// what would otherwise be a raw SQL error into a clear message.
class Nodes_API extends MY_Controller
{
    use Neighbor_actions;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Nodes_Model');
        $this->load->model('Buildings_Model');
    }

    // GET /Nodes_API/getAll — public, same reasoning as tour_stops/
    // buildings: the general navigation UI needs this, not just admins.
    public function getAll()
    {
        $nodes = $this->Nodes_Model->getAll();
        return Api_response::ok(array('nodes' => $nodes));
    }

    // POST /Nodes_API/create — admin only.
    // Body: name, building, floor, type (all required), photo_path, id,
    // leads_to_floor (all optional). Accepts a client-provided id — see
    // Nodes_Model::create's own comment for why.
    public function create()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $name = isset($data['name']) ? $data['name'] : null;
        $building = isset($data['building']) ? $data['building'] : null;
        $floor = isset($data['floor']) ? $data['floor'] : null;
        $type = isset($data['type']) ? $data['type'] : null;
        $requestedId = isset($data['id']) ? trim($data['id']) : null;

        if (empty(trim((string) $name)) || empty($building) || $floor === null || empty(trim((string) $type))) {
            return Api_response::fail(400, 'name, building, floor, and type are all required.');
        }

        if (!$this->Buildings_Model->find($building)) {
            return Api_response::fail(400, "Building '{$building}' does not exist.");
        }

        if (!empty($requestedId) && $this->Nodes_Model->idExists($requestedId)) {
            return Api_response::fail(409, "ID '{$requestedId}' is already used by another node.");
        }

        $node = $this->Nodes_Model->create(
            $name,
            $building,
            $floor,
            $type,
            isset($data['photo_path']) ? $data['photo_path'] : null,
            $requestedId,
            isset($data['leads_to_floor']) ? $data['leads_to_floor'] : null
        );

        return Api_response::ok(array('node' => $node));
    }

    // PATCH /Nodes_API/rename/{oldId} — admin only.
    // Body: new_id
    public function rename($oldId = null)
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $newId = isset($data['new_id']) ? trim($data['new_id']) : '';

        if (empty($oldId) || $newId === '') {
            return Api_response::fail(400, 'Both the current and new id are required.');
        }
        if ($this->Nodes_Model->idExists($newId)) {
            return Api_response::fail(409, "ID '{$newId}' is already used by another node.");
        }

        $node = $this->Nodes_Model->renameNode($oldId, $newId);
        return Api_response::ok(array('node' => $node));
    }

    // PATCH /Nodes_API/update/{id} — admin only.
    public function update($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'node');

        $data = $this->getInput();
        $allowed = array(
            'name', 'building', 'floor', 'type', 'photo_path',
            'leads_to_floor', 'flowchart_position_x', 'flowchart_position_y',
        );
        $patch = Api_input::patch($data, $allowed);

        // Same check as create() — only relevant if building is
        // actually part of this particular update.
        if (isset($patch['building']) && !$this->Buildings_Model->find($patch['building'])) {
            return Api_response::fail(400, "Building '{$patch['building']}' does not exist.");
        }

        $node = $this->Nodes_Model->update($id, $patch);
        return Api_response::ok(array('node' => $node));
    }

    // DELETE /Nodes_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'node');

        $this->Nodes_Model->delete($id);
        return Api_response::ok();
    }

    // addNeighbor, removeNeighbor and updateNeighborAngle are shared with
    // TourStops_API — see Neighbor_actions. Only these two hooks differ.
    protected function neighborOwnerField()
    {
        return 'node_id';
    }

    protected function neighborModel()
    {
        return $this->Nodes_Model;
    }

    // POST /Nodes_API/addMarker — admin only.
    // Body: node_id, type (room|facility|exit|hydrant), label, yaw, pitch
    public function addMarker()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        Api_input::requirePresent($data, array('node_id', 'type', 'label', 'yaw', 'pitch'));

        if (!$this->Nodes_Model->isValidMarkerType($data['type'])) {
            $allowed = implode(', ', $this->Nodes_Model->getAllowedMarkerTypes());
            return Api_response::fail(400, "Invalid marker type. Must be one of: {$allowed}");
        }

        $markerId = $this->Nodes_Model->addMarker(
            $data['node_id'],
            $data['type'],
            $data['label'],
            $data['yaw'],
            $data['pitch']
        );

        return Api_response::ok(array('marker_id' => $markerId));
    }

    // PATCH /Nodes_API/updateMarker/{marker_id} — admin only.
    public function updateMarker($markerId = null)
    {
        $this->requireAdmin();

        Api_input::requireId($markerId, 'marker');

        $data = $this->getInput();
        $allowed = array('type', 'label', 'yaw', 'pitch');
        $patch = Api_input::patch($data, $allowed);

        if (isset($patch['type']) && !$this->Nodes_Model->isValidMarkerType($patch['type'])) {
            $allowedTypes = implode(', ', $this->Nodes_Model->getAllowedMarkerTypes());
            return Api_response::fail(400, "Invalid marker type. Must be one of: {$allowedTypes}");
        }

        $this->Nodes_Model->updateMarker($markerId, $patch);
        return Api_response::ok();
    }

    // DELETE /Nodes_API/deleteMarker/{marker_id} — admin only.
    public function deleteMarker($markerId = null)
    {
        $this->requireAdmin();

        Api_input::requireId($markerId, 'marker');

        $this->Nodes_Model->deleteMarker($markerId);
        return Api_response::ok();
    }

    // POST /Nodes_API/addRoom — admin only.
    // Body: node_id, room_name
    public function addRoom()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $nodeId = isset($data['node_id']) ? $data['node_id'] : null;
        $roomName = isset($data['room_name']) ? $data['room_name'] : null;

        if (empty($nodeId) || empty(trim((string) $roomName))) {
            return Api_response::fail(400, 'node_id and room_name are both required.');
        }

        $roomRowId = $this->Nodes_Model->addRoom($nodeId, $roomName);
        return Api_response::ok(array('room_id' => $roomRowId));
    }

    // DELETE /Nodes_API/removeRoom/{room_row_id} — admin only.
    public function removeRoom($roomRowId = null)
    {
        $this->requireAdmin();

        Api_input::requireId($roomRowId, 'room');

        $this->Nodes_Model->removeRoom($roomRowId);
        return Api_response::ok();
    }
}
