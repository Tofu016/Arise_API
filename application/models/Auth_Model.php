<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Handles credential verification and the bearer-token lifecycle.
// Deliberately isolated in its own Model, separate from any future
// registration/password-reset logic — if the client's own auth system
// ends up needing to plug in here instead of our own users table check,
// this is the one, contained place that changes.
class Auth_Model extends CI_Model
{
    // 8 hours — reasonable for an admin tool, not a high-security
    // banking session. Easy to tune later without touching anything
    // else about how tokens work.
    const TOKEN_LIFETIME_HOURS = 8;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // Returns the user row (including role) on success, or false if the
    // email doesn't exist or the password doesn't match. password_verify()
    // is the correct counterpart to PHP's own password_hash() — never
    // compare hashes directly with ==, since password_hash() salts each
    // hash differently even for the same password.
    public function verifyCredentials($email, $password)
    {
        $this->db->select('*');
        $this->db->from('users');
        $this->db->where('email', $email);
        $query = $this->db->get();
        $user = $query->row_array();

        if (!$user) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }
        return $user;
    }

    // Generates a real, cryptographically random token via random_bytes()
    // (not uniqid() or similar — those are predictable, not suitable for
    // anything security-sensitive). Only the hash is ever stored; the
    // raw token is returned once, here, for the Controller to send back
    // to the client — it's never persisted server-side in raw form.
    public function createToken($userId)
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_LIFETIME_HOURS . ' hours'));

        $this->db->insert('auth_tokens', array(
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ));

        return $rawToken;
    }

    // Returns the associated user row if the token is genuinely valid
    // (exists AND not expired), or false otherwise. Hashes the given raw
    // token the same way createToken() did, then looks up that hash —
    // the raw token itself is never stored, so this is the only way to
    // check it.
    public function validateToken($rawToken)
    {
        if (empty($rawToken)) {
            return false;
        }
        $tokenHash = hash('sha256', $rawToken);

        $this->db->select('users.*');
        $this->db->from('auth_tokens');
        $this->db->join('users', 'users.id = auth_tokens.user_id');
        $this->db->where('auth_tokens.token_hash', $tokenHash);
        $this->db->where('auth_tokens.expires_at >', date('Y-m-d H:i:s'));
        $query = $this->db->get();

        return $query->row_array();
    }

    // Logout — deletes the specific token's row outright, rather than
    // waiting for it to expire naturally. This is the actual point of
    // using our own token table instead of a signed JWT: a JWT can't be
    // un-issued early without extra infrastructure (a blocklist); this
    // is just a single DELETE.
    public function deleteToken($rawToken)
    {
        $tokenHash = hash('sha256', $rawToken);
        $this->db->where('token_hash', $tokenHash);
        return $this->db->delete('auth_tokens');
    }

    // ---------- Registration ----------

    public function emailExists($email)
    {
        $this->db->select('id');
        $this->db->from('users');
        $this->db->where('email', $email);
        $query = $this->db->get();
        return $query->num_rows() > 0;
    }

    // Used by the forgot-password flow — looking someone up by email
    // alone, with no password involved, is a genuinely different
    // operation from verifyCredentials() above, so it gets its own
    // method rather than overloading that one with an optional
    // password check.
    public function findByEmail($email)
    {
        $this->db->select('*');
        $this->db->from('users');
        $this->db->where('email', $email);
        $query = $this->db->get();
        return $query->row_array();
    }

    // New accounts start as 'pending' — matches the original Firebase
    // behavior exactly: Register.jsx always sent role: "pending", never
    // letting a client self-assign "admin" or even "user" directly.
    // Returns the newly-created user row.
    public function register($email, $passwordHash, $name)
    {
        $now = date('Y-m-d H:i:s');
        $data = array(
            'email' => $email,
            'password_hash' => $passwordHash,
            'name' => $name,
            'role' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        );
        $this->db->insert('users', $data);
        $userId = $this->db->insert_id();

        $this->db->select('*');
        $this->db->from('users');
        $this->db->where('id', $userId);
        return $this->db->get()->row_array();
    }

    // Replaces the "mail" Firestore collection + Trigger Email extension
    // that sendWelcomeEmail/sendApprovalEmail relied on — queued, not
    // sent inline here, for the same reason as the original: a slow or
    // failed SMTP call shouldn't block or fail the actual request that
    // triggered it. A separate, periodic job (PHPMailer, run via cron or
    // a scheduled task) is responsible for actually sending queued rows.
    public function queueEmail($toEmail, $subject, $bodyHtml)
    {
        $this->db->insert('email_queue', array(
            'to_email' => $toEmail,
            'subject' => $subject,
            'body_html' => $bodyHtml,
        ));
    }

    // ---------- Password reset ----------

    // Same shape as createToken() above, but a much shorter lifetime (1
    // hour — a reset link sitting in an inbox for 8 hours the way a
    // login session might is a meaningfully bigger window than makes
    // sense for this), and stored in password_resets specifically, not
    // auth_tokens — these are genuinely different things (one proves
    // "I'm currently logged in", the other proves "I own this email
    // address right now"), so they don't share a table.
    public function createPasswordResetToken($userId)
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->db->insert('password_resets', array(
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ));

        return $rawToken;
    }

    // Returns the associated user_id if the reset token is genuinely
    // valid (exists AND not expired), or false otherwise.
    public function validatePasswordResetToken($rawToken)
    {
        if (empty($rawToken)) {
            return false;
        }
        $tokenHash = hash('sha256', $rawToken);

        $this->db->select('user_id');
        $this->db->from('password_resets');
        $this->db->where('token_hash', $tokenHash);
        $this->db->where('expires_at >', date('Y-m-d H:i:s'));
        $query = $this->db->get();
        $row = $query->row_array();

        return $row ? $row['user_id'] : false;
    }

    // Single-use — deletes the reset token's row once it's actually been
    // used, same reasoning as deleteToken() for logout: a reset link
    // should only ever work once, not remain valid until its natural
    // expiry even after it's already done its job.
    public function deletePasswordResetToken($rawToken)
    {
        $tokenHash = hash('sha256', $rawToken);
        $this->db->where('token_hash', $tokenHash);
        return $this->db->delete('password_resets');
    }

    public function updatePassword($userId, $newPasswordHash)
    {
        $this->db->where('id', $userId);
        return $this->db->update('users', array(
            'password_hash' => $newPasswordHash,
            'updated_at' => date('Y-m-d H:i:s'),
        ));
    }

    // Invalidates every existing login session for this user — called
    // after a successful password reset. If the password was
    // compromised, any session created before the reset (possibly by
    // whoever compromised it) shouldn't survive the reset either.
    public function deleteAllTokensForUser($userId)
    {
        $this->db->where('user_id', $userId);
        return $this->db->delete('auth_tokens');
    }
}
