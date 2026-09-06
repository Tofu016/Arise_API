<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Login, logout, registration, and password reset. Auth_Model's
// underlying checks (verifyCredentials, register, etc.) work against
// our own users table as the current working assumption — if the
// client's separate auth system ends up needing to plug in differently
// later, Auth_Model is the one, contained place that changes, not this
// Controller or anything downstream of it.
class Auth_API extends MY_Controller
{
    // POST /Auth_API/login
    // Body: email, password
    public function login()
    {
        $data = $this->getInput();
        $email = isset($data['email']) ? trim($data['email']) : '';
        $password = isset($data['password']) ? $data['password'] : '';

        if ($email === '' || $password === '') {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Email and password are required.'));
            return;
        }

        $user = $this->Auth_Model->verifyCredentials($email, $password);
        if (!$user) {
            // Deliberately the same error for "no such email" and
            // "wrong password" — distinguishing them lets an attacker
            // enumerate which emails are actually registered.
            http_response_code(401);
            echo json_encode(array('success' => false, 'error' => 'Invalid email or password.'));
            return;
        }

        $token = $this->Auth_Model->createToken($user['id']);

        echo json_encode(array(
            'success' => true,
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
            http_response_code(401);
            echo json_encode(array('success' => false, 'error' => 'Not signed in.'));
            return;
        }

        $user = $this->getCurrentUser();
        echo json_encode(array(
            'success' => true,
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
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'No token provided.'));
            return;
        }
        $token = trim(substr($header, 7));
        $this->Auth_Model->deleteToken($token);
        echo json_encode(array('success' => true));
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
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Email, password, and name are all required.'));
            return;
        }

        // Same rule as the original enforceEmailDomain blocking trigger —
        // substr/strlen rather than str_ends_with(), which is PHP 8.0+
        // only and this runs on PHP 7.4.
        $requiredDomain = '@sdca.edu.ph';
        if (substr($email, -strlen($requiredDomain)) !== $requiredDomain) {
            http_response_code(403);
            echo json_encode(array('success' => false, 'error' => 'Registration is only open to @sdca.edu.ph email addresses.'));
            return;
        }

        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Password must be at least 8 characters.'));
            return;
        }

        if ($this->Auth_Model->emailExists($email)) {
            http_response_code(409);
            echo json_encode(array('success' => false, 'error' => 'An account with this email already exists.'));
            return;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $user = $this->Auth_Model->register($email, $passwordHash, $name);

        $this->Auth_Model->queueEmail(
            $email,
            'Welcome to ARISE Campus Navigator',
            '<p>Hi ' . htmlspecialchars($name) . ',</p><p>Thanks for registering. Your account is currently pending approval — you\'ll receive another email once an administrator approves it.</p>'
        );

        $token = $this->Auth_Model->createToken($user['id']);

        echo json_encode(array(
            'success' => true,
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
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Email is required.'));
            return;
        }

        $user = $this->Auth_Model->findByEmail($email);
        if ($user) {
            $resetToken = $this->Auth_Model->createPasswordResetToken($user['id']);
            // The React app's own reset-password page/route — adjust
            // this URL if that page ends up living somewhere else or
            // under a different path.
            $resetLink = 'http://localhost:5173/reset-password?token=' . urlencode($resetToken);

            $this->Auth_Model->queueEmail(
                $email,
                'Reset your ARISE Campus Navigator password',
                '<p>Hi ' . htmlspecialchars($user['name']) . ',</p>'
                . '<p>Click the link below to reset your password. This link expires in 1 hour and can only be used once.</p>'
                . '<p><a href="' . htmlspecialchars($resetLink) . '">' . htmlspecialchars($resetLink) . '</a></p>'
                . '<p>If you didn\'t request this, you can safely ignore this email.</p>'
            );
        }

        echo json_encode(array('success' => true));
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
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Token and new password are required.'));
            return;
        }

        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Password must be at least 8 characters.'));
            return;
        }

        $userId = $this->Auth_Model->validatePasswordResetToken($token);
        if (!$userId) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'This reset link is invalid or has expired.'));
            return;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $this->Auth_Model->updatePassword($userId, $passwordHash);
        $this->Auth_Model->deletePasswordResetToken($token);
        $this->Auth_Model->deleteAllTokensForUser($userId);

        echo json_encode(array('success' => true));
    }
}
