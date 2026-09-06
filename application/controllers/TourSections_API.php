<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Now extends MY_Controller instead of CI_Controller directly — CORS,
// OPTIONS preflight, and getInput() all moved to that shared base (see
// its own comments for why), so this file only holds what's actually
// specific to tour sections.
//
// getAll() deliberately has NO permission check at all — tourSections
// was confirmed genuinely public-read in the original Firestore rules
// (allow read: if true), since the whole point of the Virtual Tour is
// being reachable without login. create()/update()/delete() call
// requireAdmin(), matching that same original rule's write-side
// restriction (allow write: if isAdmin()) — this is the first real,
// end-to-end proof that the new permission-check mechanism actually
// works, not just something built and left untested.
class TourSections_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('TourSections_Model');
    }

    // GET /TourSections_API/getAll — genuinely public, no check.
    public function getAll()
    {
        $sections = $this->TourSections_Model->getAll();
        echo json_encode(array('success' => true, 'sections' => $sections));
    }

    // POST /TourSections_API/create — admin only.
    // Body: label (required), cover_photo_path (optional)
    public function create()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $label = isset($data['label']) ? $data['label'] : null;
        $coverPhotoPath = isset($data['cover_photo_path']) ? $data['cover_photo_path'] : null;

        if (empty(trim((string) $label))) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Section name is required.'));
            return;
        }

        $section = $this->TourSections_Model->create($label, $coverPhotoPath);
        echo json_encode(array('success' => true, 'section' => $section));
    }

    // PATCH /TourSections_API/update/{id} — admin only.
    public function update($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing section id.'));
            return;
        }

        $data = $this->getInput();
        $allowed = array('label', 'cover_photo_path');
        $patch = array_intersect_key($data, array_flip($allowed));

        if (empty($patch)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'No valid fields to update.'));
            return;
        }

        $section = $this->TourSections_Model->update($id, $patch);
        echo json_encode(array('success' => true, 'section' => $section));
    }

    // DELETE /TourSections_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing section id.'));
            return;
        }

        $this->TourSections_Model->delete($id);
        echo json_encode(array('success' => true));
    }
}
