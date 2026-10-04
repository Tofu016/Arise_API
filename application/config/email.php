<?php
defined('BASEPATH') or exit('No direct script access allowed');

// CodeIgniter's email library, which loads this file itself. 'mail' is PHP's
// own mail(), which needs no extra service but often lands in spam or never
// arrives from XAMPP or a shared host; set EMAIL_PROTOCOL=smtp and the
// EMAIL_SMTP_* values in .env to send through a real mail server instead.
$config['protocol'] = $_ENV['EMAIL_PROTOCOL'] ?? 'mail';
$config['smtp_host'] = $_ENV['EMAIL_SMTP_HOST'] ?? '';
$config['smtp_port'] = (int) ($_ENV['EMAIL_SMTP_PORT'] ?? 587);
$config['smtp_user'] = $_ENV['EMAIL_SMTP_USER'] ?? '';
$config['smtp_pass'] = $_ENV['EMAIL_SMTP_PASS'] ?? '';
$config['smtp_crypto'] = $_ENV['EMAIL_SMTP_CRYPTO'] ?? 'tls';
$config['mailtype'] = 'html';
$config['charset'] = 'utf-8';
$config['newline'] = "\r\n";
$config['crlf'] = "\r\n";
