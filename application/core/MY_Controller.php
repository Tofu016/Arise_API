<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Shared base every API controller should extend instead of
// CI_Controller directly — CodeIgniter 3's own, documented extension
// mechanism (any file named exactly MY_Controller in application/core/
// becomes available this way, via the default $config['subclass_prefix']
// = 'MY_'). Consolidates three things every controller previously
// duplicated on its own (see TourSections_API's original version):
// CORS + OPTIONS preflight handling, JSON/form-body parsing, and now
// the actual permission checks — isAdmin()/isApproved() — mirroring
// the isAdmin()/isApproved() helper functions from the original
// Firestore security rules, just checked here instead of inside the
// database itself.
class MY_Controller extends CI_Controller
{
    // Which website is allowed to call this API (CORS). Env-driven so
    // production can point at the real front-end origin without a code
    // change; falls back to the local Vite dev server.
    private $allowedOrigin;

    // Cached after the first check within a single request, so
    // repeated isAdmin()/isApproved() calls in the same request don't
    // each re-validate the token against the database.
    private $currentUser = null;
    private $currentUserChecked = false;

    public function __construct()
    {
        parent::__construct();

        $this->allowedOrigin = $_ENV['CORS_ORIGIN'] ?? 'http://localhost:5173';

        header('Access-Control-Allow-Origin: ' . $this->allowedOrigin);
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

        if ($this->input->method() === 'options') {
            http_response_code(200);
            exit();
        }

        $this->load->database();
        $this->load->model('Auth_Model');
        date_default_timezone_set('Asia/Manila');
    }

    // Reads either a JSON request body or standard form-encoded POST
    // data, whichever was actually sent. See TourSections_API's
    // original version of this for the full reasoning — moved here so
    // every controller shares one copy instead of each re-implementing
    // it.
    protected function getInput()
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return $this->input->post() ?: array();
    }

    // Pulls the bearer token from the Authorization header and
    // validates it via Auth_Model — returns the associated user's row
    // (with role) if genuinely valid, or null otherwise. Protected, not
    // public — controllers should use isAdmin()/isApproved()/
    // requireAdmin()/requireApproved() below rather than reaching in
    // here directly, same as the original Firestore rules never exposed
    // "the current auth token" itself, only the derived signedIn()/
    // isAdmin()/isApproved() checks built on top of it.
    protected function getCurrentUser()
    {
        if ($this->currentUserChecked) {
            return $this->currentUser;
        }
        $this->currentUserChecked = true;

        $header = $this->input->get_request_header('Authorization');
        if (empty($header) || stripos($header, 'Bearer ') !== 0) {
            $this->currentUser = null;
            return null;
        }
        $token = trim(substr($header, 7));
        $this->currentUser = $this->Auth_Model->validateToken($token) ?: null;
        return $this->currentUser;
    }

    // Mirrors signedIn() from the original Firestore rules.
    protected function signedIn()
    {
        return $this->getCurrentUser() !== null;
    }

    // Mirrors isAdmin() from the original Firestore rules.
    protected function isAdmin()
    {
        $user = $this->getCurrentUser();
        return $user !== null && $user['role'] === 'admin';
    }

    // Mirrors isApproved() from the original Firestore rules.
    protected function isApproved()
    {
        $user = $this->getCurrentUser();
        return $user !== null && in_array($user['role'], array('user', 'admin'), true);
    }

    // Call at the top of any method that should be admin-only — sends
    // 401/403 and stops execution immediately if the check fails, same
    // shape as how the original Firestore rules simply refused a query
    // outright rather than letting application code decide what to do
    // about it.
    protected function requireAdmin()
    {
        if (!$this->signedIn()) {
            http_response_code(401);
            echo json_encode(array('success' => false, 'error' => 'Not signed in.'));
            exit();
        }
        if (!$this->isAdmin()) {
            http_response_code(403);
            echo json_encode(array('success' => false, 'error' => 'Admin access required.'));
            exit();
        }
    }

    // Call at the top of any method that requires a genuinely approved
    // (not just "pending") account.
    protected function requireApproved()
    {
        if (!$this->signedIn()) {
            http_response_code(401);
            echo json_encode(array('success' => false, 'error' => 'Not signed in.'));
            exit();
        }
        if (!$this->isApproved()) {
            http_response_code(403);
            echo json_encode(array('success' => false, 'error' => 'Account not yet approved.'));
            exit();
        }
    }

    // Confirms a usable uploaded file is present under $_FILES[$field].
    // On success returns TRUE and the caller proceeds. On failure it emits
    // a JSON error response with a SPECIFIC reason and the right HTTP
    // status, then returns FALSE — the caller should just `return;`.
    //
    // The point of this over a plain `empty($_FILES['file'])` check: when
    // an upload exceeds post_max_size, PHP throws away $_POST AND $_FILES
    // entirely, so "the request body was too big" looks identical to "no
    // file was attached" — both surface as the misleading
    // "No valid file was uploaded." Panoramas are large enough (5–30 MB,
    // sometimes more) that this is a real failure mode, not a hypothetical.
    protected function requireUploadedFile($field = 'file')
    {
        // Case 1 — the whole POST body blew past post_max_size. Bytes were
        // sent (CONTENT_LENGTH > 0) but PHP discarded everything, so both
        // superglobals are empty. This is the case the naive check gets
        // wrong.
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($this->input->method() === 'post' && $contentLength > 0 && empty($_POST) && empty($_FILES)) {
            $limit = $this->iniSizeBytes('post_max_size');
            $this->uploadFail(413, $limit > 0
                ? 'That upload is too large — the request is ' . $this->humanSize($contentLength)
                  . ' but this server accepts at most ' . $this->humanSize($limit) . ' per request. '
                  . 'Use a smaller image, or raise post_max_size (and upload_max_filesize) in php.ini.'
                : 'That upload is too large for this server. Use a smaller image, or raise '
                  . 'post_max_size and upload_max_filesize in php.ini.');
            return false;
        }

        // Case 2 — no file part at all (genuinely nothing attached).
        if (empty($_FILES[$field]) || !isset($_FILES[$field]['error'])) {
            $this->uploadFail(400, 'No file was attached to the request (expected form field "' . $field . '").');
            return false;
        }

        // Case 3 — a file part is present; PHP's own per-file error code
        // tells us whether it actually arrived intact.
        switch ($_FILES[$field]['error']) {
            case UPLOAD_ERR_OK:
                return true;

            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $limit = $this->iniSizeBytes('upload_max_filesize');
                $this->uploadFail(413, $limit > 0
                    ? 'That file is too large — the limit for a single upload is ' . $this->humanSize($limit)
                      . '. Use a smaller image, or raise upload_max_filesize in php.ini.'
                    : 'That file is larger than this server allows for a single upload.');
                return false;

            case UPLOAD_ERR_PARTIAL:
                $this->uploadFail(400, 'The file only uploaded partially — check your connection and try again.');
                return false;

            case UPLOAD_ERR_NO_FILE:
                $this->uploadFail(400, 'No file was attached to the request.');
                return false;

            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
            case UPLOAD_ERR_EXTENSION:
                log_message('error', 'Upload failed server-side (PHP upload error code '
                    . $_FILES[$field]['error'] . ') — check tmp dir / permissions / php extensions.');
                $this->uploadFail(500, 'The server could not save the uploaded file. Please tell an administrator.');
                return false;

            default:
                $this->uploadFail(400, 'The upload failed (error code ' . (int) $_FILES[$field]['error'] . ').');
                return false;
        }
    }

    // Emits the standard { success:false, error } JSON shape with an
    // explicit HTTP status. Same shape every other guard here uses.
    private function uploadFail($status, $message)
    {
        http_response_code($status);
        echo json_encode(array('success' => false, 'error' => $message));
    }

    // Converts a php.ini shorthand size ("64M", "8K", "1G") to a byte
    // count. Returns 0 when the setting is empty or "0" — for
    // post_max_size, 0 legitimately means "no limit", so callers treat 0
    // as "don't mention a specific number".
    private function iniSizeBytes($key)
    {
        $raw = trim((string) ini_get($key));
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
