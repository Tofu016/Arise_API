<?php
defined('BASEPATH') or exit('No direct script access allowed');

// A plain file, not loaded through CI's loader: it holds static methods
// and an exception class, and is never instantiated as a library.
require_once APPPATH . 'libraries/Api_response.php';
require_once APPPATH . 'libraries/Api_input.php';
require_once APPPATH . 'libraries/Auth_session.php';

// Shared base every API controller should extend instead of
// CI_Controller directly — CodeIgniter 3's own, documented extension
// mechanism (any file named exactly MY_Controller in application/core/
// becomes available this way, via the default $config['subclass_prefix']
// = 'MY_'). Consolidates three things every controller previously
// duplicated on its own (see TourSections_API's original version):
// CORS + OPTIONS preflight handling, JSON/form-body parsing, and now
// the actual permission check — isAdmin() — mirroring the isAdmin()
// helper function from the original Firestore security rules, just
// checked here instead of inside the database itself.
class MY_Controller extends CI_Controller
{
    // Which website is allowed to call this API (CORS). Env-driven so
    // production can point at the real front-end origin without a code
    // change; falls back to the local Vite dev server.
    private $allowedOrigin;

    // Cached after the first check within a single request, so
    // repeated isAdmin() calls in the same request don't each
    // re-validate the token against the database.
    private $currentUser = null;
    private $currentUserChecked = false;

    public function __construct()
    {
        parent::__construct();

        // CORS_ORIGIN may be a comma-separated list (e.g. localhost plus a
        // LAN address for kiosk/phone testing); the request's own Origin
        // is echoed back only if it is on that list.
        $allowed = array_map('trim', explode(',', $_ENV['CORS_ORIGIN'] ?? 'http://localhost:5173'));
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $this->allowedOrigin = in_array($requestOrigin, $allowed, true) ? $requestOrigin : $allowed[0];

        header('Access-Control-Allow-Origin: ' . $this->allowedOrigin);
        header('Vary: Origin');
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

    // Identifies the caller from the Authorization header (see
    // Auth_session) and validates the token via Auth_Model — returns the
    // associated user's row (with role) if genuinely valid, or null
    // otherwise. Protected, not public — controllers should use
    // isAdmin()/requireAdmin() below rather than reaching in here
    // directly, same as the original Firestore rules never exposed "the
    // current auth token" itself, only the derived signedIn()/isAdmin()
    // checks built on top of it.
    protected function getCurrentUser()
    {
        if ($this->currentUserChecked) {
            return $this->currentUser;
        }
        $this->currentUserChecked = true;

        $this->currentUser = Auth_session::userFor(
            $this->input->get_request_header('Authorization'),
            array($this->Auth_Model, 'validateToken')
        );
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

    // The web app's own address (scheme + host, no trailing slash), for
    // building links that point at it — the password-reset email's link,
    // for one. FRONTEND_URL if set; otherwise CORS_ORIGIN, which is the
    // same address in a normal deployment (see DEPLOY.md), so setting
    // that alone is enough; otherwise the local Vite dev server. A blank
    // value counts as unset.
    protected function frontendUrl()
    {
        foreach (array('FRONTEND_URL', 'CORS_ORIGIN') as $key) {
            if (!empty($_ENV[$key])) {
                return rtrim(trim(explode(',', $_ENV[$key])[0]), '/');
            }
        }
        return 'http://localhost:5173';
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

    // Saves the uploaded image as a Photo in $category and returns the
    // reply: { success: true, path } on success, or the failure's own
    // error and HTTP status. $building is only for per-building
    // categories. Callers check requireAdmin() first. Details the admin
    // shouldn't see (result['log']) go to the server log instead.
    protected function savePhoto($category, $building = null)
    {
        $store = $this->photoStore();
        $name = isset($_POST['filename']) ? $_POST['filename'] : null;
        $result = $store->save($category, Photo_store::incomingFromGlobals(), $name, $building);

        if ($result['ok']) {
            return Api_response::ok(array('path' => $result['path']));
        }
        if (isset($result['log'])) {
            log_message('error', $result['log']);
        }
        return Api_response::fail($result['status'], $result['error']);
    }
}
