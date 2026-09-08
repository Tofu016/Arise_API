<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Handles uploads for genuinely public Virtual Tour content — panoramas,
// section covers, marker photos. Uploads themselves still require admin
// auth (extends MY_Controller, calls requireAdmin()) even though the
// resulting files are freely, directly viewable afterward — anyone can
// look at a tour photo, but only an admin can add one.
//
// Files are saved directly under this project's own uploads/ folder,
// which Apache serves as plain static files — no PHP involvement needed
// just to view them, matching the original Firebase behavior where
// these specific paths were the ones marked publicly readable in
// storage.rules. Same three path prefixes as before
// (tourpanorama/, tourcover/, tourmarker/) preserved deliberately —
// useSecurePhotoUrl.js's own rewrite recognizes these exact prefixes to
// decide "resolve this as a direct public URL" versus "fall back to the
// old Firebase logic for anything not migrated yet."
class TourUploads_API extends MY_Controller
{
    private $uploadRoot;

    public function __construct()
    {
        parent::__construct();
        // FCPATH is CI3's own constant for the project's actual document
        // root (where index.php lives) — uploads/ sits directly there,
        // a sibling of application/ and system/, so Apache can serve it
        // as plain static files with zero extra configuration.
        // Overridable via UPLOAD_ROOT in .env (must be an absolute path
        // with a trailing slash) so production can store files outside
        // the web root / on a separate volume.
        $this->uploadRoot = !empty($_ENV['UPLOAD_ROOT'])
            ? rtrim($_ENV['UPLOAD_ROOT'], '/\\') . '/'
            : FCPATH . 'uploads/';
    }

    // Strips any directory components and rejects anything left that
    // isn't a plain, safe filename — the actual defense against
    // directory traversal (a crafted name like "../../../etc/passwd"
    // trying to escape the intended upload folder), since the filename
    // itself is client-supplied and can't be trusted as-is.
    private function sanitizeFilename($filename)
    {
        $base = basename($filename);
        // Rejects "." and ".." explicitly — both pass the character-class
        // check below on their own (composed entirely of allowed
        // characters) — same fix applied to IndoorUploads_API's matching
        // method, after catching this during review there.
        if (preg_match('/^\.+$/', $base)) {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $base)) {
            return false;
        }
        return $base;
    }

    // The real defense against arbitrary code execution — checked
    // BEFORE the uploaded file ever gets moved anywhere. getimagesize()
    // reads the file's actual header bytes to confirm it's a genuinely
    // recognized image format; a PHP script named "photo.jpg" fails
    // this outright, since its real content has no valid image header
    // at all, regardless of what its filename claims. This is checked
    // in addition to — not instead of — forcing the SAVED extension to
    // match this verified type (see handleUpload below): even in the
    // unlikely case of a crafted "polyglot" file that somehow passes
    // this check while also containing embedded code, it still only
    // ever gets saved with a safe, non-executable extension, since
    // Apache decides how to handle a request based on the file's
    // extension, not its actual content.
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

        // Reports a specific reason — including "the request exceeded
        // post_max_size", which PHP otherwise makes indistinguishable from
        // "no file attached". See MY_Controller::requireUploadedFile().
        if (!$this->requireUploadedFile('file')) {
            return;
        }

        $safeExt = $this->validateAndGetExtension($_FILES['file']['tmp_name']);
        if ($safeExt === false) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'The uploaded file is not a valid, recognized image.'));
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

        $targetDir = $this->uploadRoot . $subfolder . '/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $targetPath = $targetDir . $safeName;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            http_response_code(500);
            echo json_encode(array('success' => false, 'error' => 'Failed to save the uploaded file.'));
            return;
        }

        // Same shape as the original Firebase-based upload functions —
        // { path: "tourpanorama/filename.jpg" } — so the rewritten
        // tourPhotoSync.js functions calling this can return the exact
        // same thing their callers already expect.
        echo json_encode(array('success' => true, 'path' => "{$subfolder}/{$safeName}"));
    }

    // POST /TourUploads_API/panorama — admin only.
    // multipart/form-data: file (required), filename (optional override)
    public function panorama()
    {
        $this->handleUpload('tourpanorama');
    }

    // POST /TourUploads_API/cover — admin only.
    public function cover()
    {
        $this->handleUpload('tourcover');
    }

    // POST /TourUploads_API/marker — admin only.
    public function marker()
    {
        $this->handleUpload('tourmarker');
    }
}
