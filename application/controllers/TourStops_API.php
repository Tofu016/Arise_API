<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Same conventions as TourSections_API — extends MY_Controller,
// getAll() genuinely public (matching the original confirmed
// tourStops Firestore rule: allow read: if true), every write behind
// requireAdmin(). The extra endpoints here (neighbors, markers) exist
// because tour_stops has real graph/marker structure that
// tour_sections never needed.
class TourStops_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('TourStops_Model');
    }

    // GET /TourStops_API/getAll — genuinely public, no check.
    public function getAll()
    {
        $stops = $this->TourStops_Model->getAll();
        echo json_encode(array('success' => true, 'stops' => $stops));
    }

    // POST /TourStops_API/create — admin only.
    // Body: name (required), id, section_id, photo_path, description (all optional)
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
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Stop name is required.'));
            return;
        }

        if (!empty($requestedId) && $this->TourStops_Model->idExists($requestedId)) {
            http_response_code(409);
            echo json_encode(array('success' => false, 'error' => "ID '{$requestedId}' is already used by another stop."));
            return;
        }

        $stop = $this->TourStops_Model->create(
            $name,
            isset($data['section_id']) ? $data['section_id'] : null,
            isset($data['photo_path']) ? $data['photo_path'] : null,
            isset($data['description']) ? $data['description'] : null,
            $requestedId
        );

        echo json_encode(array('success' => true, 'stop' => $stop));
    }

    // PATCH /TourStops_API/rename/{oldId} — admin only.
    // Body: new_id
    public function rename($oldId = null)
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $newId = isset($data['new_id']) ? trim($data['new_id']) : '';

        if (empty($oldId) || $newId === '') {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Both the current and new id are required.'));
            return;
        }
        if ($this->TourStops_Model->idExists($newId)) {
            http_response_code(409);
            echo json_encode(array('success' => false, 'error' => "ID '{$newId}' is already used by another stop."));
            return;
        }

        $stop = $this->TourStops_Model->renameStop($oldId, $newId);
        echo json_encode(array('success' => true, 'stop' => $stop));
    }

    // PATCH /TourStops_API/update/{id} — admin only.
    public function update($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing stop id.'));
            return;
        }

        $data = $this->getInput();
        $allowed = array('name', 'section_id', 'photo_path', 'description');
        $patch = array_intersect_key($data, array_flip($allowed));

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

        if (empty($patch)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'No valid fields to update.'));
            return;
        }

        $stop = $this->TourStops_Model->update($id, $patch);
        echo json_encode(array('success' => true, 'stop' => $stop));
    }

    // DELETE /TourStops_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        if (empty($id)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing stop id.'));
            return;
        }

        $this->TourStops_Model->delete($id);
        echo json_encode(array('success' => true));
    }

    // POST /TourStops_API/addNeighbor — admin only.
    // Body: stop_id, neighbor_id, yaw, pitch, reverse_yaw, reverse_pitch
    // Writes both directions of the link in one call — see
    // TourStops_Model::addNeighbor for why both angles are required.
    public function addNeighbor()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $required = array('stop_id', 'neighbor_id', 'yaw', 'pitch', 'reverse_yaw', 'reverse_pitch');
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                http_response_code(400);
                echo json_encode(array('success' => false, 'error' => "Missing field: {$field}"));
                return;
            }
        }

        $this->TourStops_Model->addNeighbor(
            $data['stop_id'],
            $data['neighbor_id'],
            $data['yaw'],
            $data['pitch'],
            $data['reverse_yaw'],
            $data['reverse_pitch']
        );

        echo json_encode(array('success' => true));
    }

    // POST /TourStops_API/removeNeighbor — admin only.
    // Body: stop_id, neighbor_id — removes both directions.
    public function removeNeighbor()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        if (empty($data['stop_id']) || empty($data['neighbor_id'])) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'stop_id and neighbor_id are both required.'));
            return;
        }

        $this->TourStops_Model->removeNeighbor($data['stop_id'], $data['neighbor_id']);
        echo json_encode(array('success' => true));
    }

    // PATCH /TourStops_API/updateNeighborAngle — admin only.
    // Body: stop_id, neighbor_id, yaw, pitch — updates ONE direction's
    // angle only, matching setHotspot()'s real semantics. See
    // TourStops_Model::updateNeighborAngle for why addNeighbor() alone
    // can't do this.
    public function updateNeighborAngle()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $required = array('stop_id', 'neighbor_id', 'yaw', 'pitch');
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                http_response_code(400);
                echo json_encode(array('success' => false, 'error' => "Missing field: {$field}"));
                return;
            }
        }

        $this->TourStops_Model->updateNeighborAngle(
            $data['stop_id'],
            $data['neighbor_id'],
            $data['yaw'],
            $data['pitch']
        );
        echo json_encode(array('success' => true));
    }

    // POST /TourStops_API/addMarker — admin only.
    // Body: stop_id, label, yaw, pitch, photos (array of paths, optional)
    public function addMarker()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $required = array('stop_id', 'label', 'yaw', 'pitch');
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                http_response_code(400);
                echo json_encode(array('success' => false, 'error' => "Missing field: {$field}"));
                return;
            }
        }

        $photos = isset($data['photos']) && is_array($data['photos']) ? $data['photos'] : array();
        $markerId = $this->TourStops_Model->addMarker(
            $data['stop_id'],
            $data['label'],
            $data['yaw'],
            $data['pitch'],
            $photos
        );

        echo json_encode(array('success' => true, 'marker_id' => $markerId));
    }

    // PATCH /TourStops_API/updateMarker/{marker_id} — admin only.
    // Body: label/yaw/pitch (any subset), photos (optional — omit
    // entirely to leave photos untouched, include an empty array to
    // clear them, or a new full list to replace them).
    public function updateMarker($markerId = null)
    {
        $this->requireAdmin();

        if (empty($markerId)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing marker id.'));
            return;
        }

        $data = $this->getInput();
        $allowed = array('label', 'yaw', 'pitch');
        $patch = array_intersect_key($data, array_flip($allowed));
        $photos = (isset($data['photos']) && is_array($data['photos'])) ? $data['photos'] : null;

        if (empty($patch) && $photos === null) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'No valid fields to update.'));
            return;
        }

        $this->TourStops_Model->updateMarker($markerId, $patch, $photos);
        echo json_encode(array('success' => true));
    }

    // DELETE /TourStops_API/deleteMarker/{marker_id} — admin only.
    public function deleteMarker($markerId = null)
    {
        $this->requireAdmin();

        if (empty($markerId)) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Missing marker id.'));
            return;
        }

        $this->TourStops_Model->deleteMarker($markerId);
        echo json_encode(array('success' => true));
    }
}
