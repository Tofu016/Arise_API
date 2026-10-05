<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Neighbor_links.php';
// For Elevators_Model::parseFloors; CI's loader skips an already-declared class.
require_once APPPATH . 'models/Elevators_Model.php';

// The most complex resource — same bidirectional neighbor-link pattern
// as TourStops_Model, plus:
//  - a REQUIRED (not optional) foreign key into buildings
//  - node_markers.type is a real ENUM in the schema (room/facility/
//    emergency_exit/fire_extinguisher/elevator) — an
//    invalid value here would otherwise surface as a raw MySQL error,
//    so it's validated explicitly before ever reaching the database
//  - an emergency_exit marker is what makes a node a fire exit node: its
//    landing nodes (node_marker_landings) are where the hidden fire stairs
//    come out, used only by Nearest Exit routing
//  - node_rooms: a separate, simple one-to-many list of room names —
//    a loose, name-based reference (no FK to placard_dialogs), matching
//    the original: a node can list a room name before that room's own
//    details even exist yet
//  - markers here have no photos at all
class Nodes_Model extends CI_Model
{
    private $table = 'nodes';
    private $allowedMarkerTypes = array('room', 'facility', 'emergency_exit', 'fire_extinguisher', 'elevator');

    // GD1/GD2/GD3 are separate buildings but one physical campus (see
    // buildingStore.js's own HARDCODED_IDS on the frontend for the same
    // distinction). They are built in, so they count as one campus even with
    // no buildings row saying so; every other grouping comes from
    // buildings.campus_id (see campusBuildingIds).
    private $mainCampusBuildingIds = array('gd1', 'gd2', 'gd3');

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function getAllowedMarkerTypes()
    {
        return $this->allowedMarkerTypes;
    }

    // ---------- Reads ----------

    public function getAll()
    {
        $nodes = $this->_getNodeRows();
        $neighborsByNode = $this->_getNeighborsGrouped();
        $markersByNode = $this->_getMarkersGrouped();
        $roomsByNode = $this->_getRoomsGrouped();

        foreach ($nodes as &$node) {
            $id = $node['id'];
            $node['neighbors'] = isset($neighborsByNode[$id]) ? $neighborsByNode[$id] : array();
            $node['markers'] = isset($markersByNode[$id]) ? $markersByNode[$id] : array();
            $node['rooms'] = isset($roomsByNode[$id]) ? $roomsByNode[$id] : array();
        }
        unset($node);

        return $nodes;
    }

    public function find($id)
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->where('id', $id);
        $row = $this->db->get()->row_array();
        if (!$row) {
            return null;
        }

        $neighborsByNode = $this->_getNeighborsGrouped($id);
        $markersByNode = $this->_getMarkersGrouped($id);
        $roomsByNode = $this->_getRoomsGrouped($id);
        $row['neighbors'] = isset($neighborsByNode[$id]) ? $neighborsByNode[$id] : array();
        $row['markers'] = isset($markersByNode[$id]) ? $markersByNode[$id] : array();
        $row['rooms'] = isset($roomsByNode[$id]) ? $roomsByNode[$id] : array();

        return $row;
    }

    private function _getNodeRows()
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->order_by('building', 'ASC');
        $this->db->order_by('floor', 'ASC');
        $this->db->order_by('name', 'ASC');
        return $this->db->get()->result_array();
    }

    // The edge table and its owner column are the only things that differ
    // from TourStops_Model's neighbour links — see Neighbor_links.
    private function neighborLinks()
    {
        return new Neighbor_links($this->db, 'node_neighbors', 'node_id');
    }

    private function _getNeighborsGrouped($onlyNodeId = null)
    {
        return $this->neighborLinks()->groupedByOwner($onlyNodeId);
    }

    // An elevator marker's label and floors are joined in from `elevators`
    // on every read rather than copied onto the marker, so all landings of
    // one elevator always agree. An emergency exit marker carries its landing
    // node ids, lowest floor first, so a client reads the order a router
    // prefers.
    private function _getMarkersGrouped($onlyNodeId = null)
    {
        $this->db->select('node_markers.*, elevators.label AS elevator_label, elevators.accessible_floors AS elevator_floors');
        $this->db->from('node_markers');
        $this->db->join('elevators', 'elevators.id = node_markers.elevator_id', 'left');
        if ($onlyNodeId !== null) {
            $this->db->where('node_markers.node_id', $onlyNodeId);
        }
        $rows = $this->db->get()->result_array();

        $landings = $this->_getLandingsGrouped(array_column($rows, 'id'));

        $grouped = array();
        foreach ($rows as $row) {
            $isElevator = $row['type'] === 'elevator' && $row['elevator_label'] !== null;
            $grouped[$row['node_id']][] = array(
                'id' => $row['id'],
                'type' => $row['type'],
                'label' => $isElevator ? $row['elevator_label'] : $row['label'],
                'yaw' => $row['yaw'],
                'pitch' => $row['pitch'],
                'elevator_id' => $row['elevator_id'],
                'accessible_floors' => $isElevator ? Elevators_Model::parseFloors($row['elevator_floors']) : array(),
                'landings' => isset($landings[$row['id']]) ? $landings[$row['id']] : array(),
            );
        }
        return $grouped;
    }

    // marker id -> landing node ids, lowest floor first (then id, so the
    // order never changes between two reads). Plain queries rather than a
    // join, so the in-memory test database can run them too.
    private function _getLandingsGrouped(array $markerIds)
    {
        if (empty($markerIds)) {
            return array();
        }
        $this->db->select('marker_id, landing_node_id');
        $this->db->from('node_marker_landings');
        $this->db->where_in('marker_id', $markerIds);
        $rows = $this->db->get()->result_array();
        if (empty($rows)) {
            return array();
        }

        $this->db->select('id, floor');
        $this->db->from($this->table);
        $this->db->where_in('id', array_values(array_unique(array_column($rows, 'landing_node_id'))));
        $floors = array();
        foreach ($this->db->get()->result_array() as $node) {
            $floors[$node['id']] = (int) $node['floor'];
        }

        usort($rows, function ($a, $b) use ($floors) {
            $fa = isset($floors[$a['landing_node_id']]) ? $floors[$a['landing_node_id']] : 0;
            $fb = isset($floors[$b['landing_node_id']]) ? $floors[$b['landing_node_id']] : 0;
            return $fa === $fb ? strcmp($a['landing_node_id'], $b['landing_node_id']) : $fa - $fb;
        });

        $grouped = array();
        foreach ($rows as $row) {
            $grouped[$row['marker_id']][] = $row['landing_node_id'];
        }
        return $grouped;
    }

    private function _getRoomsGrouped($onlyNodeId = null)
    {
        $this->db->select('*');
        $this->db->from('node_rooms');
        if ($onlyNodeId !== null) {
            $this->db->where('node_id', $onlyNodeId);
        }
        $rows = $this->db->get()->result_array();

        $grouped = array();
        foreach ($rows as $row) {
            $grouped[$row['node_id']][] = array(
                'id' => $row['id'],
                'room_name' => $row['room_name'],
            );
        }
        return $grouped;
    }

    // ---------- Node CRUD ----------

    private function slugifyType($type)
    {
        $slug = strtolower(trim($type));
        $slug = preg_replace('/[^a-z0-9]+/', '', $slug);
        return $slug === '' ? 'node' : $slug;
    }

    // Matches the schema's own documented id pattern: {building}_f{floor}_{type}{sequence}
    // — e.g. "gd1_f1_hallway01" — a per-(building, floor, type)
    // sequence, not a global one, so "hallway01" can exist independently
    // in gd1 and gd2 without colliding.
    private function generateUniqueId($building, $floor, $type)
    {
        $base = strtolower($building) . '_f' . $floor . '_' . $this->slugifyType($type);
        $n = 1;
        do {
            $id = $base . str_pad($n, 2, '0', STR_PAD_LEFT);
            $n++;
        } while ($this->_nodeExists($id));
        return $id;
    }

    private function _nodeExists($id)
    {
        $this->db->select('id');
        $this->db->from($this->table);
        $this->db->where('id', $id);
        return $this->db->get()->num_rows() > 0;
    }

    public function idExists($id)
    {
        return $this->_nodeExists($id);
    }

    // $requestedId: NodeForm.jsx suggests/lets the admin edit the id
    // before saving, and NodeEditorPage.jsx selects that exact id
    // immediately after creating, without waiting for a server response
    // — same reasoning and contract as TourStops_Model::create.
    public function create($name, $building, $floor, $type, $photoPath = null, $requestedId = null)
    {
        $id = !empty($requestedId) ? $requestedId : $this->generateUniqueId($building, $floor, $type);
        $now = date('Y-m-d H:i:s');

        $data = array(
            'id' => $id,
            'name' => trim($name),
            'building' => $building,
            'floor' => $floor,
            'type' => $type,
            'created_at' => $now,
            'updated_at' => $now,
        );
        if (!empty($photoPath)) {
            $data['photo_path'] = $photoPath;
        }

        $this->db->insert($this->table, $data);
        return $this->find($id);
    }

    // Safe because of the ON UPDATE CASCADE fix on node_neighbors,
    // node_markers, AND node_rooms — three tables here, one more than
    // tour_stops needed, since a node's room list would otherwise be
    // silently orphaned by a rename too.
    public function renameNode($oldId, $newId)
    {
        $this->db->where('id', $oldId);
        $this->db->update($this->table, array(
            'id' => $newId,
            'updated_at' => date('Y-m-d H:i:s'),
        ));
        return $this->find($newId);
    }

    // Every building id in the same campus as $buildingId: the built-in
    // GD1/GD2/GD3 cluster, plus any buildings an admin grouped through
    // buildings.campus_id. A building with no campus_id is its own campus,
    // and a campus is named by the id of the building others join (the same
    // COALESCE(campus_id, id) rule as Buildings_Model).
    private function campusBuildingIds($buildingId)
    {
        $ids = array($buildingId);
        if (in_array($buildingId, $this->mainCampusBuildingIds, true)) {
            $ids = $this->mainCampusBuildingIds;
        }

        $this->db->where('id', $buildingId);
        $row = $this->db->get('buildings')->row_array();
        $campusId = ($row && !empty($row['campus_id'])) ? $row['campus_id'] : $buildingId;
        $ids[] = $campusId;

        $this->db->where('campus_id', $campusId);
        $members = $this->db->get('buildings')->result_array();
        foreach ($members as $member) {
            $ids[] = $member['id'];
        }
        return array_values(array_unique($ids));
    }

    public function update($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', $id);
        $this->db->update($this->table, $data);
        $node = $this->find($id);

        // One starting node per building floor: flagging this one clears
        // the flag on the rest of its floor.
        if ($node && !empty($data['is_starting_node'])) {
            $this->db->where('building', $node['building']);
            $this->db->where('floor', $node['floor']);
            $this->db->where('id !=', $id);
            $this->db->update($this->table, array('is_starting_node' => 0));
        }

        // One campus entrance per campus: flagging this one clears the flag
        // on every other node in the same campus (see campusBuildingIds).
        if ($node && !empty($data['is_campus_entrance'])) {
            $this->db->where_in('building', $this->campusBuildingIds($node['building']));
            $this->db->where('id !=', $id);
            $this->db->update($this->table, array('is_campus_entrance' => 0));
        }

        // One building entrance per building: flagging this one clears the
        // flag on the rest of its own building only (narrower scope than
        // campus entrance — a building entrance never crosses into GD2/GD3
        // just because they share a campus).
        if ($node && !empty($data['is_building_entrance'])) {
            $this->db->where('building', $node['building']);
            $this->db->where('id !=', $id);
            $this->db->update($this->table, array('is_building_entrance' => 0));
        }
        return $node;
    }

    public function delete($id)
    {
        // Neighbors (both directions), markers, rooms, and any emergency
        // exit landing pointing here all cascade-delete via the schema's own
        // foreign keys.
        $this->db->where('id', $id);
        return $this->db->delete($this->table);
    }

    // ---------- Neighbor links (bidirectional) ----------

    public function addNeighbor($nodeId, $neighborId, $yaw, $pitch, $reverseYaw, $reversePitch)
    {
        $this->neighborLinks()->link($nodeId, $neighborId, $yaw, $pitch, $reverseYaw, $reversePitch);
    }

    public function removeNeighbor($nodeId, $neighborId)
    {
        $this->neighborLinks()->unlink($nodeId, $neighborId);
    }

    // Updates ONE existing edge's angle only — see
    // Neighbor_links::setAngle for the full reasoning.
    public function updateNeighborAngle($nodeId, $neighborId, $yaw, $pitch)
    {
        return $this->neighborLinks()->setAngle($nodeId, $neighborId, $yaw, $pitch);
    }

    // The arrival view for this one edge only — see
    // Neighbor_links::setDefaultView. $yaw/$pitch null clears it.
    public function updateNeighborDefaultView($nodeId, $neighborId, $yaw, $pitch)
    {
        return $this->neighborLinks()->setDefaultView($nodeId, $neighborId, $yaw, $pitch);
    }

    // ---------- Markers (room / facility / emergency_exit / fire_extinguisher / elevator — no photos) ----------

    public function isValidMarkerType($type)
    {
        return in_array($type, $this->allowedMarkerTypes, true);
    }

    // The raw row (stored label, elevator_id), or null. Landings are read
    // separately (see markerLandingIds).
    public function findMarker($markerId)
    {
        $this->db->select('*');
        $this->db->from('node_markers');
        $this->db->where('id', $markerId);
        return $this->db->get()->row_array();
    }

    // $elevatorId is only set for the 'elevator' type; other types leave
    // the column NULL.
    public function addMarker($nodeId, $type, $label, $yaw, $pitch, $elevatorId = null)
    {
        $data = array(
            'node_id' => $nodeId,
            'type' => $type,
            'label' => $label,
            'yaw' => $yaw,
            'pitch' => $pitch,
        );
        if ($elevatorId !== null) {
            $data['elevator_id'] = $elevatorId;
        }
        $this->db->insert('node_markers', $data);
        return $this->db->insert_id();
    }

    public function updateMarker($markerId, $data)
    {
        $this->db->where('id', $markerId);
        return $this->db->update('node_markers', $data);
    }

    public function deleteMarker($markerId)
    {
        $this->db->where('id', $markerId);
        return $this->db->delete('node_markers');
    }

    // ---------- Emergency exit landings ----------

    public function markerLandingIds($markerId)
    {
        $this->db->select('landing_node_id');
        $this->db->from('node_marker_landings');
        $this->db->where('marker_id', $markerId);
        $ids = array();
        foreach ($this->db->get()->result_array() as $row) {
            $ids[] = $row['landing_node_id'];
        }
        return $ids;
    }

    // Replaces the whole landing list: the admin form always sends the list
    // as it should end up, so there is no add/remove pair to get out of step.
    public function setMarkerLandings($markerId, array $nodeIds)
    {
        $this->db->where('marker_id', $markerId);
        $this->db->delete('node_marker_landings');
        foreach (array_values(array_unique($nodeIds)) as $nodeId) {
            $this->db->insert('node_marker_landings', array('marker_id' => $markerId, 'landing_node_id' => $nodeId));
        }
    }

    // Every (source node, landing node) pair across all emergency exit
    // markers that involve $nodeId, either as the node carrying the marker
    // or as a landing target. Used to refuse a floor or building change that
    // would leave a landing on its own floor or in another building.
    public function landingPairsInvolving($nodeId)
    {
        $this->db->select('id');
        $this->db->from('node_markers');
        $this->db->where('node_id', $nodeId);
        $ownMarkerIds = array_column($this->db->get()->result_array(), 'id');

        $pairs = array();
        if (!empty($ownMarkerIds)) {
            $this->db->select('marker_id, landing_node_id');
            $this->db->from('node_marker_landings');
            $this->db->where_in('marker_id', $ownMarkerIds);
            foreach ($this->db->get()->result_array() as $row) {
                $pairs[] = array('source_id' => $nodeId, 'landing_id' => $row['landing_node_id']);
            }
        }

        $this->db->select('marker_id, landing_node_id');
        $this->db->from('node_marker_landings');
        $this->db->where('landing_node_id', $nodeId);
        $pointingHere = $this->db->get()->result_array();
        if (!empty($pointingHere)) {
            $this->db->select('id, node_id');
            $this->db->from('node_markers');
            $this->db->where_in('id', array_column($pointingHere, 'marker_id'));
            $sourceOf = array_column($this->db->get()->result_array(), 'node_id', 'id');
            foreach ($pointingHere as $row) {
                if (isset($sourceOf[$row['marker_id']])) {
                    $pairs[] = array('source_id' => $sourceOf[$row['marker_id']], 'landing_id' => $nodeId);
                }
            }
        }
        return $pairs;
    }

    // ---------- Rooms served (loose name references) ----------

    // The node already holding this room name, or null. Trim and
    // case-insensitive, matching checkDuplicateRooms on the frontend, so
    // "203" and " 203 " are the same room. Search needs one answer per name.
    public function findRoomOwner($roomName)
    {
        $this->db->select('node_id');
        $this->db->from('node_rooms');
        $this->db->where('LOWER(TRIM(room_name)) =', strtolower(trim($roomName)));
        $row = $this->db->get()->row_array();
        return $row ? $row['node_id'] : null;
    }

    public function addRoom($nodeId, $roomName)
    {
        $this->db->insert('node_rooms', array(
            'node_id' => $nodeId,
            'room_name' => trim($roomName),
        ));
        return $this->db->insert_id();
    }

    public function removeRoom($roomRowId)
    {
        $this->db->where('id', $roomRowId);
        return $this->db->delete('node_rooms');
    }
}
