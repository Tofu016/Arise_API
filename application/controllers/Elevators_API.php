<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Same conventions as Nodes_API — getAll() public, writes behind
// requireAdmin(). An elevator owns its label, building and floors; its
// landings are node markers that point at it (see Nodes_API::addMarker),
// so update() has to refuse any floor change that would strand one.
class Elevators_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Elevators_Model');
        $this->load->model('Buildings_Model');
    }

    // GET /Elevators_API/getAll — public, like Nodes_API::getAll: the
    // navigation UI needs elevators to route between floors.
    public function getAll()
    {
        return Api_response::ok(array('elevators' => $this->Elevators_Model->getAll()));
    }

    // POST /Elevators_API/create — admin only.
    // Body: id, label, building, accessible_floors (array or "1,2,3").
    public function create()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $id = isset($data['id']) ? trim((string) $data['id']) : '';
        $label = isset($data['label']) ? trim((string) $data['label']) : '';
        $building = isset($data['building']) ? $data['building'] : null;

        if ($id === '') {
            return Api_response::fail(400, 'Elevator id is required.');
        }
        if (strlen($id) > 64) {
            return Api_response::fail(400, 'Elevator id must be at most 64 characters.');
        }
        if ($label === '') {
            return Api_response::fail(400, 'Elevator label is required.');
        }
        if (empty($building) || !$this->Buildings_Model->find($building)) {
            return Api_response::fail(400, "Building '{$building}' does not exist.");
        }
        $floors = $this->parseFloors(isset($data['accessible_floors']) ? $data['accessible_floors'] : null);
        if ($floors === null) {
            return Api_response::fail(400, 'accessible_floors must list at least 2 distinct whole-number floors.');
        }
        if ($this->Elevators_Model->idExists($id)) {
            return Api_response::fail(409, "Elevator id '{$id}' is already taken.");
        }

        $elevator = $this->Elevators_Model->create($id, $label, $building, $floors);
        return Api_response::ok(array('elevator' => $elevator));
    }

    // PATCH /Elevators_API/update/{id} — admin only. Body: label,
    // accessible_floors. id and building are fixed: every landing was
    // validated against them.
    public function update($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'elevator');

        $patch = Api_input::patch($this->getInput(), array('label', 'accessible_floors'));

        $elevator = $this->Elevators_Model->find($id);
        if (!$elevator) {
            return Api_response::fail(404, 'Elevator not found.');
        }

        if (array_key_exists('label', $patch) && trim((string) $patch['label']) === '') {
            return Api_response::fail(400, 'Elevator label is required.');
        }

        if (array_key_exists('accessible_floors', $patch)) {
            $floors = $this->parseFloors($patch['accessible_floors']);
            if ($floors === null) {
                return Api_response::fail(400, 'accessible_floors must list at least 2 distinct whole-number floors.');
            }

            $stranded = array();
            foreach ($elevator['landings'] as $landing) {
                if (!in_array((int) $landing['floor'], $floors, true)) {
                    $stranded[] = "{$landing['node_id']} (floor {$landing['floor']})";
                }
            }
            if ($stranded) {
                return Api_response::fail(409, 'Remove the landing on ' . implode(', ', $stranded) . ' before dropping that floor from this elevator.');
            }
            $patch['accessible_floors'] = $floors;
        }

        $elevator = $this->Elevators_Model->update($id, $patch);
        return Api_response::ok(array('elevator' => $elevator));
    }

    // DELETE /Elevators_API/delete/{id} — admin only. Its landing markers
    // cascade-delete with it.
    public function delete($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'elevator');

        if (!$this->Elevators_Model->idExists($id)) {
            return Api_response::fail(404, 'Elevator not found.');
        }

        $this->Elevators_Model->delete($id);
        return Api_response::ok();
    }

    // A JSON array or a comma string -> sorted, de-duplicated ints, or null
    // when anything isn't a whole number or fewer than 2 floors remain (a
    // one-floor elevator connects nothing).
    private function parseFloors($raw)
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? array() : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return null;
        }

        $floors = array();
        foreach ($raw as $floor) {
            $floor = is_string($floor) ? trim($floor) : $floor;
            if (is_int($floor) || (is_string($floor) && preg_match('/^-?\d+$/', $floor))) {
                $floors[] = (int) $floor;
            } else {
                return null;
            }
        }
        $floors = array_values(array_unique($floors));
        sort($floors);
        return count($floors) >= 2 ? $floors : null;
    }
}
