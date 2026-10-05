<?php
defined('BASEPATH') or exit('No direct script access allowed');

// A plain file, not loaded through CI's loader: it holds static methods
// and an exception class, and is never instantiated as a library.
require_once APPPATH . 'libraries/Api_response.php';
require_once APPPATH . 'libraries/Api_input.php';
require_once APPPATH . 'libraries/Auth_session.php';
require_once APPPATH . 'libraries/Rate_limit.php';
// Only for its static Photo_store::isPhotoPath() check; photoStore() still
// loads the instance through CI (the loader creates it when the class is
// already declared but not yet attached to the controller).
require_once APPPATH . 'libraries/Photo_store.php';

// Shared base every API controller should extend instead of
// CI_Controller directly — CodeIgniter 3's own, documented extension
// mechanism (any file named exactly MY_Controller in application/core/
// becomes available this way, via the default $config['subclass_prefix']
// = 'MY_'). Consolidates three things every controller would otherwise
// duplicate: CORS + OPTIONS preflight handling, JSON/form-body parsing,
// and the permission check (requireAdmin()).
class MY_Controller extends CI_Controller
{
    // Which website is allowed to call this API (CORS). Env-driven so
    // production can point at the real front-end origin without a code
    // change; falls back to the local Vite dev server.
    private $allowedOrigin;

    // Cached after the first check within a single request, so
    // repeated guard calls in the same request don't each re-validate
    // the token against the database.
    private $currentAdmin = null;
    private $currentAdminChecked = false;

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
        header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Kiosk-Token');

        if ($this->input->method() === 'options') {
            http_response_code(200);
            exit();
        }

        $this->load->database();
        $this->load->model('Auth_Model');
        $this->load->model('Rate_limit_Model');
        date_default_timezone_set('Asia/Manila');
    }

    // Reads either a JSON request body or standard form-encoded POST
    // data, whichever was actually sent. Lives here so every controller
    // shares one copy instead of each re-implementing it.
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
    // Auth_session) and validates the token via Auth_Model: the admin's
    // row if the token is genuinely valid, or null otherwise. Only admins
    // can sign in at all, so a valid token is an admin's. Protected, not
    // public: controllers use signedIn()/requireAdmin() below rather than
    // reaching in here directly.
    protected function getCurrentAdmin()
    {
        if ($this->currentAdminChecked) {
            return $this->currentAdmin;
        }
        $this->currentAdminChecked = true;

        $this->currentAdmin = Auth_session::adminFor(
            $this->input->get_request_header('Authorization'),
            array($this->Auth_Model, 'validateToken')
        );
        return $this->currentAdmin;
    }

    protected function signedIn()
    {
        return $this->getCurrentAdmin() !== null;
    }

    // Call at the top of any method that should be admin-only: stops the
    // action with a 401 reply when no admin is signed in. Throws an
    // Api_abort that _remap turns into the reply; only ever call this from
    // inside an action, never a constructor.
    protected function requireAdmin()
    {
        if (!$this->signedIn()) {
            throw new Api_abort(Api_response::fail(401, 'Not signed in.'));
        }
    }

    // Sends one HTML email through CodeIgniter's email library (see
    // config/email.php). A failure is logged and returned as false, never
    // thrown: an email that cannot go out must not fail the request that
    // asked for it (the account exists either way).
    protected function sendMail($to, array $mail)
    {
        $this->load->library('email');
        $this->email->clear();
        $this->email->from($_ENV['EMAIL_FROM'] ?? 'noreply@sdca.edu.ph', $_ENV['EMAIL_FROM_NAME'] ?? 'ARISE Campus Navigator');
        $this->email->to($to);
        $this->email->subject($mail['subject']);
        $this->email->message($mail['html']);
        if (!@$this->email->send(false)) {
            log_message('error', 'Email to ' . $to . ' failed: ' . $this->email->print_debugger(array('headers')));
            return false;
        }
        return true;
    }

    // The web app's own address, for links inside emails. FRONTEND_URL, or
    // the first CORS_ORIGIN, which is the same address in a normal setup.
    protected function frontendUrl()
    {
        $url = trim($_ENV['FRONTEND_URL'] ?? '');
        if ($url !== '') {
            return $url;
        }
        $origins = explode(',', $_ENV['CORS_ORIGIN'] ?? 'http://localhost:5173');
        return trim($origins[0]);
    }

    protected function clientIp()
    {
        return $this->input->ip_address();
    }

    // Stops the action with a 429 when $subject already has $max hits in
    // bucket $bucket inside the last $windowSeconds. Check only: nothing is
    // recorded, so a caller that counts failures (login) records them itself
    // with recordRateHit(), and one that counts every request calls both.
    // $message may hold one %d, filled with the seconds to wait. The reply
    // also carries retry_after for the client. Same Api_abort rule as
    // requireAdmin(): only call this from inside an action.
    protected function enforceRateLimit($bucket, $subject, $max, $windowSeconds, $message)
    {
        $now = date('Y-m-d H:i:s');
        $since = Rate_limit::windowStart($now, $windowSeconds);
        if ((int) $this->Rate_limit_Model->countSince($bucket, $subject, $since) < $max) {
            return;
        }
        $retryAfter = Rate_limit::retryAfter($this->Rate_limit_Model->oldestSince($bucket, $subject, $since), $now, $windowSeconds);
        throw new Api_abort(Api_response::fail(429, sprintf($message, $retryAfter), array('retry_after' => $retryAfter)));
    }

    protected function recordRateHit($bucket, $subject)
    {
        $this->Rate_limit_Model->record($bucket, $subject, date('Y-m-d H:i:s'));
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
                'protected_root' => $this->protectedUploadRoot(),
            ));
        }
        return $this->photo_store;
    }

    // Downscaled JPEG copies of Photos for the mobile app (see
    // Photo_preview). Cached under the protected root, so the copies are
    // exactly as unreachable by a direct web request as the originals;
    // "_previews" is not a Photo_store category, so it can never collide
    // with a Photo path or show up in the gallery.
    protected function photoPreview()
    {
        if (!isset($this->photo_preview)) {
            $this->load->library('Photo_preview', array(
                'cache_root' => $this->protectedUploadRoot() . '_previews/',
            ));
        }
        return $this->photo_preview;
    }

    private function protectedUploadRoot()
    {
        $root = !empty($_ENV['PROTECTED_UPLOAD_ROOT'])
            ? $_ENV['PROTECTED_UPLOAD_ROOT']
            : dirname(FCPATH, 2) . '/protected-uploads/';
        return rtrim($root, '/\\') . '/';
    }

    // Every Photo path currently referenced anywhere in the database,
    // exactly as stored (e.g. "panoramas/gd1/somefile.webp"), as a set:
    // the definitive "in use" check (see Photo_references). Read fresh on
    // every call, deliberately: Photos_API::delete and Signage_API's media
    // cleanup must not trust a list read before another admin's change.
    protected function referencedPhotoPaths()
    {
        if (!isset($this->photo_references)) {
            $this->load->library('Photo_references', array(
                'reader' => function ($table, array $columns) {
                    return $this->db->select(implode(', ', $columns))->get($table)->result_array();
                },
            ));
        }
        return $this->photo_references->referencedPaths();
    }

    // Stops the action with a 400 unless $path is empty (no photo) or a
    // Photo path in $category — i.e. something save() actually produced,
    // not a guessed file name. $field names the body field in the message.
    protected function requirePhotoPathIn($path, $category, $field = 'photo_path')
    {
        if ($path === null || $path === '') {
            return;
        }
        if (!Photo_store::isPhotoPath($path, $category)) {
            throw new Api_abort(Api_response::fail(400, "{$field} must be an uploaded photo ({$category}/…); upload the photo instead of entering a file name."));
        }
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
