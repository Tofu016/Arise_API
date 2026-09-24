<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Neighbor_actions.php';

// Same conventions throughout — getAll() public, writes behind
// requireAdmin(). Two extra validation steps exist here specifically
// because nodes has real constraints tour_stops didn't: create()
// checks the building actually exists before inserting (a required FK,
// unlike tour_stops' optional section_id), and addMarker() checks the
// type against the schema's real ENUM before inserting — both to turn
// what would otherwise be a raw SQL error into a clear message. Elevator
// markers are landings of a row in `elevators` (see Elevators_API) and
// are checked against it before they're written.
class Nodes_API extends MY_Controller
{
    use Neighbor_actions;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Nodes_Model');
        $this->load->model('Buildings_Model');
        $this->load->model('Elevators_Model');
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
    // leads_to_floors (all optional). Accepts a client-provided id — see
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

        $leadsToFloors = $this->parseLeadsToFloors(isset($data['leads_to_floors']) ? $data['leads_to_floors'] : null);
        if ($leadsToFloors === null) {
            return Api_response::fail(400, 'leads_to_floors must be a list of distinct whole-number floors.');
        }

        $node = $this->Nodes_Model->create(
            $name,
            $building,
            $floor,
            $type,
            isset($data['photo_path']) ? $data['photo_path'] : null,
            $requestedId,
            $leadsToFloors
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
            'leads_to_floors', 'flowchart_position_x', 'flowchart_position_y', 'is_starting_node',
            'starting_view_yaw', 'starting_view_pitch', 'is_campus_entrance', 'is_building_entrance',
        );
        $patch = Api_input::patch($data, $allowed);

        // Same check as create() — only relevant if building is
        // actually part of this particular update.
        if (isset($patch['building']) && !$this->Buildings_Model->find($patch['building'])) {
            return Api_response::fail(400, "Building '{$patch['building']}' does not exist.");
        }

        if (array_key_exists('leads_to_floors', $patch)) {
            $leadsToFloors = $this->parseLeadsToFloors($patch['leads_to_floors']);
            if ($leadsToFloors === null) {
                return Api_response::fail(400, 'leads_to_floors must be a list of distinct whole-number floors.');
            }
            $patch['leads_to_floors'] = $leadsToFloors;
        }

        if (isset($patch['floor']) || isset($patch['building'])) {
            $conflict = $this->landingConflict($id, $patch);
            if ($conflict !== null) {
                return Api_response::fail(409, $conflict);
            }
        }

        $node = $this->Nodes_Model->update($id, $patch);
        return Api_response::ok(array('node' => $node));
    }

    // A JSON array or a comma string -> distinct, sorted ints, or an empty
    // array for null/""/[] (leads_to_floors is optional, unlike an
    // elevator's accessible_floors — a node isn't required to be a stairs
    // node at all, and even a stairs node's floors are only enforced
    // client-side). Returns null only when the input isn't a list of whole
    // numbers at all.
    private function parseLeadsToFloors($raw)
    {
        if ($raw === null || $raw === '') {
            return array();
        }
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return null;
        }

        $floors = array();
        foreach ($raw as $floor) {
            $floor = is_string($floor) ? trim($floor) : $floor;
            if ($floor === '') {
                continue;
            }
            if (is_int($floor) || (is_string($floor) && preg_match('/^-?\d+$/', $floor))) {
                $floors[] = (int) $floor;
            } else {
                return null;
            }
        }
        $floors = array_values(array_unique($floors));
        sort($floors);
        return $floors;
    }

    // Moving a node moves any elevator landing on it, which must stay in
    // the elevator's building, on one of its floors, and alone on that
    // floor. Returns the reason it can't, or null.
    private function landingConflict($nodeId, array $patch)
    {
        $elevators = $this->Elevators_Model->elevatorsLandingAt($nodeId) ?: array();
        foreach ($elevators as $elevator) {
            $name = "elevator '{$elevator['id']}'";
            if (isset($patch['building']) && $patch['building'] !== $elevator['building']) {
                return "This node holds a landing of {$name}, which is in building '{$elevator['building']}'. Remove the landing first.";
            }
            if (!isset($patch['floor'])) {
                continue;
            }
            $floor = (int) $patch['floor'];
            if (!in_array($floor, $elevator['accessible_floors'], true)) {
                return "This node holds a landing of {$name}, which doesn't stop at floor {$floor}. Remove the landing first.";
            }
            foreach ($elevator['landings'] as $landing) {
                if ($landing['node_id'] !== $nodeId && (int) $landing['floor'] === $floor) {
                    return "This node holds a landing of {$name}, which already has a landing on floor {$floor} (node {$landing['node_id']}).";
                }
            }
        }
        return null;
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

    // The rules every elevator landing has to satisfy, shared by
    // addMarker and updateMarker. Returns the elevator, or aborts with the
    // reply. $markerId is the landing being edited, so it doesn't collide
    // with itself.
    private function requireValidLanding($elevatorId, $nodeId, $markerId = null)
    {
        $elevatorId = trim((string) $elevatorId);
        if ($elevatorId === '') {
            throw new Api_abort(Api_response::fail(400, 'elevator_id is required for an elevator marker.'));
        }

        $elevator = $this->Elevators_Model->find($elevatorId);
        if (!$elevator) {
            throw new Api_abort(Api_response::fail(400, "Elevator '{$elevatorId}' does not exist."));
        }

        $node = $this->Nodes_Model->find($nodeId);
        if (!$node) {
            throw new Api_abort(Api_response::fail(400, "Node '{$nodeId}' does not exist."));
        }
        if ($node['building'] !== $elevator['building']) {
            throw new Api_abort(Api_response::fail(400, "Node '{$nodeId}' is in building '{$node['building']}', but elevator '{$elevatorId}' is in '{$elevator['building']}'."));
        }

        $floor = (int) $node['floor'];
        if (!in_array($floor, $elevator['accessible_floors'], true)) {
            $floors = implode(', ', $elevator['accessible_floors']);
            throw new Api_abort(Api_response::fail(400, "Floor {$floor} is not one of elevator '{$elevatorId}'s floors ({$floors})."));
        }

        foreach ($elevator['landings'] as $landing) {
            if ((int) $landing['floor'] === $floor && (string) $landing['marker_id'] !== (string) $markerId) {
                throw new Api_abort(Api_response::fail(409, "Elevator '{$elevatorId}' already has a landing on floor {$floor} (node {$landing['node_id']})."));
            }
        }

        return $elevator;
    }

    // POST /Nodes_API/addMarker — admin only.
    // Body: node_id, type (room|facility|exit|hydrant|elevator), label,
    // yaw, pitch, and — elevator only — elevator_id. An elevator marker's
    // label is optional: it always displays the elevator's own label.
    public function addMarker()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $isElevator = isset($data['type']) && $data['type'] === 'elevator';
        Api_input::requirePresent($data, $isElevator
            ? array('node_id', 'type', 'yaw', 'pitch')
            : array('node_id', 'type', 'label', 'yaw', 'pitch'));

        if (!$this->Nodes_Model->isValidMarkerType($data['type'])) {
            $allowed = implode(', ', $this->Nodes_Model->getAllowedMarkerTypes());
            return Api_response::fail(400, "Invalid marker type. Must be one of: {$allowed}");
        }

        $label = isset($data['label']) ? $data['label'] : null;
        $elevatorId = null;
        if ($isElevator) {
            $elevator = $this->requireValidLanding(isset($data['elevator_id']) ? $data['elevator_id'] : '', $data['node_id']);
            $elevatorId = $elevator['id'];
            // The column is NOT NULL; reads show the elevator's label anyway.
            if ($label === null || trim((string) $label) === '') {
                $label = $elevator['label'];
            }
        }

        $markerId = $this->Nodes_Model->addMarker(
            $data['node_id'],
            $data['type'],
            $label,
            $data['yaw'],
            $data['pitch'],
            $elevatorId
        );

        return Api_response::ok(array('marker_id' => $markerId));
    }

    // PATCH /Nodes_API/updateMarker/{marker_id} — admin only. The landing
    // rules only re-run when the marker's elevator actually changes (or it
    // becomes an elevator), so moving just yaw/pitch never needs them.
    public function updateMarker($markerId = null)
    {
        $this->requireAdmin();

        Api_input::requireId($markerId, 'marker');

        $data = $this->getInput();
        $allowed = array('type', 'label', 'yaw', 'pitch', 'elevator_id');
        $patch = Api_input::patch($data, $allowed);

        if (isset($patch['type']) && !$this->Nodes_Model->isValidMarkerType($patch['type'])) {
            $allowedTypes = implode(', ', $this->Nodes_Model->getAllowedMarkerTypes());
            return Api_response::fail(400, "Invalid marker type. Must be one of: {$allowedTypes}");
        }

        if (isset($patch['type']) && $patch['type'] !== 'elevator') {
            // Left pointing at an elevator, the cascade would delete this
            // no-longer-elevator marker along with it.
            $patch['elevator_id'] = null;
        } elseif (array_key_exists('elevator_id', $patch) || isset($patch['type'])) {
            $marker = $this->Nodes_Model->findMarker($markerId);
            if (!$marker) {
                return Api_response::fail(404, 'Marker not found.');
            }
            if ($marker['type'] !== 'elevator' && !isset($patch['type'])) {
                return Api_response::fail(400, 'elevator_id only applies to elevator markers.');
            }

            $elevatorId = array_key_exists('elevator_id', $patch) ? trim((string) $patch['elevator_id']) : $marker['elevator_id'];
            if ($marker['type'] !== 'elevator' || $elevatorId !== $marker['elevator_id']) {
                $elevatorId = $this->requireValidLanding($elevatorId, $marker['node_id'], $markerId)['id'];
            }
            $patch['elevator_id'] = $elevatorId;
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
