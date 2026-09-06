<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Handles indoor content — room photos, room 360° photos — that need to
// stay genuinely login-gated to view, unlike TourUploads_API's Virtual
// Tour content. Two real differences from that file, both deliberate:
//
//  - Files are stored OUTSIDE htdocs entirely (two levels above this
//    project's own root — see $protectedRoot below), not just blocked
//    via .htaccess. Given the .htaccess confusion earlier in this
//    project, a genuinely unreachable location is a stronger guarantee
//    than a rule that has to be correctly configured and stay that way.
//  - Viewing goes through serve() below, which checks requireApproved()
//    before ever reading a byte — the actual PHP equivalent of what
//    getBytes() + Firebase Storage's own rules did before. requireApproved(),
//    not requireAdmin() — matches exactly what's needed to even reach
//    MainPage.jsx in the first place (RequireAuth with no requireRole),
//    not a stricter admin-only bar.
class IndoorUploads_API extends MY_Controller
{
    private $protectedRoot;

    public function __construct()
    {
        parent::__construct();
        // FCPATH is this project's own root (where index.php lives, e.g.
        // .../htdocs/Arise_API/) — going up two levels lands outside
        // htdocs entirely (.../htdocs/'s own parent), genuinely
        // unreachable by any web request regardless of Apache config.
        $this->protectedRoot = dirname(FCPATH, 2) . '/protected-uploads/';
    }

    private function sanitizeFilename($filename)
    {
        $base = basename($filename);
        // Rejects "." and ".." explicitly — both pass the character-class
        // check below on their own (they're composed entirely of allowed
        // characters), which would otherwise let a path made entirely of
        // ".." segments escape protectedRoot via directory traversal. This
        // is the actual fix for a real bug caught during review, not a
        // defensive measure added out of general caution.
        if (preg_match('/^\.+$/', $base)) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $base)) {
            return false;
        }
        return $base;
    }

    // building is used as a path segment (matching roomPhotoSync.js's
    // own roomphoto/{building}/{filename} structure) — sanitized the
    // same way a filename is, since it's just as client-supplied.
    private function sanitizePathSegment($segment)
    {
        if (preg_match('/^\.+$/', $segment)) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
            return false;
        }
        return $segment;
    }

    // The real defense against arbitrary code execution — same
    // reasoning as TourUploads_API's matching method. Checked BEFORE
    // the uploaded file ever gets moved anywhere, and its verified
    // result is what determines the SAVED extension (see handleUpload
    // below), independent of whatever extension the client claims.
    private function validateAndGetExtension($tmpPath)
    {
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            return false;
        }

        $mimeToExt = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        );

        return isset($mimeToExt[$imageInfo['mime']]) ? $mimeToExt[$imageInfo['mime']] : false;
    }

    private function handleUpload($subfolder)
    {
        $this->requireAdmin();

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'No valid file was uploaded.'));
            return;
        }

        $safeExt = $this->validateAndGetExtension($_FILES['file']['tmp_name']);
        if ($safeExt === false) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'The uploaded file is not a valid, recognized image.'));
            return;
        }

        $building = isset($_POST['building']) ? $this->sanitizePathSegment($_POST['building']) : false;
        if ($building === false) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Invalid or missing building.'));
            return;
        }

        $requestedName = isset($_POST['filename']) ? $_POST['filename'] : $_FILES['file']['name'];
        $requestedBase = pathinfo($requestedName, PATHINFO_FILENAME);
        $safeBase = $this->sanitizeFilename($requestedBase);
        if ($safeBase === false) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Invalid filename.'));
            return;
        }

        // The saved extension always comes from validateAndGetExtension's
        // own verified result, never from the client-supplied filename —
        // this is what makes the second defense layer actually
        // independent of the first, rather than just repeating it.
        $safeName = "{$safeBase}.{$safeExt}";

        $targetDir = $this->protectedRoot . $subfolder . '/' . $building . '/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $targetPath = $targetDir . $safeName;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            http_response_code(500);
            echo json_encode(array('success' => false, 'error' => 'Failed to save the uploaded file.'));
            return;
        }

        // Same path shape as before — "roomphoto/{building}/{filename}" —
        // stored in the database exactly as-is; serve() below is what
        // turns this back into actual bytes at view time.
        echo json_encode(array('success' => true, 'path' => "{$subfolder}/{$building}/{$safeName}"));
    }

    // POST /IndoorUploads_API/roomPhoto — admin only.
    public function roomPhoto()
    {
        $this->handleUpload('roomphoto');
    }

    // POST /IndoorUploads_API/room360Photo — admin only.
    public function room360Photo()
    {
        $this->handleUpload('room360');
    }

    // POST /IndoorUploads_API/panoramaReview — admin only.
    // Temporary holding area for a brand-new upload not yet reviewed —
    // kept out of the real panoramas/ path entirely, matching the
    // original Firebase behavior's own reasoning: a not-yet-blurred
    // photo shouldn't be reachable through the normal viewing path at
    // all, even a genuinely protected one, until an admin has actually
    // confirmed it.
    public function panoramaReview()
    {
        $this->handleUpload('panoramas-review');
    }

    // POST /IndoorUploads_API/panoramaPublish — admin only.
    // The real, permanent location — matches copyPanoramaFile's own
    // panoramas/{building}/{filename} shape exactly. Used both for a
    // brand-new upload's final publish step and for overwriting in
    // place when re-reviewing an already-published photo.
    public function panoramaPublish()
    {
        $this->handleUpload('panoramas');
    }

    // POST /IndoorUploads_API/deleteReviewFile — admin only.
    // Body: path — cleans up a temp file from panoramas-review/ once
    // review is done (confirmed or cancelled). Deliberately restricted
    // to ONLY the panoramas-review/ subfolder specifically, not an
    // arbitrary path — this endpoint has no business deleting anything
    // from panoramas/, roomphoto/, or room360/ at all.
    public function deleteReviewFile()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $path = isset($data['path']) ? $data['path'] : '';

        $segments = explode('/', $path);
        // The first segment MUST be panoramas-review — refuses anything
        // else outright, rather than trusting the caller to only ever
        // send a path from that folder.
        if (empty($segments) || $segments[0] !== 'panoramas-review') {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Invalid path.'));
            return;
        }

        $safeSegments = array();
        foreach ($segments as $segment) {
            $clean = $this->sanitizePathSegment($segment);
            if ($clean === false) {
                http_response_code(400);
                echo json_encode(array('success' => false, 'error' => 'Invalid path.'));
                return;
            }
            $safeSegments[] = $clean;
        }

        $fullPath = $this->protectedRoot . implode('/', $safeSegments);
        // Best-effort, same as the original deleteReviewFile — a
        // leftover temp file is a minor storage cost, not worth
        // failing the whole flow over.
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        echo json_encode(array('success' => true));
    }

    // GET /IndoorUploads_API/serve?path=roomphoto/gd1/somefile.jpg
    // Requires an approved (non-pending) account — the actual gate on
    // viewing, not just on the app shell around it. Query param, not a
    // URL segment, since a real path contains slashes CI3's own segment
    // routing would otherwise split apart.
    public function serve()
    {
        $this->requireApproved();

        $path = $this->input->get('path');
        if (empty($path)) {
            http_response_code(400);
            echo 'Missing path.';
            return;
        }

        // Rebuilt from individually-sanitized segments rather than
        // trusting the combined string directly — the actual defense
        // against a crafted path like "../../../etc/passwd" trying to
        // escape protectedRoot entirely.
        $segments = explode('/', $path);
        $safeSegments = array();
        foreach ($segments as $segment) {
            $clean = $this->sanitizePathSegment($segment);
            if ($clean === false) {
                http_response_code(400);
                echo 'Invalid path.';
                return;
            }
            $safeSegments[] = $clean;
        }

        $fullPath = $this->protectedRoot . implode('/', $safeSegments);
        if (!is_file($fullPath)) {
            http_response_code(404);
            echo 'Not found.';
            return;
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimeTypes = array(
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        );
        $mime = isset($mimeTypes[$ext]) ? $mimeTypes[$ext] : 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
    }
}
