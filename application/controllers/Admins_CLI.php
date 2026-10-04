<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Account_policy.php';

// Command-line admin management: how the very first admin is created on a
// fresh deployment (nobody can sign in to create one through the app),
// and the way back in if every admin has lost their password.
//
//   php index.php Admins_CLI create
//   php index.php Admins_CLI setPassword
//
// Each value is taken from its environment variable (ADMIN_EMAIL,
// ADMIN_NAME, ADMIN_PASSWORD) if set, otherwise prompted on stdin, so a
// password never lands in shell history or the process list. They are not
// command-line arguments because CodeIgniter rejects "@" in the URI that
// CLI arguments become. Extends CI_Controller directly and refuses HTTP,
// like Cron_API: access to the server's shell is the credential.
class Admins_CLI extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_404();
        }
        date_default_timezone_set('Asia/Manila');
        $this->load->database();
        $this->load->model('Admins_Model');
        $this->load->model('Auth_Model');
    }

    public function create()
    {
        $email = trim(strtolower($this->value('ADMIN_EMAIL', 'Email')));
        $name = trim($this->value('ADMIN_NAME', 'Full name'));
        if ($email === '' || $name === '') {
            return $this->fail('Email and full name are required.');
        }
        $error = $this->policyError(function () use ($email) {
            Account_policy::requireEmailDomain($email);
        });
        if ($error) {
            return $this->fail($error);
        }
        if ($this->Admins_Model->emailExists($email)) {
            return $this->fail("An admin with email {$email} already exists. Use setPassword to change its password.");
        }

        $password = $this->value('ADMIN_PASSWORD', 'Password (min ' . Account_policy::MIN_PASSWORD_LENGTH . ' characters, typed text is visible)');
        $error = $this->policyError(function () use ($password) {
            Account_policy::requirePassword($password);
        });
        if ($error) {
            return $this->fail($error);
        }

        $this->Admins_Model->create($email, password_hash($password, PASSWORD_DEFAULT), $name);
        echo "Created admin {$email}.\n";
    }

    public function setPassword()
    {
        $email = trim(strtolower($this->value('ADMIN_EMAIL', 'Email')));
        $admin = $email === '' ? null : $this->Admins_Model->findByEmail($email);
        if (!$admin) {
            return $this->fail("No admin with email {$email}.");
        }

        $password = $this->value('ADMIN_PASSWORD', 'New password (min ' . Account_policy::MIN_PASSWORD_LENGTH . ' characters, typed text is visible)');
        $error = $this->policyError(function () use ($password) {
            Account_policy::requirePassword($password);
        });
        if ($error) {
            return $this->fail($error);
        }

        $this->Admins_Model->updatePassword($admin['id'], password_hash($password, PASSWORD_DEFAULT));
        $this->Auth_Model->deleteAllTokensForAdmin($admin['id']);
        echo "Password updated for {$email}; their sessions were ended.\n";
    }

    private function value($envName, $prompt)
    {
        $fromEnv = getenv($envName);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }
        echo $prompt . ': ';
        return rtrim((string) fgets(STDIN), "\r\n");
    }

    // Account_policy refuses by throwing an Api_abort holding the reply;
    // here only the reply's message is wanted. Null when the check passes.
    private function policyError(callable $check)
    {
        $reply = Api_response::run(function () use ($check) {
            $check();
        });
        return $reply === null ? null : $reply->body()['error'];
    }

    private function fail($message)
    {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}
