<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Same conventions throughout — getAll() public, writes behind
// requireAdmin(). delete() specifically checks hasNodes() first and
// refuses cleanly rather than letting the database's own foreign key
// restriction surface as a raw SQL error.
class Buildings_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Buildings_Model');
    }

    // GET /Buildings_API/getAll — public, matching nodes/tour data
    // being reachable by the general navigation UI, not just admins.
    public function getAll()
    {
        $buildings = $this->Buildings_Model->getAll();
        echo json_encode(array('success' => true, 'buildings' => $buildings));
    }

    // POST /Buildings_API/create — admin only.
    // Body: name (required), floor_count (required), lat, lng (optional)
    public function create()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $name = isset($data['name']) ? $data['name'] : null;
        $floorCount = isset($data['floor_count']) ? $data['floor_count'] : null;

        if (empty(trim((string) $name))) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Building name is required.'));
            return;
        }
        if (!is_numeric($floorCount) || $floorCount < 1) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Floor count must be a positive number.'));
            return;
        }

        $building = $this->Buildings_Model->create(
            $name,
            $floorCount,
            isset($data['lat']) ? $data['lat'] : null,
            isset($data['lng']) ? $data['lng'] : null
        );

        echo json_encode(array('success' => true, 'building' => $building));
    }

    // PATCH /Buildings_API/update/{id} — admin only.
    public function update($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing building id.'));
            return;
        }

        $data = $this->getInput();
        $allowed = array('name', 'floor_count', 'lat', 'lng');
        $patch = array_intersect_key($data, array_flip($allowed));

        if (empty($patch)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'No valid fields to update.'));
            return;
        }

        $building = $this->Buildings_Model->update($id, $patch);
        echo json_encode(array('success' => true, 'building' => $building));
    }

    // DELETE /Buildings_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing building id.'));
            return;
        }

        if ($this->Buildings_Model->hasNodes($id)) {
            http_response_code(409);
            echo json_encode(array(
                'success' => false,
                'error' => 'This building still has nodes assigned to it. Reassign or delete those nodes first.',
            ));
            return;
        }

        $this->Buildings_Model->delete($id);
        echo json_encode(array('success' => true));
    }
}
