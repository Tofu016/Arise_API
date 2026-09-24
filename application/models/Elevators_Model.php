<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The single source for an elevator's label, building and floors. Its
// landings are ordinary node_markers rows of type 'elevator' pointing
// here through elevator_id, so nothing about the elevator itself is ever
// copied onto a marker (the old per-marker copies drifted apart).
class Elevators_Model extends CI_Model
{
    private $table = 'elevators';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // "-1,1,2" -> array(-1, 1, 2), sorted. Stored as text because floors
    // can be negative (UG = -1) and MySQL's SET type can't hold that.
    public static function parseFloors($raw)
    {
        if ($raw === null || $raw === '') {
            return array();
        }
        $floors = array_map('intval', explode(',', $raw));
        sort($floors);
        return $floors;
    }

    public function getAll()
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->order_by('building', 'ASC');
        $this->db->order_by('id', 'ASC');
        $rows = $this->db->get()->result_array();

        $landingsByElevator = array();
        foreach ($this->landingRows() as $landing) {
            $landingsByElevator[$landing['elevator_id']][] = $this->landingShape($landing);
        }

        $elevators = array();
        foreach ($rows as $row) {
            $elevators[] = $this->shape($row, isset($landingsByElevator[$row['id']]) ? $landingsByElevator[$row['id']] : array());
        }
        return $elevators;
    }

    // With landings; null when no such elevator.
    public function find($id)
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->where('id', $id);
        $row = $this->db->get()->row_array();
        if (!$row) {
            return null;
        }
        return $this->shape($row, $this->landings($id));
    }

    public function idExists($id)
    {
        $this->db->select('id');
        $this->db->from($this->table);
        $this->db->where('id', $id);
        return $this->db->get()->num_rows() > 0;
    }

    // [{ marker_id, node_id, floor }] for one elevator, lowest floor first.
    public function landings($id)
    {
        return array_map(array($this, 'landingShape'), $this->landingRows($id));
    }

    // Every elevator with a landing on $nodeId (with landings), so a node
    // edit can be checked against each one before it moves the landing.
    public function elevatorsLandingAt($nodeId)
    {
        $this->db->select('elevator_id');
        $this->db->from('node_markers');
        $this->db->where('node_id', $nodeId);
        $this->db->where('type', 'elevator');
        $rows = $this->db->get()->result_array();

        $elevators = array();
        foreach ($rows as $row) {
            if ($row['elevator_id'] !== null && !isset($elevators[$row['elevator_id']])) {
                $elevator = $this->find($row['elevator_id']);
                if ($elevator) {
                    $elevators[$row['elevator_id']] = $elevator;
                }
            }
        }
        return array_values($elevators);
    }

    // Floors come in already validated as an int array (see Elevators_API).
    public function create($id, $label, $building, array $floors)
    {
        $now = date('Y-m-d H:i:s');
        $this->db->insert($this->table, array(
            'id' => $id,
            'label' => trim($label),
            'building' => $building,
            'accessible_floors' => $this->joinFloors($floors),
            'created_at' => $now,
            'updated_at' => $now,
        ));
        return $this->find($id);
    }

    public function update($id, array $data)
    {
        if (isset($data['accessible_floors'])) {
            $data['accessible_floors'] = $this->joinFloors($data['accessible_floors']);
        }
        if (isset($data['label'])) {
            $data['label'] = trim($data['label']);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', $id);
        $this->db->update($this->table, $data);
        return $this->find($id);
    }

    // Landing markers go with it via node_markers' ON DELETE CASCADE.
    public function delete($id)
    {
        $this->db->where('id', $id);
        return $this->db->delete($this->table);
    }

    // Public/static so Nodes_Model can reuse it for leads_to_floors, which
    // is stored the same way (comma text) for the same reason (negative
    // UG floors).
    public static function joinFloors(array $floors)
    {
        $floors = array_values(array_unique(array_map('intval', $floors)));
        sort($floors);
        return implode(',', $floors);
    }

    private function landingRows($onlyElevatorId = null)
    {
        $this->db->select('node_markers.id AS marker_id, node_markers.node_id, node_markers.elevator_id, nodes.floor');
        $this->db->from('node_markers');
        $this->db->join('nodes', 'nodes.id = node_markers.node_id');
        $this->db->where('node_markers.type', 'elevator');
        if ($onlyElevatorId !== null) {
            $this->db->where('node_markers.elevator_id', $onlyElevatorId);
        }
        $this->db->order_by('nodes.floor', 'ASC');
        $rows = $this->db->get()->result_array();

        return array_values(array_filter($rows, function ($row) {
            return $row['elevator_id'] !== null;
        }));
    }

    private function landingShape(array $row)
    {
        return array(
            'marker_id' => (int) $row['marker_id'],
            'node_id' => $row['node_id'],
            'floor' => (int) $row['floor'],
        );
    }

    private function shape(array $row, array $landings)
    {
        return array(
            'id' => $row['id'],
            'label' => $row['label'],
            'building' => $row['building'],
            'accessible_floors' => self::parseFloors($row['accessible_floors']),
            'landings' => $landings,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        );
    }
}
