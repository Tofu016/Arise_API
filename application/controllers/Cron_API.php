<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Rate_limit.php';

// Extends CI_Controller directly, not MY_Controller: the CORS/OPTIONS
// handling and Auth_Model loading in that shared base are only
// meaningful for actual HTTP requests from the React app; this is
// meant to run exclusively via CLI (Windows Task Scheduler, e.g.
// php index.php Cron_API purgeExpired), never as a public web endpoint.
class Cron_API extends CI_Controller
{
    // How long a web analytics session may go without a new tracked
    // event before closeStaleSessions() considers it abandoned.
    const STALE_SESSION_MINUTES = 30;

    public function __construct()
    {
        parent::__construct();

        // Refuses to run over HTTP at all: there's no reason this
        // should ever be reachable as a public URL.
        if (!$this->input->is_cli_request()) {
            show_404();
        }

        // Same timezone MY_Controller sets for HTTP requests. Without it the
        // CLI falls back to php.ini's default, so closeStaleSessions' cutoff
        // would be compared against Manila-time rows in a different zone.
        date_default_timezone_set('Asia/Manila');

        $this->load->database();
    }

    // Housekeeping: deletes expired login tokens and rate-limit hits too old
    // for any limit to read. Nothing here changes what the API accepts
    // (expired tokens are already refused), so it is safe to run at any time
    // and as often as wanted; daily is plenty. Same CLI-only guard as the
    // rest of this controller.
    public function purgeExpired()
    {
        $this->load->model('Auth_Model');
        $this->load->model('Rate_limit_Model');

        echo 'Purged: ' . $this->Auth_Model->purgeExpiredTokens() . " login tokens.\n";
        $cutoff = Rate_limit::windowStart(date('Y-m-d H:i:s'), Rate_limit::KEEP_SECONDS);
        echo 'Purged: ' . $this->Rate_limit_Model->purgeOlderThan($cutoff) . " rate limit hits.\n";
    }

    // Web analytics sessions have no explicit end event (see
    // useAnalytics.js) — this closes any that have gone
    // STALE_SESSION_MINUTES without a new tracked event, so
    // avgDurationSeconds and the funnel don't wait on them forever. Same
    // CLI-only guard as the rest of this controller; run it as often as
    // purgeExpired (see DEPLOY.md's cron setup).
    public function closeStaleSessions()
    {
        $this->load->model('Analytics_Model');
        $closed = $this->Analytics_Model->closeStaleSessions(self::STALE_SESSION_MINUTES);
        echo "Closed: {$closed} stale web analytics sessions.\n";
    }
}
