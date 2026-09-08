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
}
