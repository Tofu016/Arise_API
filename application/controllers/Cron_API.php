<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Extends CI_Controller directly, not MY_Controller — the CORS/OPTIONS
// handling and Auth_Model loading in that shared base are only
// meaningful for actual HTTP requests from the React app; this is
// meant to run exclusively via CLI (Windows Task Scheduler → php
// index.php Cron_API processEmails), never as a public web endpoint.
//
// ============================================================
// SMTP credentials come from Arise_API/.env (SMTP_HOST, SMTP_PORT,
// SMTP_USER, SMTP_PASS, SMTP_FROM_EMAIL, SMTP_FROM_NAME) — never
// hardcoded here. See .env.example for the template. The fallbacks
// below are inert placeholders so the file still parses without a
// .env; email will simply fail to authenticate until .env is filled.
// index.php loads phpdotenv for HTTP requests, but this controller
// runs via CLI where index.php still executes first, so $_ENV is
// populated by the time we get here.
// ============================================================
define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? 'smtp.example.com');
define('SMTP_PORT', (int) ($_ENV['SMTP_PORT'] ?? 587));
define('SMTP_USERNAME', $_ENV['SMTP_USER'] ?? '');
define('SMTP_PASSWORD', $_ENV['SMTP_PASS'] ?? '');
define('SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@sdca.edu.ph');
define('SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME'] ?? 'ARISE Campus Navigator');

class Cron_API extends CI_Controller
{
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

    public function processEmails()
    {
        $pending = $this->Email_Model->getPending();
        $sentCount = 0;
        $failedCount = 0;

        foreach ($pending as $row) {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->Port = SMTP_PORT;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USERNAME;
                $mail->Password = SMTP_PASSWORD;
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                $mail->addAddress($row['to_email']);
                $mail->isHTML(true);
                $mail->Subject = $row['subject'];
                $mail->Body = $row['body_html'];

                $mail->send();
                $this->Email_Model->markSent($row['id']);
                $sentCount++;
            } catch (Exception $e) {
                // Deliberately NOT marked sent — left as sent_at IS NULL
                // so the next scheduled run picks it up and tries again,
                // rather than silently losing a failed email. If a
                // specific row keeps failing indefinitely (a bad
                // address, for instance), that becomes visible as an
                // ever-growing queue rather than a silent data loss.
                echo "Failed to send to {$row['to_email']}: {$mail->ErrorInfo}\n";
                $failedCount++;
            }
        }

        echo "Processed: {$sentCount} sent, {$failedCount} failed.\n";
    }
}