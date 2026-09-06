<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Same conventions throughout — getAll() public, writes behind
// requireAdmin(). Two extra validation steps exist here specifically
// because nodes has real constraints tour_stops didn't: create()
// checks the building actually exists before inserting (a required FK,
// unlike tour_stops' optional section_id), and addMarker() checks the
// type against the schema's real ENUM before inserting — both to turn
// what would otherwise be a raw SQL error into a clear message.
class Nodes_API extends MY_Controller
{
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
        echo json_encode(array('success' => true, 'nodes' => $nodes));
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
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'name, building, floor, and type are all required.'));
            return;
        }

        if (!$this->Buildings_Model->find($building)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => "Building '{$building}' does not exist."));
            return;
        }

        if (!empty($requestedId) && $this->Nodes_Model->idExists($requestedId)) {
            http_response_code(409);
            echo json_encode(array('success' => false, 'error' => "ID '{$requestedId}' is already used by another node."));
            return;
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

        echo json_encode(array('success' => true, 'node' => $node));
    }

    // PATCH /Nodes_API/rename/{oldId} — admin only.
    // Body: new_id
    public function rename($oldId = null)
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $newId = isset($data['new_id']) ? trim($data['new_id']) : '';

        if (empty($oldId) || $newId === '') {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Both the current and new id are required.'));
            return;
        }
        if ($this->Nodes_Model->idExists($newId)) {
            http_response_code(409);
            echo json_encode(array('success' => false, 'error' => "ID '{$newId}' is already used by another node."));
            return;
        }

        $node = $this->Nodes_Model->renameNode($oldId, $newId);
        echo json_encode(array('success' => true, 'node' => $node));
    }

    // PATCH /Nodes_API/update/{id} — admin only.
    public function update($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing node id.'));
            return;
        }

        $data = $this->getInput();
        $allowed = array(
            'name', 'building', 'floor', 'type', 'photo_path',
            'leads_to_floor', 'flowchart_position_x', 'flowchart_position_y',
        );
        $patch = array_intersect_key($data, array_flip($allowed));

        if (empty($patch)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'No valid fields to update.'));
            return;
        }

        // Same check as create() — only relevant if building is
        // actually part of this particular update.
        if (isset($patch['building']) && !$this->Buildings_Model->find($patch['building'])) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => "Building '{$patch['building']}' does not exist."));
            return;
        }

        $node = $this->Nodes_Model->update($id, $patch);
        echo json_encode(array('success' => true, 'node' => $node));
    }

    // DELETE /Nodes_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing node id.'));
            return;
        }

        $this->Nodes_Model->delete($id);
        echo json_encode(array('success' => true));
    }

    // POST /Nodes_API/addNeighbor — admin only.
    // Body: node_id, neighbor_id, yaw, pitch, reverse_yaw, reverse_pitch
    public function addNeighbor()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $required = array('node_id', 'neighbor_id', 'yaw', 'pitch', 'reverse_yaw', 'reverse_pitch');
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                http_response_code(400);
                echo json_encode(array('success' => false, 'error' => "Missing field: {$field}"));
                return;
            }
        }

        $this->Nodes_Model->addNeighbor(
            $data['node_id'],
            $data['neighbor_id'],
            $data['yaw'],
            $data['pitch'],
            $data['reverse_yaw'],
            $data['reverse_pitch']
        );

        echo json_encode(array('success' => true));
    }

    // POST /Nodes_API/removeNeighbor — admin only.
    // Body: node_id, neighbor_id
    public function removeNeighbor()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        if (empty($data['node_id']) || empty($data['neighbor_id'])) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'node_id and neighbor_id are both required.'));
            return;
        }

        $this->Nodes_Model->removeNeighbor($data['node_id'], $data['neighbor_id']);
        echo json_encode(array('success' => true));
    }

    // PATCH /Nodes_API/updateNeighborAngle — admin only.
    // Body: node_id, neighbor_id, yaw, pitch
    public function updateNeighborAngle()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $required = array('node_id', 'neighbor_id', 'yaw', 'pitch');
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                http_response_code(400);
                echo json_encode(array('success' => false, 'error' => "Missing field: {$field}"));
                return;
            }
        }

        $this->Nodes_Model->updateNeighborAngle(
            $data['node_id'],
            $data['neighbor_id'],
            $data['yaw'],
            $data['pitch']
        );
        echo json_encode(array('success' => true));
    }

    // POST /Nodes_API/addMarker — admin only.
    // Body: node_id, type (room|facility|exit|hydrant), label, yaw, pitch
    public function addMarker()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $required = array('node_id', 'type', 'label', 'yaw', 'pitch');
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                http_response_code(400);
                echo json_encode(array('success' => false, 'error' => "Missing field: {$field}"));
                return;
            }
        }

        if (!$this->Nodes_Model->isValidMarkerType($data['type'])) {
            $allowed = implode(', ', $this->Nodes_Model->getAllowedMarkerTypes());
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => "Invalid marker type. Must be one of: {$allowed}"));
            return;
        }

        $markerId = $this->Nodes_Model->addMarker(
            $data['node_id'],
            $data['type'],
            $data['label'],
            $data['yaw'],
            $data['pitch']
        );

        echo json_encode(array('success' => true, 'marker_id' => $markerId));
    }

    // PATCH /Nodes_API/updateMarker/{marker_id} — admin only.
    public function updateMarker($markerId = null)
    {
        $this->requireAdmin();

        if (empty($markerId)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing marker id.'));
            return;
        }

        $data = $this->getInput();
        $allowed = array('type', 'label', 'yaw', 'pitch');
        $patch = array_intersect_key($data, array_flip($allowed));

        if (isset($patch['type']) && !$this->Nodes_Model->isValidMarkerType($patch['type'])) {
            $allowedTypes = implode(', ', $this->Nodes_Model->getAllowedMarkerTypes());
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => "Invalid marker type. Must be one of: {$allowedTypes}"));
            return;
        }

        if (empty($patch)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'No valid fields to update.'));
            return;
        }

        $this->Nodes_Model->updateMarker($markerId, $patch);
        echo json_encode(array('success' => true));
    }

    // DELETE /Nodes_API/deleteMarker/{marker_id} — admin only.
    public function deleteMarker($markerId = null)
    {
        $this->requireAdmin();

        if (empty($markerId)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing marker id.'));
            return;
        }

        $this->Nodes_Model->deleteMarker($markerId);
        echo json_encode(array('success' => true));
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
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'node_id and room_name are both required.'));
            return;
        }

        $roomRowId = $this->Nodes_Model->addRoom($nodeId, $roomName);
        echo json_encode(array('success' => true, 'room_id' => $roomRowId));
    }

    // DELETE /Nodes_API/removeRoom/{room_row_id} — admin only.
    public function removeRoom($roomRowId = null)
    {
        $this->requireAdmin();

        if (empty($roomRowId)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing room id.'));
            return;
        }

        $this->Nodes_Model->removeRoom($roomRowId);
        echo json_encode(array('success' => true));
    }
}
