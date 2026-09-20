<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Admin photo gallery — lists every Photo the Photo store holds and
// cross-references each against every table that can reference a photo,
// so the admin panel can show which files are genuinely in use versus
// orphaned. Deletion (admin-only, like everything else here) only ever
// removes files confirmed orphaned by this same check — never something
// the gallery itself found still referenced anywhere.
class Photos_API extends MY_Controller
{
    // Every path currently referenced anywhere in the database, exactly
    // as stored (e.g. "panoramas/gd1/somefile.webp") — this is the
    // definitive "in use" set every listed photo gets checked against.
    // Confirmed against the real, current schema.sql directly rather
    // than assumed from memory, specifically because getting this list
    // wrong here would mean a still-in-use photo could be incorrectly
    // offered for deletion.
    private function getReferencedPaths()
    {
        $referenced = array();

        foreach ($this->db->select('photo_path')->get('nodes')->result_array() as $row) {
            if (!empty($row['photo_path'])) $referenced[$row['photo_path']] = true;
        }
        foreach ($this->db->select('photo_path')->get('tour_stops')->result_array() as $row) {
            if (!empty($row['photo_path'])) $referenced[$row['photo_path']] = true;
        }
        foreach ($this->db->select('photo_path, photo_360_path')->get('placard_dialogs')->result_array() as $row) {
            if (!empty($row['photo_path'])) $referenced[$row['photo_path']] = true;
            if (!empty($row['photo_360_path'])) $referenced[$row['photo_360_path']] = true;
        }
        foreach ($this->db->select('cover_photo_path')->get('tour_sections')->result_array() as $row) {
            if (!empty($row['cover_photo_path'])) $referenced[$row['cover_photo_path']] = true;
        }
        foreach ($this->db->select('photo_path')->get('tour_stop_marker_photos')->result_array() as $row) {
            if (!empty($row['photo_path'])) $referenced[$row['photo_path']] = true;
        }

        return $referenced;
    }

    // GET /Photos_API/getAll — admin only.
    public function getAll()
    {
        $this->requireAdmin();

        $referenced = $this->getReferencedPaths();
        $photos = $this->photoStore()->listPhotos();

        foreach ($photos as $i => $photo) {
            $photos[$i]['in_use'] = isset($referenced[$photo['path']]);
        }

        // Newest first — an admin managing this gallery cares most
        // about what was just uploaded.
        usort($photos, function ($a, $b) {
            return $b['modified_at'] <=> $a['modified_at'];
        });

        return Api_response::ok(array('photos' => $photos));
    }

    // DELETE /Photos_API/delete — admin only. Body: path
    // Deliberately re-checks in_use itself, from a fresh database read,
    // rather than trusting whatever the frontend last displayed —
    // another admin could have attached this exact photo to something
    // in the time since the gallery was last loaded, and this must not
    // delete a file that's actually in use by the time the request
    // arrives, regardless of what the UI showed a moment earlier.
    public function delete()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $relativePath = isset($data['path']) ? $data['path'] : '';

        $referenced = $this->getReferencedPaths();
        if (isset($referenced[$relativePath])) {
            return Api_response::fail(409, 'This photo is currently in use and cannot be deleted.');
        }

        $result = $this->photoStore()->remove($relativePath);
        if (!$result['ok']) {
            // A path the store won't accept is reported the same as one
            // that simply isn't there.
            if ($result['reason'] === 'invalid_path') {
                return Api_response::fail(404, 'Photo not found.');
            }
            return Api_response::fail($result['status'], $result['error']);
        }

        return Api_response::ok();
    }
}
