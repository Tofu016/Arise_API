<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Account_policy.php';
require_once APPPATH . 'libraries/Account_mail.php';

// Login, logout, registration, and password reset. Auth_Model's
// underlying checks (verifyCredentials, register, etc.) work against
// our own users table as the current working assumption — if the
// client's separate auth system ends up needing to plug in differently
// later, Auth_Model is the one, contained place that changes, not this
// Controller or anything downstream of it.
class Auth_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Email_Model');
    }

    // POST /Auth_API/login
    // Body: email, password
    public function login()
    {
        $data = $this->getInput();
        $email = isset($data['email']) ? trim($data['email']) : '';
        $password = isset($data['password']) ? $data['password'] : '';

        if ($email === '' || $password === '') {
            return Api_response::fail(400, 'Email and password are required.');
        }

        $user = $this->Auth_Model->verifyCredentials($email, $password);
        if (!$user) {
            // Deliberately the same error for "no such email" and
            // "wrong password" — distinguishing them lets an attacker
            // enumerate which emails are actually registered.
            return Api_response::fail(401, 'Invalid email or password.');
        }

        $token = $this->Auth_Model->createToken($user['id']);

        return Api_response::ok(array(
            'token' => $token,
            'user' => array(
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role'],
            ),
        ));
    }

    // GET /Auth_API/me
    // Returns the current user if the Authorization token is valid, or
    // a 401 otherwise — lets the React app answer "am I still logged
    // in, and as who" on page load/refresh, without this the app would
    // have no way to recover a session across a reload short of storing
    // the full user object insecurely alongside the token.
    public function me()
    {
        if (!$this->signedIn()) {
            return Api_response::fail(401, 'Not signed in.');
        }

        $user = $this->getCurrentUser();
        return Api_response::ok(array(
            'user' => array(
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role'],
            ),
        ));
    }

    // POST /Auth_API/logout
    // Body: none needed — the token itself comes from the Authorization
    // header, same as every other authenticated request.
    public function logout()
    {
        $header = $this->input->get_request_header('Authorization');
        if (empty($header) || stripos($header, 'Bearer ') !== 0) {
            return Api_response::fail(400, 'No token provided.');
        }
        $token = trim(substr($header, 7));
        $this->Auth_Model->deleteToken($token);
        return Api_response::ok();
    }

    // POST /Auth_API/register
    // Body: email, password, name
    //
    // Replicates enforceEmailDomain (the domain check) and the effect of
    // sendWelcomeEmail (a queued email), and matches the original
    // Firebase behavior of signing the new account in immediately — new
    // users always start as role: 'pending' regardless of what's sent
    // in the request; the app itself is responsible for showing a
    // waiting-for-approval screen based on that role, same as before.
    public function register()
    {
        $data = $this->getInput();
        $email = isset($data['email']) ? trim(strtolower($data['email'])) : '';
        $password = isset($data['password']) ? $data['password'] : '';
        $name = isset($data['name']) ? trim($data['name']) : '';

        if ($email === '' || $password === '' || $name === '') {
            return Api_response::fail(400, 'Email, password, and name are all required.');
        }

        Account_policy::requireEmailDomain($email);
        Account_policy::requirePassword($password);

        if ($this->Auth_Model->emailExists($email)) {
            return Api_response::fail(409, 'An account with this email already exists.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $user = $this->Auth_Model->register($email, $passwordHash, $name);

        $welcome = Account_mail::welcome($name);
        $this->Email_Model->enqueue($email, $welcome['subject'], $welcome['html']);

        $token = $this->Auth_Model->createToken($user['id']);

        return Api_response::ok(array(
            'token' => $token,
            'user' => array(
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role'],
            ),
        ));
    }

    // POST /Auth_API/forgotPassword
    // Body: email
    //
    // Always responds with success, whether or not that email is
    // actually registered — the alternative (a distinct "no such email"
    // error) lets an attacker enumerate which emails have accounts on
    // this system just by trying a list of addresses. Only a real match
    // actually gets an email queued.
    public function forgotPassword()
    {
        $data = $this->getInput();
        $email = isset($data['email']) ? trim(strtolower($data['email'])) : '';

        if ($email === '') {
            return Api_response::fail(400, 'Email is required.');
        }

        $user = $this->Auth_Model->findByEmail($email);
        if ($user) {
            $resetToken = $this->Auth_Model->createPasswordResetToken($user['id']);
            $reset = Account_mail::passwordReset($user['name'], 'http://localhost:5173', $resetToken);
            $this->Email_Model->enqueue($email, $reset['subject'], $reset['html']);
        }

        return Api_response::ok();
    }

    // POST /Auth_API/resetPassword
    // Body: token, password
    //
    // Single-use — the token is deleted the moment it's successfully
    // used, and every existing login session for that account is
    // invalidated too, since a password reset is exactly the situation
    // where an old, possibly-compromised session shouldn't be allowed
    // to survive.
    public function resetPassword()
    {
        $data = $this->getInput();
        $token = isset($data['token']) ? $data['token'] : '';
        $password = isset($data['password']) ? $data['password'] : '';

        if ($token === '' || $password === '') {
            return Api_response::fail(400, 'Token and new password are required.');
        }

        Account_policy::requirePassword($password);

        $userId = $this->Auth_Model->validatePasswordResetToken($token);
        if (!$userId) {
            return Api_response::fail(400, 'This reset link is invalid or has expired.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $this->Auth_Model->updatePassword($userId, $passwordHash);
        $this->Auth_Model->deletePasswordResetToken($token);
        $this->Auth_Model->deleteAllTokensForUser($userId);

        return Api_response::ok();
    }
}
