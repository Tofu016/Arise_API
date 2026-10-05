<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Neighbor_actions.php';

// Same conventions as TourSections_API — extends MY_Controller,
// getAll() genuinely public (matching the original confirmed
// tourStops Firestore rule: allow read: if true), every write behind
// requireAdmin(). The extra endpoints here (neighbors) exist because
// tour_stops has real graph structure that tour_sections never needed.
class TourStops_API extends MY_Controller
{
    use Neighbor_actions;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('TourStops_Model');
    }

    // GET /TourStops_API/getAll — genuinely public, no check.
    public function getAll()
    {
        $stops = $this->TourStops_Model->getAll();
        return Api_response::ok(array('stops' => $stops));
    }

    // POST /TourStops_API/create — admin only.
    // Body: name (required), id, section_id, photo_path, cover_photo_path,
    // description (all optional). cover_photo_path is a flat photo in
    // tourcover/ (the scene list's thumbnail), photo_path the 360° one.
    // Accepts an optional client-provided id — TourStopForm.jsx lets the
    // admin see/edit the suggested id before saving, and TourStopsPage.jsx
    // selects that exact id immediately after creating without waiting
    // for a server response, so the server has to honor it rather than
    // generate its own.
    public function create()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $name = isset($data['name']) ? $data['name'] : null;
        $requestedId = isset($data['id']) ? trim($data['id']) : null;

        if (empty(trim((string) $name))) {
            return Api_response::fail(400, 'Stop name is required.');
        }

        if (!empty($requestedId) && $this->TourStops_Model->idExists($requestedId)) {
            return Api_response::fail(409, "ID '{$requestedId}' is already used by another stop.");
        }

        $this->requirePhotoPathIn(isset($data['photo_path']) ? $data['photo_path'] : null, 'tourpanorama');
        $this->requirePhotoPathIn(isset($data['cover_photo_path']) ? $data['cover_photo_path'] : null, 'tourcover', 'cover_photo_path');

        $stop = $this->TourStops_Model->create(
            $name,
            isset($data['section_id']) ? $data['section_id'] : null,
            isset($data['photo_path']) ? $data['photo_path'] : null,
            isset($data['description']) ? $data['description'] : null,
            $requestedId,
            isset($data['cover_photo_path']) ? $data['cover_photo_path'] : null
        );

        return Api_response::ok(array('stop' => $stop));
    }

    // PATCH /TourStops_API/rename/{oldId} — admin only.
    // Body: new_id
    public function rename($oldId = null)
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $newId = isset($data['new_id']) ? trim($data['new_id']) : '';

        if (empty($oldId) || $newId === '') {
            return Api_response::fail(400, 'Both the current and new id are required.');
        }
        if ($this->TourStops_Model->idExists($newId)) {
            return Api_response::fail(409, "ID '{$newId}' is already used by another stop.");
        }

        $stop = $this->TourStops_Model->renameStop($oldId, $newId);
        return Api_response::ok(array('stop' => $stop));
    }

    // PATCH /TourStops_API/update/{id} — admin only.
    public function update($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'stop');

        $data = $this->getInput();
        $allowed = array('name', 'section_id', 'photo_path', 'cover_photo_path', 'description');
        $patch = Api_input::patch($data, $allowed);

        // section_id is a foreign key — the database expects either a
        // real tour_sections.id or NULL, never a literal empty string.
        // Genuine bug caught during testing: the frontend was sending
        // "" for "no section selected," which failed at the database
        // level with an unhandled error. Fixed there too, but this is
        // the backend's own independent safeguard against the same
        // class of input from any caller, not just that one.
        if (isset($patch['section_id']) && $patch['section_id'] === '') {
            $patch['section_id'] = null;
        }

        // Checked only when the photo actually changes: the admin form
        // sends every field on save, and a stop stored before this rule
        // must stay editable.
        if (array_key_exists('photo_path', $patch) || array_key_exists('cover_photo_path', $patch)) {
            $current = $this->TourStops_Model->find($id);
            if (array_key_exists('photo_path', $patch) && (!$current || $patch['photo_path'] !== $current['photo_path'])) {
                $this->requirePhotoPathIn($patch['photo_path'], 'tourpanorama');
            }
            $currentCover = $current && isset($current['cover_photo_path']) ? $current['cover_photo_path'] : null;
            if (array_key_exists('cover_photo_path', $patch) && (!$current || $patch['cover_photo_path'] !== $currentCover)) {
                $this->requirePhotoPathIn($patch['cover_photo_path'], 'tourcover', 'cover_photo_path');
            }
        }

        $stop = $this->TourStops_Model->update($id, $patch);
        return Api_response::ok(array('stop' => $stop));
    }

    // DELETE /TourStops_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'stop');

        $this->TourStops_Model->delete($id);
        return Api_response::ok();
    }

    // addNeighbor, removeNeighbor and updateNeighborAngle are shared with
    // Nodes_API — see Neighbor_actions. Only these two hooks differ.
    protected function neighborOwnerField()
    {
        return 'stop_id';
    }

    protected function neighborModel()
    {
        return $this->TourStops_Model;
    }
}
