<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Account_policy.php';
require_once APPPATH . 'libraries/Account_mail.php';

// Admin login, logout, session recovery, registration and emailed password
// reset. A registered account starts 'pending' and cannot sign in until an
// admin approves it (Admins_API::approve). Admins can also create accounts
// directly (Admins_API) and, for the very first one, the command line
// (Admins_CLI).
class Auth_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Admins_Model');
    }

    // POST /Auth_API/login
    // Body: email, password. Failed attempts are counted per IP and per
    // email (see Rate_limit); once either has LOGIN_MAX_FAILURES inside the
    // window, login answers 429 before even looking at the password, so a
    // correct guess during a lockout gets nothing.
    public function login()
    {
        $data = $this->getInput();
        $email = isset($data['email']) && is_string($data['email']) ? trim(strtolower($data['email'])) : '';
        $password = isset($data['password']) ? $data['password'] : '';

        if ($email === '' || $password === '') {
            return Api_response::fail(400, 'Email and password are required.');
        }

        $ip = Rate_limit::subject($this->clientIp());
        $account = Rate_limit::subject($email);
        $message = 'Too many failed sign-in attempts. Try again in %d seconds.';
        $this->enforceRateLimit('login_ip', $ip, Rate_limit::LOGIN_MAX_FAILURES, Rate_limit::LOGIN_WINDOW, $message);
        $this->enforceRateLimit('login_email', $account, Rate_limit::LOGIN_MAX_FAILURES, Rate_limit::LOGIN_WINDOW, $message);

        $admin = $this->Auth_Model->verifyCredentials($email, $password);
        if (!$admin) {
            $this->recordRateHit('login_ip', $ip);
            $this->recordRateHit('login_email', $account);
            // Deliberately the same error for "no such email" and
            // "wrong password": distinguishing them lets an attacker
            // enumerate which emails are actually registered.
            return Api_response::fail(401, 'Invalid email or password.');
        }

        // Checked only after the password matched, so an unapproved account
        // is revealed to whoever holds its password and nobody else.
        if ($admin['status'] !== 'approved') {
            return Api_response::fail(403, 'Your account is awaiting approval by an administrator.');
        }

        $token = $this->Auth_Model->createToken($admin['id']);

        return Api_response::ok(array(
            'token' => $token,
            'user' => $this->clientAdmin($admin),
        ));
    }

    // POST /Auth_API/register
    // Body: email, password, name
    //
    // Creates a 'pending' account and signs nobody in: an admin has to
    // approve it first.
    public function register()
    {
        $data = $this->getInput();
        $email = isset($data['email']) && is_string($data['email']) ? trim(strtolower($data['email'])) : '';
        $password = isset($data['password']) && is_string($data['password']) ? $data['password'] : '';
        $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';

        if ($email === '' || $password === '' || $name === '') {
            return Api_response::fail(400, 'Email, password, and name are all required.');
        }

        $ip = Rate_limit::subject($this->clientIp());
        $this->enforceRateLimit('register_ip', $ip, Rate_limit::REGISTER_IP_MAX, Rate_limit::REGISTER_WINDOW, 'Too many registrations from this address. Try again in %d seconds.');
        $this->recordRateHit('register_ip', $ip);

        Account_policy::requireEmailDomain($email);
        Account_policy::requirePassword($password);

        if ($this->Admins_Model->emailExists($email)) {
            return Api_response::fail(409, 'An account with this email already exists.');
        }

        $this->Admins_Model->create($email, password_hash($password, PASSWORD_DEFAULT), $name, 'pending');
        $this->sendMail($email, Account_mail::welcome($name));

        return Api_response::ok();
    }

    // POST /Auth_API/forgotPassword
    // Body: email
    //
    // Always answers success, whether or not the email has an account: a
    // distinct "no such email" reply would let anyone probe which addresses
    // are registered. Only an approved account actually gets the email.
    public function forgotPassword()
    {
        $data = $this->getInput();
        $email = isset($data['email']) && is_string($data['email']) ? trim(strtolower($data['email'])) : '';

        if ($email === '') {
            return Api_response::fail(400, 'Email is required.');
        }

        $ip = Rate_limit::subject($this->clientIp());
        $account = Rate_limit::subject($email);
        $message = 'Too many reset requests. Try again in %d seconds.';
        $this->enforceRateLimit('reset_ip', $ip, Rate_limit::RESET_IP_MAX, Rate_limit::RESET_WINDOW, $message);
        $this->enforceRateLimit('reset_email', $account, Rate_limit::RESET_EMAIL_MAX, Rate_limit::RESET_WINDOW, $message);
        $this->recordRateHit('reset_ip', $ip);
        $this->recordRateHit('reset_email', $account);

        $admin = $this->Admins_Model->findByEmail($email);
        if ($admin && $admin['status'] === 'approved') {
            $token = $this->Auth_Model->createPasswordResetToken($admin['id']);
            $this->sendMail($email, Account_mail::passwordReset($admin['name'], $this->frontendUrl(), $token));
        }

        return Api_response::ok();
    }

    // POST /Auth_API/resetPassword
    // Body: token, password
    //
    // Single use, and it ends every login session of the account: a reset
    // is exactly when an old, possibly stolen session should not survive.
    public function resetPassword()
    {
        $data = $this->getInput();
        $token = isset($data['token']) && is_string($data['token']) ? $data['token'] : '';
        $password = isset($data['password']) && is_string($data['password']) ? $data['password'] : '';

        if ($token === '' || $password === '') {
            return Api_response::fail(400, 'Token and new password are required.');
        }

        Account_policy::requirePassword($password);

        $adminId = $this->Auth_Model->validatePasswordResetToken($token);
        if (!$adminId) {
            return Api_response::fail(400, 'This reset link is invalid or has expired.');
        }

        $this->Admins_Model->updatePassword($adminId, password_hash($password, PASSWORD_DEFAULT));
        $this->Auth_Model->deletePasswordResetToken($token);
        $this->Auth_Model->deleteAllTokensForAdmin($adminId);

        return Api_response::ok();
    }

    // GET /Auth_API/me
    // Returns the current admin if the Authorization token is valid, or
    // a 401 otherwise, so the React app can answer "am I still logged
    // in, and as who" on page load/refresh.
    public function me()
    {
        if (!$this->signedIn()) {
            return Api_response::fail(401, 'Not signed in.');
        }

        return Api_response::ok(array(
            'user' => $this->clientAdmin($this->getCurrentAdmin()),
        ));
    }

    // POST /Auth_API/logout
    // Body: none needed; the token itself comes from the Authorization
    // header, same as every other authenticated request.
    public function logout()
    {
        $token = Auth_session::tokenFrom($this->input->get_request_header('Authorization'));
        if ($token === null) {
            return Api_response::fail(400, 'No token provided.');
        }
        $this->Auth_Model->deleteToken($token);
        return Api_response::ok();
    }

    // The admin as the client sees it after login and /me: an allow-list
    // of exactly these three fields, so nothing else on the row (above
    // all the password hash) can ever reach a response. The key stays
    // "user" so the client contract is unchanged.
    private function clientAdmin($admin)
    {
        return array(
            'id' => $admin['id'],
            'email' => $admin['email'],
            'name' => $admin['name'],
        );
    }
}
