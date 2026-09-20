<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Sends one email over SMTP with PHPMailer. This is the production
// adapter of the "mailer" seam Email_Model::deliverPending works against:
// anything with send($to, $subject, $bodyHtml) that throws when the
// message could not be sent. Tests use a recording fake instead.
//
// SMTP credentials come from the environment (SMTP_HOST, SMTP_PORT,
// SMTP_USER, SMTP_PASS, SMTP_FROM_EMAIL, SMTP_FROM_NAME) — never
// hardcoded. See .env.example. The fallbacks are inert placeholders so
// nothing breaks without a .env; email simply fails to authenticate
// until it is filled in.
class Smtp_mailer
{
    private $config;

    // $config keys: host, port, username, password, from_email, from_name.
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function fromEnv(array $env)
    {
        return new static(array(
            'host' => $env['SMTP_HOST'] ?? 'smtp.example.com',
            'port' => (int) ($env['SMTP_PORT'] ?? 587),
            'username' => $env['SMTP_USER'] ?? '',
            'password' => $env['SMTP_PASS'] ?? '',
            'from_email' => $env['SMTP_FROM_EMAIL'] ?? 'noreply@sdca.edu.ph',
            'from_name' => $env['SMTP_FROM_NAME'] ?? 'ARISE Campus Navigator',
        ));
    }

    // Throws a RuntimeException carrying PHPMailer's own error text when
    // the message could not be sent.
    public function send($toEmail, $subject, $bodyHtml)
    {
        $mail = $this->newMail();
        try {
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->Port = $this->config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'];
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->setFrom($this->config['from_email'], $this->config['from_name']);
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $bodyHtml;

            $mail->send();
        } catch (Exception $e) {
            $reason = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
            throw new RuntimeException($reason, 0, $e);
        }
    }

    // A fresh PHPMailer per message, with exceptions enabled. Separate so
    // a test can substitute one that records instead of connecting.
    protected function newMail()
    {
        return new PHPMailer\PHPMailer\PHPMailer(true);
    }
}
