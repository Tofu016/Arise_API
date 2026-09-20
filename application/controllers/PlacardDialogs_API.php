<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Same conventions throughout — getAll() public (room details are
// shown to any visitor tapping a room in the navigator, not just
// admins), writes behind requireAdmin(). room_name's uniqueness is
// checked explicitly before insert/update, same reasoning as the
// building-existence and marker-type checks built for nodes.
class PlacardDialogs_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('PlacardDialogs_Model');
    }

    // GET /PlacardDialogs_API/getAll — public.
    public function getAll()
    {
        $dialogs = $this->PlacardDialogs_Model->getAll();
        return Api_response::ok(array('dialogs' => $dialogs));
    }

    // POST /PlacardDialogs_API/create — admin only.
    // Body: room_name (required); description, department, use,
    // photo_path, photo_360_path, link, search_terms (all optional)
    public function create()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $roomName = isset($data['room_name']) ? trim($data['room_name']) : '';

        if ($roomName === '') {
            return Api_response::fail(400, 'room_name is required.');
        }

        if ($this->PlacardDialogs_Model->roomNameExists($roomName)) {
            return Api_response::fail(409, 'A room with this name already exists.');
        }

        $allowed = array('description', 'department', 'use', 'photo_path', 'photo_360_path', 'link');
        $fields = array_intersect_key($data, array_flip($allowed));
        $searchTerms = (isset($data['search_terms']) && is_array($data['search_terms'])) ? $data['search_terms'] : array();

        $dialog = $this->PlacardDialogs_Model->create($roomName, $fields, $searchTerms);
        return Api_response::ok(array('dialog' => $dialog));
    }

    // PATCH /PlacardDialogs_API/update/{id} — admin only.
    public function update($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            return Api_response::fail(400, 'Missing dialog id.');
        }

        $data = $this->getInput();

        if (isset($data['room_name'])) {
            $newName = trim($data['room_name']);
            if ($newName === '') {
                return Api_response::fail(400, 'room_name cannot be blank.');
            }
            if ($this->PlacardDialogs_Model->roomNameExists($newName, $id)) {
                return Api_response::fail(409, 'A room with this name already exists.');
            }
        }

        $allowed = array('room_name', 'description', 'department', 'use', 'photo_path', 'photo_360_path', 'link');
        $patch = array_intersect_key($data, array_flip($allowed));
        $searchTerms = (isset($data['search_terms']) && is_array($data['search_terms'])) ? $data['search_terms'] : null;

        if (empty($patch) && $searchTerms === null) {
            return Api_response::fail(400, 'No valid fields to update.');
        }

        $dialog = $this->PlacardDialogs_Model->update($id, $patch, $searchTerms);
        return Api_response::ok(array('dialog' => $dialog));
    }

    // DELETE /PlacardDialogs_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            return Api_response::fail(400, 'Missing dialog id.');
        }

        $this->PlacardDialogs_Model->delete($id);
        return Api_response::ok();
    }
}
