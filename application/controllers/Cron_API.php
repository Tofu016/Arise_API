<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Smtp_mailer.php';

// Extends CI_Controller directly, not MY_Controller — the CORS/OPTIONS
// handling and Auth_Model loading in that shared base are only
// meaningful for actual HTTP requests from the React app; this is
// meant to run exclusively via CLI (Windows Task Scheduler → php
// index.php Cron_API processEmails), never as a public web endpoint.
//
// SMTP credentials come from Arise_API/.env — see Smtp_mailer and
// .env.example. index.php loads phpdotenv before this runs, so $_ENV is
// populated for CLI just as for HTTP.
class Cron_API extends CI_Controller
{
    // How long a sent email stays in email_queue before purgeExpired()
    // deletes it.
    const SENT_EMAIL_DAYS = 30;

    public function __construct()
    {
        parent::__construct();

        // Refuses to run over HTTP at all — there's no reason this
        // should ever be reachable as a public URL. A repeatedly-hit
        // web endpoint that sends real emails is both a spam vector and
        // a way to hammer the SMTP relay; CLI-only avoids that risk
        // entirely rather than trying to secure a web-facing version of
        // this.
        if (!$this->input->is_cli_request()) {
            show_404();
        }

        $this->load->database();
        $this->load->model('Email_Model');

        // Loads Composer's autoloader directly, just for this
        // controller — no application-wide config.php change needed,
        // since PHPMailer is only used here.
        require_once APPPATH . '../vendor/autoload.php';
    }

    // Sends what is waiting in the email queue and reports, in the format
    // the cron log has always used. A failed email stays queued, so the
    // next scheduled run tries it again — see Email_Model::deliverPending.
    public function processEmails()
    {
        $result = $this->Email_Model->deliverPending(Smtp_mailer::fromEnv($_ENV));

        foreach ($result['failed'] as $failure) {
            echo "Failed to send to {$failure['to']}: {$failure['error']}\n";
        }

        echo 'Processed: ' . count($result['sent']) . ' sent, ' . count($result['failed']) . " failed.\n";
    }

    // Housekeeping: deletes what has outlived its use � expired login and
    // password-reset tokens, and emails sent more than SENT_EMAIL_DAYS ago.
    // Nothing here changes what the API accepts (expired tokens are already
    // refused), so it is safe to run at any time and as often as wanted;
    // daily is plenty. Same CLI-only guard as processEmails.
    public function purgeExpired()
    {
        $this->load->model('Auth_Model');

        $tokens = $this->Auth_Model->purgeExpiredTokens();
        $emails = $this->Email_Model->purgeSent(self::SENT_EMAIL_DAYS);

        echo 'Purged: ' . $tokens['auth_tokens'] . ' login tokens, '
            . $tokens['password_resets'] . ' reset tokens, '
            . $emails . " sent emails.
";
    }
}
