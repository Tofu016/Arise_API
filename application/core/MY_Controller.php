<?php
defined('BASEPATH') or exit('No direct script access allowed');

// A plain file, not loaded through CI's loader: it holds static methods
// and an exception class, and is never instantiated as a library.
require_once APPPATH . 'libraries/Api_response.php';

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

    // Call at the top of any method that should be admin-only — stops
    // the action with a 401/403 reply if the check fails, same shape as
    // how the original Firestore rules simply refused a query outright
    // rather than letting application code decide what to do about it.
    // Throws an Api_abort that _remap turns into the reply; only ever
    // call this from inside an action, never a constructor.
    protected function requireAdmin()
    {
        if (!$this->signedIn()) {
            throw new Api_abort(Api_response::fail(401, 'Not signed in.'));
        }
        if (!$this->isAdmin()) {
            throw new Api_abort(Api_response::fail(403, 'Admin access required.'));
        }
    }

    // Call at the top of any method that requires a genuinely approved
    // (not just "pending") account.
    protected function requireApproved()
    {
        if (!$this->signedIn()) {
            throw new Api_abort(Api_response::fail(401, 'Not signed in.'));
        }
        if (!$this->isApproved()) {
            throw new Api_abort(Api_response::fail(403, 'Account not yet approved.'));
        }
    }

    // Every request to a controller extending this one comes through
    // here (CodeIgniter calls _remap in place of the action). Runs the
    // action, then sends whatever Api_response it returned or a guard
    // aborted with. An action that returns nothing is one not yet moved
    // to return values: it has already echoed its own reply.
    public function _remap($method, $params = array())
    {
        if (!Api_response::isAction($this, $method, array('MY_Controller', 'CI_Controller'))) {
            show_404();
            return;
        }

        $response = Api_response::run(function () use ($method, $params) {
            return call_user_func_array(array($this, $method), $params);
        });
        if ($response !== null) {
            $response->emit();
        }
    }

    // The one Photo store every upload, serve and gallery endpoint
    // shares. Roots are env-driven so production can keep files outside
    // the web root or on a separate volume; the defaults are the
    // in-project locations these controllers have always used (FCPATH is
    // where index.php lives; going up two levels from it lands outside
    // htdocs entirely, genuinely unreachable by any web request).
    protected function photoStore()
    {
        if (!isset($this->photo_store)) {
            $this->load->library('Photo_store', array(
                'public_root' => !empty($_ENV['UPLOAD_ROOT'])
                    ? $_ENV['UPLOAD_ROOT']
                    : FCPATH . 'uploads/',
                'protected_root' => !empty($_ENV['PROTECTED_UPLOAD_ROOT'])
                    ? $_ENV['PROTECTED_UPLOAD_ROOT']
                    : dirname(FCPATH, 2) . '/protected-uploads/',
            ));
        }
        return $this->photo_store;
    }

    // Saves the uploaded image as a Photo in $category and emits the
    // standard reply: { success: true, path } on success, or the
    // failure's own JSON error and HTTP status. $building is only for
    // per-building categories. Callers check requireAdmin() first.
    protected function savePhoto($category, $building = null)
    {
        $store = $this->photoStore();
        $name = isset($_POST['filename']) ? $_POST['filename'] : null;
        $result = $store->save($category, Photo_store::incomingFromGlobals(), $name, $building);

        if ($result['ok']) {
            echo json_encode(array('success' => true, 'path' => $result['path']));
            return;
        }
        $this->respondWithPhotoFailure($result);
    }

    // Emits the standard { success: false, error } shape with the
    // failure result's HTTP status. Details the admin shouldn't see
    // (result['log']) go to the server log instead.
    protected function respondWithPhotoFailure(array $result)
    {
        if (isset($result['log'])) {
            log_message('error', $result['log']);
        }
        http_response_code($result['status']);
        echo json_encode(array('success' => false, 'error' => $result['error']));
    }
}
