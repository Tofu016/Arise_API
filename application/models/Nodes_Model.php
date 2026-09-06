<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The most complex resource — same bidirectional neighbor-link pattern
// as TourStops_Model, plus:
//  - a REQUIRED (not optional) foreign key into buildings
//  - node_markers.type is a real ENUM in the schema (room/facility/
//    exit/hydrant), unlike tour_stop_markers' free-form varchar — an
//    invalid value here would otherwise surface as a raw MySQL error,
//    so it's validated explicitly before ever reaching the database
//  - node_rooms: a separate, simple one-to-many list of room names —
//    a loose, name-based reference (no FK to placard_dialogs), matching
//    the original: a node can list a room name before that room's own
//    details even exist yet
//  - markers here have no photos at all, unlike tour_stop_markers
class Nodes_Model extends CI_Model
{
    private $table = 'nodes';
    private $allowedMarkerTypes = array('room', 'facility', 'exit', 'hydrant');

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

    private function _getNeighborsGrouped($onlyNodeId = null)
    {
        $this->db->select('*');
        $this->db->from('node_neighbors');
        if ($onlyNodeId !== null) {
            $this->db->where('node_id', $onlyNodeId);
        }
        $rows = $this->db->get()->result_array();

        $grouped = array();
        foreach ($rows as $row) {
            $grouped[$row['node_id']][] = array(
                'neighbor_id' => $row['neighbor_id'],
                'yaw' => $row['yaw'],
                'pitch' => $row['pitch'],
            );
        }
        return $grouped;
    }

    private function _getMarkersGrouped($onlyNodeId = null)
    {
        $this->db->select('*');
        $this->db->from('node_markers');
        if ($onlyNodeId !== null) {
            $this->db->where('node_id', $onlyNodeId);
        }
        $rows = $this->db->get()->result_array();

        $grouped = array();
        foreach ($rows as $row) {
            $grouped[$row['node_id']][] = array(
                'id' => $row['id'],
                'type' => $row['type'],
                'label' => $row['label'],
                'yaw' => $row['yaw'],
                'pitch' => $row['pitch'],
            );
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
    public function create($name, $building, $floor, $type, $photoPath = null, $requestedId = null, $leadsToFloor = null)
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
        if ($leadsToFloor !== null && $leadsToFloor !== '') {
            $data['leads_to_floor'] = $leadsToFloor;
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

    public function update($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', $id);
        $this->db->update($this->table, $data);
        return $this->find($id);
    }

    public function delete($id)
    {
        // Neighbors (both directions), markers, and rooms all
        // cascade-delete via the schema's own foreign keys.
        $this->db->where('id', $id);
        return $this->db->delete($this->table);
    }

    // ---------- Neighbor links (bidirectional) ----------

    public function addNeighbor($nodeId, $neighborId, $yaw, $pitch, $reverseYaw, $reversePitch)
    {
        $this->db->insert('node_neighbors', array(
            'node_id' => $nodeId,
            'neighbor_id' => $neighborId,
            'yaw' => $yaw,
            'pitch' => $pitch,
        ));
        $this->db->insert('node_neighbors', array(
            'node_id' => $neighborId,
            'neighbor_id' => $nodeId,
            'yaw' => $reverseYaw,
            'pitch' => $reversePitch,
        ));
    }

    public function removeNeighbor($nodeId, $neighborId)
    {
        $this->db->where('node_id', $nodeId);
        $this->db->where('neighbor_id', $neighborId);
        $this->db->delete('node_neighbors');

        $this->db->where('node_id', $neighborId);
        $this->db->where('neighbor_id', $nodeId);
        $this->db->delete('node_neighbors');
    }

    // Updates ONE existing edge's angle only — see
    // TourStops_Model::updateNeighborAngle for the full reasoning
    // (addNeighbor() alone always writes both new rows atomically; this
    // updates one that already exists, matching setHotspot()'s real
    // per-direction semantics).
    public function updateNeighborAngle($nodeId, $neighborId, $yaw, $pitch)
    {
        $this->db->where('node_id', $nodeId);
        $this->db->where('neighbor_id', $neighborId);
        return $this->db->update('node_neighbors', array(
            'yaw' => $yaw,
            'pitch' => $pitch,
        ));
    }

    // ---------- Markers (room / facility / exit / hydrant — no photos) ----------

    public function isValidMarkerType($type)
    {
        return in_array($type, $this->allowedMarkerTypes, true);
    }

    public function addMarker($nodeId, $type, $label, $yaw, $pitch)
    {
        $this->db->insert('node_markers', array(
            'node_id' => $nodeId,
            'type' => $type,
            'label' => $label,
            'yaw' => $yaw,
            'pitch' => $pitch,
        ));
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

    // ---------- Rooms served (loose name references) ----------

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
