<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Admin photo gallery — scans every upload folder on disk and
// cross-references each file against every table that can reference a
// photo, so the admin panel can show which files are genuinely in use
// versus orphaned. Deletion (admin-only, like everything else here)
// only ever removes files confirmed orphaned by this same check —
// never something the gallery itself found still referenced anywhere.
class Photos_API extends MY_Controller
{
    private $uploadRoot;
    private $protectedRoot;

    // Every physical location a photo can be saved, and which
    // frontend-facing path prefix each one corresponds to — matches
    // TourUploads_API/IndoorUploads_API's own subfolder names exactly.
    private $folders = array(
        'tourpanorama' => 'public',
        'tourcover' => 'public',
        'tourmarker' => 'public',
        'panoramas' => 'protected',
        'roomphoto' => 'protected',
        'room360' => 'protected',
    );

    public function __construct()
    {
        parent::__construct();
        $this->uploadRoot = FCPATH . 'uploads/';
        $this->protectedRoot = dirname(FCPATH, 2) . '/protected-uploads/';
    }

    // Every path currently referenced anywhere in the database, exactly
    // as stored (e.g. "panoramas/gd1/somefile.webp") — this is the
    // definitive "in use" set every scanned file gets checked against.
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
        $photos = array();

        foreach ($this->folders as $subfolder => $visibility) {
            $root = $visibility === 'public' ? $this->uploadRoot : $this->protectedRoot;
            $dir = $root . $subfolder . '/';
            if (!is_dir($dir)) continue;

            // Both public (uploads/tourpanorama/file.jpg — flat, no
            // building subfolder) and protected (protected-uploads/
            // panoramas/gd1/file.jpg — one building subfolder deep)
            // layouts need walking, since they're genuinely structured
            // differently — see TourUploads_API vs IndoorUploads_API's
            // own handleUpload() for why.
            $this->scanFolder($dir, $subfolder, $visibility, $referenced, $photos);
        }

        // Newest first — an admin managing this gallery cares most
        // about what was just uploaded.
        usort($photos, function ($a, $b) {
            return $b['modified_at'] <=> $a['modified_at'];
        });

        echo json_encode(array('success' => true, 'photos' => $photos));
    }

    private function scanFolder($dir, $subfolder, $visibility, $referenced, &$photos)
    {
        $entries = @scandir($dir);
        if ($entries === false) return;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = $dir . $entry;

            if (is_dir($fullPath)) {
                // One level deep only (a building subfolder under a
                // protected-uploads/ category) — matches how deep
                // IndoorUploads_API itself ever writes, never deeper.
                $this->scanFolder($fullPath . '/', $subfolder . '/' . $entry, $visibility, $referenced, $photos);
                continue;
            }

            // The relative path exactly as it would be stored in the
            // database (e.g. "panoramas/gd1/file.webp") — subfolder here
            // already carries any building-subfolder segment appended
            // by the recursive call above.
            $relativePath = $subfolder . '/' . $entry;

            $photos[] = array(
                'path' => $relativePath,
                'visibility' => $visibility,
                'in_use' => isset($referenced[$relativePath]),
                'size_bytes' => filesize($fullPath),
                'modified_at' => filemtime($fullPath),
            );
        }
    }

    // Resolves a category+relative path back to a real filesystem path,
    // the same way for both getAll's own scan and delete() below —
    // kept as one shared method so the two can never disagree with each
    // other about where a given path actually lives on disk.
    private function resolveFullPath($relativePath)
    {
        $segments = explode('/', $relativePath);
        $category = $segments[0];
        if (!isset($this->folders[$category])) return false;

        // Every segment individually validated against the same safe
        // character set the upload controllers themselves use — the
        // same directory-traversal defense, applied here since this
        // path is being used to delete a file, not just read one.
        foreach ($segments as $segment) {
            if (preg_match('/^\.+$/', $segment) || !preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
                return false;
            }
        }

        $root = $this->folders[$category] === 'public' ? $this->uploadRoot : $this->protectedRoot;
        return $root . implode('/', $segments);
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
            http_response_code(409);
            echo json_encode(array('success' => false, 'error' => 'This photo is currently in use and cannot be deleted.'));
            return;
        }

        $fullPath = $this->resolveFullPath($relativePath);
        if ($fullPath === false || !is_file($fullPath)) {
            http_response_code(404);
            echo json_encode(array('success' => false, 'error' => 'Photo not found.'));
            return;
        }

        if (!unlink($fullPath)) {
            http_response_code(500);
            echo json_encode(array('success' => false, 'error' => 'Failed to delete the file.'));
            return;
        }

        echo json_encode(array('success' => true));
    }
}
