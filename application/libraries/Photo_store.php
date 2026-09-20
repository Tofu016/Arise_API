<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Where Photos live on disk. Every Photo is addressed by its Photo path
// (e.g. "panoramas/gd1/lobby.webp") — the same string the database
// stores — and callers never see a filesystem path or a root directory.
//
// Deliberately free of CodeIgniter dependencies: roots and the "mover"
// (the function that actually relocates an uploaded file) are passed in,
// so tests run against a temp directory with no framework, no database
// and no HTTP request. Methods return plain result arrays instead of
// emitting output; a failure carries an HTTP status hint for the
// controller to use.
class Photo_store
{
    // Every Category a Photo can be saved under.
    //   visibility — 'public' photos live under the public root and are
    //                served straight from disk; 'protected' photos live
    //                under the protected root and are streamed by PHP.
    //   layout     — 'flat' is category/file; 'per-building' is
    //                category/building/file.
    //   listed     — whether the admin gallery lists it. panoramas-review
    //                is a temporary holding area whose files are
    //                unreferenced by design until published, so listing
    //                them would offer a mid-review file for deletion.
    const CATEGORIES = array(
        'tourpanorama' => array('visibility' => 'public', 'layout' => 'flat', 'listed' => true),
        'tourcover' => array('visibility' => 'public', 'layout' => 'flat', 'listed' => true),
        'tourmarker' => array('visibility' => 'public', 'layout' => 'flat', 'listed' => true),
        'panoramas' => array('visibility' => 'protected', 'layout' => 'per-building', 'listed' => true),
        'roomphoto' => array('visibility' => 'protected', 'layout' => 'per-building', 'listed' => true),
        'room360' => array('visibility' => 'protected', 'layout' => 'per-building', 'listed' => true),
        'panoramas-review' => array('visibility' => 'protected', 'layout' => 'per-building', 'listed' => false),
    );

    // The only image types accepted, and the extension each is saved as.
    const MIME_TO_EXT = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    );

    const EXT_TO_MIME = array(
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    );

    private $publicRoot;
    private $protectedRoot;
    private $mover;
    private $limits;

    // $config:
    //   public_root, protected_root — absolute directories (required).
    //   mover  — callable($tmpPath, $targetPath): bool. Defaults to
    //            move_uploaded_file, which only succeeds for genuine
    //            HTTP uploads; tests pass rename/copy instead.
    //   limits — optional ['post_max_size' => bytes,
    //            'upload_max_filesize' => bytes] overriding php.ini,
    //            which can't be changed at runtime.
    public function __construct(array $config)
    {
        $this->publicRoot = rtrim($config['public_root'], '/\\') . '/';
        $this->protectedRoot = rtrim($config['protected_root'], '/\\') . '/';
        $this->mover = isset($config['mover']) ? $config['mover'] : 'move_uploaded_file';
        $this->limits = isset($config['limits']) ? $config['limits'] : array();
    }

    // Describes the current HTTP request's upload for save(). The only
    // method that touches superglobals — everything else is pure.
    //   file           — the $_FILES['file'] entry, or null if absent.
    //   request_bytes  — CONTENT_LENGTH.
    //   body_discarded — bytes were sent but PHP threw away $_POST and
    //                    $_FILES entirely, which is what happens when the
    //                    body exceeds post_max_size.
    public static function incomingFromGlobals()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtolower($_SERVER['REQUEST_METHOD']) : '';
        $bytes = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

        return array(
            'file' => isset($_FILES['file']) ? $_FILES['file'] : null,
            'request_bytes' => $bytes,
            'body_discarded' => $method === 'post' && $bytes > 0 && empty($_POST) && empty($_FILES),
        );
    }

    // Saves an uploaded image as a Photo.
    //   $category — a key of CATEGORIES.
    //   $incoming — see incomingFromGlobals().
    //   $name     — optional client-chosen base name; falls back to the
    //               uploaded file's own name. Its extension is ignored.
    //   $building — required for per-building categories, must be null
    //               for flat ones.
    // Returns array('ok' => true, 'path' => $photoPath) or
    // array('ok' => false, 'status' => int, 'error' => string).
    public function save($category, array $incoming, $name = null, $building = null)
    {
        if (!isset(self::CATEGORIES[$category])) {
            throw new InvalidArgumentException("Unknown photo category: {$category}");
        }
        $def = self::CATEGORIES[$category];
        if ($def['layout'] === 'flat' && $building !== null) {
            throw new InvalidArgumentException("Category {$category} has no building level.");
        }

        $received = $this->checkReceived($incoming);
        if ($received !== true) {
            return $received;
        }
        $file = $incoming['file'];

        $ext = $this->verifiedExtension($file['tmp_name']);
        if ($ext === false) {
            return $this->fail(400, 'The uploaded file is not a valid, recognized image.');
        }

        $dir = $this->rootFor($def) . $category . '/';
        $relative = $category . '/';
        if ($def['layout'] === 'per-building') {
            $safeBuilding = $this->safeSegment($building);
            if ($safeBuilding === false) {
                return $this->fail(400, 'Invalid or missing building.');
            }
            $dir .= $safeBuilding . '/';
            $relative .= $safeBuilding . '/';
        }

        $requested = $name !== null ? $name : (isset($file['name']) ? $file['name'] : '');
        $safeBase = is_string($requested)
            ? $this->safeSegment(basename(pathinfo($requested, PATHINFO_FILENAME)))
            : false;
        if ($safeBase === false) {
            return $this->fail(400, 'Invalid filename.');
        }

        // The saved extension always comes from the verified image type,
        // never from the client-supplied name — so even a crafted
        // "polyglot" file that fooled the header check is only ever
        // stored with a safe, non-executable extension.
        $safeName = "{$safeBase}.{$ext}";

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!call_user_func($this->mover, $file['tmp_name'], $dir . $safeName)) {
            return $this->fail(500, 'Failed to save the uploaded file.');
        }

        return array('ok' => true, 'path' => $relative . $safeName);
    }

    // Finds an existing Photo by its Photo path. Every segment is checked
    // individually, so a crafted path can never escape the roots.
    // Returns array('ok' => true, 'full_path', 'category', 'visibility',
    // 'content_type') or array('ok' => false, 'reason' =>
    // 'invalid_path' | 'not_found', 'status' => int, 'error' => string).
    public function resolve($path)
    {
        if (!is_string($path)) {
            return $this->invalidPath();
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($this->safeSegment($segment) === false) {
                return $this->invalidPath();
            }
        }

        $category = $segments[0];
        if (!isset(self::CATEGORIES[$category])) {
            return $this->invalidPath();
        }
        $def = self::CATEGORIES[$category];
        $expected = $def['layout'] === 'flat' ? 2 : 3;
        if (count($segments) !== $expected) {
            return $this->invalidPath();
        }

        $fullPath = $this->rootFor($def) . implode('/', $segments);
        if (!is_file($fullPath)) {
            return array('ok' => false, 'reason' => 'not_found', 'status' => 404, 'error' => 'Photo not found.');
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        return array(
            'ok' => true,
            'full_path' => $fullPath,
            'category' => $category,
            'visibility' => $def['visibility'],
            'content_type' => isset(self::EXT_TO_MIME[$ext]) ? self::EXT_TO_MIME[$ext] : 'application/octet-stream',
        );
    }

    // Deletes a Photo's file. Knows nothing about whether it is in use —
    // that is the caller's rule. Returns array('ok' => true) or a failure
    // with 'reason' of 'invalid_path', 'not_found' or 'delete_failed'.
    public function remove($path)
    {
        $found = $this->resolve($path);
        if (!$found['ok']) {
            return $found;
        }
        if (!@unlink($found['full_path'])) {
            return array('ok' => false, 'reason' => 'delete_failed', 'status' => 500, 'error' => 'Failed to delete the file.');
        }
        return array('ok' => true);
    }

    // Every Photo in the categories the gallery lists, as
    // array('path', 'visibility', 'size_bytes', 'modified_at') rows.
    public function listPhotos()
    {
        $photos = array();

        foreach (self::CATEGORIES as $category => $def) {
            if (!$def['listed']) {
                continue;
            }
            $dir = $this->rootFor($def) . $category . '/';
            if (!is_dir($dir)) {
                continue;
            }

            if ($def['layout'] === 'flat') {
                $this->collectFiles($dir, $category, $def['visibility'], $photos);
                continue;
            }

            $buildings = @scandir($dir);
            if ($buildings === false) {
                continue;
            }
            foreach ($buildings as $building) {
                if ($building === '.' || $building === '..' || !is_dir($dir . $building)) {
                    continue;
                }
                $this->collectFiles($dir . $building . '/', $category . '/' . $building, $def['visibility'], $photos);
            }
        }

        return $photos;
    }

    private function collectFiles($dir, $relativeDir, $visibility, array &$photos)
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            $fullPath = $dir . $entry;
            if ($entry === '.' || $entry === '..' || is_dir($fullPath)) {
                continue;
            }
            $photos[] = array(
                'path' => $relativeDir . '/' . $entry,
                'visibility' => $visibility,
                'size_bytes' => filesize($fullPath),
                'modified_at' => filemtime($fullPath),
            );
        }
    }

    private function rootFor(array $def)
    {
        return $def['visibility'] === 'public' ? $this->publicRoot : $this->protectedRoot;
    }

    // One path segment (a building or a filename base) is safe only if
    // it is made of plain filename characters and isn't "." or ".." —
    // both of which pass the character check on their own (they are
    // composed entirely of allowed characters), so a path of ".."
    // segments would otherwise escape the root. A real bug caught during
    // review, not defensive-only code.
    private function safeSegment($segment)
    {
        if (!is_string($segment)) {
            return false;
        }
        if (preg_match('/^\.+$/', $segment) || !preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
            return false;
        }
        return $segment;
    }

    // The real defense against arbitrary code execution — checked BEFORE
    // the file is moved anywhere. getimagesize() reads the actual header
    // bytes, so a PHP script named "photo.jpg" fails outright.
    private function verifiedExtension($tmpPath)
    {
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            return false;
        }
        return isset(self::MIME_TO_EXT[$info['mime']]) ? self::MIME_TO_EXT[$info['mime']] : false;
    }

    // Confirms a usable uploaded file arrived, with a SPECIFIC reason
    // when it didn't. The point of this over a plain empty($_FILES)
    // check: when a request exceeds post_max_size, PHP discards $_POST
    // AND $_FILES entirely, so "too big" looks identical to "no file
    // attached". Panoramas are 5–30 MB, so that's a real failure mode.
    // Returns true or a failure array.
    private function checkReceived(array $incoming)
    {
        if (!empty($incoming['body_discarded'])) {
            $limit = $this->limit('post_max_size');
            $bytes = isset($incoming['request_bytes']) ? (int) $incoming['request_bytes'] : 0;
            return $this->fail(413, $limit > 0
                ? 'That upload is too large — the request is ' . $this->humanSize($bytes)
                  . ' but this server accepts at most ' . $this->humanSize($limit) . ' per request. '
                  . 'Use a smaller image, or raise post_max_size (and upload_max_filesize) in php.ini.'
                : 'That upload is too large for this server. Use a smaller image, or raise '
                  . 'post_max_size and upload_max_filesize in php.ini.');
        }

        $file = isset($incoming['file']) ? $incoming['file'] : null;
        if (empty($file) || !isset($file['error'])) {
            return $this->fail(400, 'No file was attached to the request (expected form field "file").');
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                return true;

            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $limit = $this->limit('upload_max_filesize');
                return $this->fail(413, $limit > 0
                    ? 'That file is too large — the limit for a single upload is ' . $this->humanSize($limit)
                      . '. Use a smaller image, or raise upload_max_filesize in php.ini.'
                    : 'That file is larger than this server allows for a single upload.');

            case UPLOAD_ERR_PARTIAL:
                return $this->fail(400, 'The file only uploaded partially — check your connection and try again.');

            case UPLOAD_ERR_NO_FILE:
                return $this->fail(400, 'No file was attached to the request.');

            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
            case UPLOAD_ERR_EXTENSION:
                // 'log' is for the controller to record — the message
                // shown to the admin deliberately says nothing specific.
                $failure = $this->fail(500, 'The server could not save the uploaded file. Please tell an administrator.');
                $failure['log'] = 'Upload failed server-side (PHP upload error code '
                    . (int) $file['error'] . ') — check tmp dir / permissions / php extensions.';
                return $failure;

            default:
                return $this->fail(400, 'The upload failed (error code ' . (int) $file['error'] . ').');
        }
    }

    private function fail($status, $message)
    {
        return array('ok' => false, 'status' => $status, 'error' => $message);
    }

    private function invalidPath()
    {
        return array('ok' => false, 'reason' => 'invalid_path', 'status' => 400, 'error' => 'Invalid path.');
    }

    private function limit($key)
    {
        if (isset($this->limits[$key])) {
            return (int) $this->limits[$key];
        }
        return $this->iniSizeBytes(ini_get($key));
    }

    // Converts a php.ini shorthand size ("64M", "8K", "1G") to bytes.
    // Returns 0 when empty or "0" — for post_max_size, 0 legitimately
    // means "no limit", so callers treat 0 as "don't mention a number".
    private function iniSizeBytes($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '' || (int) $raw === 0) {
            return 0;
        }
        $value = (int) $raw;
        switch (strtolower(substr($raw, -1))) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return $value;
        }
    }

    // Bytes -> a short human string for error messages ("18.4 MB").
    private function humanSize($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }
}
