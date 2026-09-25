<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Handles indoor content — room photos, room 360s, and node panoramas.
// Files are stored OUTSIDE htdocs entirely (two levels above this
// project's own root — see $protectedRoot below), not just blocked via
// .htaccess — a genuinely unreachable location is a stronger guarantee
// than a rule that has to stay correctly configured.
//
// Viewing used to require requireApproved() on serve() below, matching
// MainPage.jsx being login-gated. Now that MainPage is genuinely public
// (no RequireAuth wrapper — see App.jsx), that check has been removed
// entirely here too. Uploading stays admin-only regardless — visitors
// can view, only admins can add or change what's shown.
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
        // check below on their own (composed entirely of allowed
        // characters), which would otherwise let a path made entirely of
        // ".." segments escape protectedRoot via directory traversal —
        // a real bug caught during review, not defensive-only code.
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

    // The real defense against arbitrary code execution — checked
    // BEFORE the uploaded file ever gets moved anywhere. getimagesize()
    // reads the file's actual header bytes to confirm it's a genuinely
    // recognized image format; a PHP script named "photo.jpg" fails
    // this outright, since its real content has no valid image header
    // at all. Checked in addition to — not instead of — forcing the
    // SAVED extension to match this verified type (see handleUpload
    // below): even a crafted "polyglot" file that somehow passed this
    // check would still only ever get saved with a safe, non-executable
    // extension, since Apache decides how to handle a request based on
    // the file's extension, not its actual content.
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
        // own verified result, never from the client-supplied filename.
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
    // Temporary holding area for a brand-new upload not yet reviewed.
    public function panoramaReview()
    {
        $this->handleUpload('panoramas-review');
    }

    // POST /IndoorUploads_API/panoramaPublish — admin only.
    // The real, permanent location — matches copyPanoramaFile's own
    // panoramas/{building}/{filename} shape exactly.
    public function panoramaPublish()
    {
        $this->handleUpload('panoramas');
    }

    // POST /IndoorUploads_API/deleteReviewFile — admin only.
    // Deliberately restricted to ONLY the panoramas-review/ subfolder —
    // this endpoint has no business deleting anything from panoramas/,
    // roomphoto/, or room360/ at all.
    public function deleteReviewFile()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $path = isset($data['path']) ? $data['path'] : '';

        $segments = explode('/', $path);
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
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        echo json_encode(array('success' => true));
    }

    // GET /IndoorUploads_API/serve?path=roomphoto/gd1/somefile.jpg
    // No auth check anymore — indoor content is genuinely public now,
    // matching MainPage.jsx no longer requiring an account to view.
    // Uploading (above) stays admin-only regardless; this only governs
    // viewing. Query param, not a URL segment, since a real path
    // contains slashes CI3's own segment routing would otherwise split
    // apart.
    public function serve()
    {
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

        // Optional, explicit format override — mobile's own panorama
        // decoder (jpeg-js) only understands JPEG, and the web admin
        // now uploads panoramas as WebP. Rather than teach mobile's
        // decode pipeline a second, less-mature format (a genuinely
        // higher-risk change to the one part of that pipeline that's
        // always worked reliably), mobile explicitly requests this
        // conversion instead. Web never sends &format=jpeg and keeps
        // getting the original file completely unchanged — no
        // regression, no added conversion cost for the client that
        // never needed it.
        $requestedFormat = $this->input->get('format');
        // Connection: close on every response from this method — a direct
        // test of a real, observed pattern: the FIRST photo request after
        // app launch consistently loads fast, but the second and every
        // one after it consistently stalls for ~28-30 seconds specifically
        // in fetch() itself, not in decode or render (both independently
        // confirmed fast on their own). That exact shape — first request
        // fine, later ones stuck for a long, suspiciously consistent
        // timeout — is a known signature of a client reusing a pooled
        // HTTP connection that's gone stale, then waiting out a timeout
        // before falling back to a fresh one. This forces the server to
        // close the TCP connection after every response, so there's never
        // a pooled connection for the client to attempt reusing at all.
        header('Connection: close');

        if ($requestedFormat === 'jpeg' && $ext !== 'jpg' && $ext !== 'jpeg') {
            $converted = $this->convertToJpeg($fullPath, $ext);
            if ($converted !== false) {
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . strlen($converted));
                echo $converted;
                return;
            }
            // Conversion genuinely failed (GD/WebP support missing on
            // this server, a corrupt file, etc.) — fall through and
            // serve the original file/format below rather than erroring
            // out entirely. A photo in the "wrong" format for this one
            // requester is still better than no photo at all.
        }

        $mimeTypes = array(
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        );
        $mime = isset($mimeTypes[$ext]) ? $mimeTypes[$ext] : 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
    }

    // Converts the file at $fullPath (of the given, already-detected
    // $ext) into raw JPEG bytes entirely in memory, or returns false if
    // conversion genuinely isn't possible on this server (GD missing,
    // GD built without WebP read support, an unreadable/corrupt file).
    // PHP's GD library handles this conversion natively and reliably —
    // a mature, well-established server-side capability, unlike pure-JS
    // WebP decoding in a React Native/Hermes environment, which is
    // exactly the higher-risk path this design deliberately avoids.
    //
    // Also downscales to $maxWidth here — concretely confirmed necessary,
    // not a guess: a real 6080x3040 panorama measured at 66+ SECONDS to
    // decode via jpeg-js on an actual device at the original resolution,
    // fully blocking React Native's single JS thread the entire time
    // (this is precisely the same problem imageResize.js's own
    // maxWidth=1536 was originally built to solve on the frontend, before
    // that resizing step was separately removed elsewhere in this
    // project). 1024, not 1536 — measured directly: even after the first
    // resize pass, 1536px still took 4.2 real seconds to decode on device,
    // itself still a genuine, fully synchronous freeze with zero visual
    // feedback during it. Decode time scales roughly with pixel count, so
    // 1024 (roughly 44% the pixel count of 1536, for the same 2:1
    // panorama aspect ratio) should land close to ~1.9s instead — a real,
    // deliberate trade-off of some sharpness for a meaningfully shorter
    // freeze, not a free win either way. Scoped to only this conversion
    // path — mobile's own &format=jpeg request — so web, which never
    // sends that parameter, keeps receiving the full-resolution original
    // completely unaffected.
    private function convertToJpeg($fullPath, $ext, $maxWidth = 1024)
    {
        if (!function_exists('imagejpeg')) {
            return false;
        }

        $image = false;
        if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($fullPath);
        } elseif ($ext === 'png' && function_exists('imagecreatefrompng')) {
            $image = @imagecreatefrompng($fullPath);
        } elseif ($ext === 'gif' && function_exists('imagecreatefromgif')) {
            $image = @imagecreatefromgif($fullPath);
        }

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > $maxWidth) {
            $targetWidth = $maxWidth;
            $targetHeight = (int) round($height * ($maxWidth / $width));

            $resized = imagecreatetruecolor($targetWidth, $targetHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, 90);
        $jpegBytes = ob_get_clean();
        imagedestroy($image);

        return $jpegBytes;
    }
}
