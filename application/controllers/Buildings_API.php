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
        return Api_response::ok(array('buildings' => $buildings));
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
            return Api_response::fail(400, 'Building name is required.');
        }
        if (!is_numeric($floorCount) || $floorCount < 1) {
            return Api_response::fail(400, 'Floor count must be a positive number.');
        }

        $building = $this->Buildings_Model->create(
            $name,
            $floorCount,
            isset($data['lat']) ? $data['lat'] : null,
            isset($data['lng']) ? $data['lng'] : null
        );

        return Api_response::ok(array('building' => $building));
    }

    // PATCH /Buildings_API/update/{id} — admin only.
    public function update($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'building');

        $data = $this->getInput();
        $allowed = array('name', 'floor_count', 'lat', 'lng');
        $patch = Api_input::patch($data, $allowed);

        $building = $this->Buildings_Model->update($id, $patch);
        return Api_response::ok(array('building' => $building));
    }

    // DELETE /Buildings_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'building');

        if ($this->Buildings_Model->hasNodes($id)) {
            return Api_response::fail(409, 'This building still has nodes assigned to it. Reassign or delete those nodes first.');
        }

        $this->Buildings_Model->delete($id);
        return Api_response::ok();
    }
}
