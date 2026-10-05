<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Same conventions throughout — getAll() public (room and facility details
// are shown to any visitor tapping a room or facility in the navigator, not
// just admins), writes behind requireAdmin(). A dialog belongs to a room (a
// node's "Rooms served" entry) or a facility (a facility marker's label);
// both are keyed by name in room_name, so the name is unique across the
// two. The field keeps its room_name name because the mobile app reads it.
// room_name's uniqueness is
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
    // photo_path, photo_360_path, link, search_terms, extra_photos (all optional;
    // extra_photos is an array of {path, thumb_x, thumb_y} shown after photo_path;
    // thumb_x/thumb_y also set the main photo's thumbnail focus)
    public function create()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $roomName = isset($data['room_name']) ? trim($data['room_name']) : '';

        if ($roomName === '') {
            return Api_response::fail(400, 'room_name is required.');
        }

        if ($this->PlacardDialogs_Model->roomNameExists($roomName)) {
            return Api_response::fail(409, 'A room or facility with this name already exists.');
        }

        $allowed = array('description', 'department', 'contact_number', 'photo_path', 'photo_360_path', 'link', 'thumb_x', 'thumb_y');
        $fields = $this->clampThumbFocus(array_intersect_key($data, array_flip($allowed)));
        $searchTerms = (isset($data['search_terms']) && is_array($data['search_terms'])) ? $data['search_terms'] : array();

        $extraPhotos = (isset($data['extra_photos']) && is_array($data['extra_photos'])) ? $data['extra_photos'] : array();

        $dialog = $this->PlacardDialogs_Model->create($roomName, $fields, $searchTerms, $extraPhotos);
        return Api_response::ok(array('dialog' => $dialog));
    }

    // PATCH /PlacardDialogs_API/update/{id} — admin only.
    public function update($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'dialog');

        $data = $this->getInput();

        if (isset($data['room_name'])) {
            $newName = trim($data['room_name']);
            if ($newName === '') {
                return Api_response::fail(400, 'room_name cannot be blank.');
            }
            if ($this->PlacardDialogs_Model->roomNameExists($newName, $id)) {
                return Api_response::fail(409, 'A room or facility with this name already exists.');
            }
        }

        $allowed = array('room_name', 'description', 'department', 'contact_number', 'photo_path', 'photo_360_path', 'link', 'thumb_x', 'thumb_y');
        $searchTerms = (isset($data['search_terms']) && is_array($data['search_terms'])) ? $data['search_terms'] : null;
        $extraPhotos = (isset($data['extra_photos']) && is_array($data['extra_photos'])) ? $data['extra_photos'] : null;
        // A body carrying only search_terms or extra_photos is still a change.
        $patch = $this->clampThumbFocus(Api_input::patch($data, $allowed, $searchTerms !== null || $extraPhotos !== null));

        $dialog = $this->PlacardDialogs_Model->update($id, $patch, $searchTerms, $extraPhotos);
        return Api_response::ok(array('dialog' => $dialog));
    }

    // thumb_x / thumb_y: where the main photo's square thumbnail is centered,
    // as 0-100 object-position percentages.
    private function clampThumbFocus($fields)
    {
        foreach (array('thumb_x', 'thumb_y') as $key) {
            if (isset($fields[$key])) {
                $fields[$key] = PlacardDialogs_Model::clampPercent($fields[$key]);
            }
        }
        return $fields;
    }

    // DELETE /PlacardDialogs_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'dialog');

        $this->PlacardDialogs_Model->delete($id);
        return Api_response::ok();
    }
}
