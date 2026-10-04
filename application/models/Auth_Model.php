<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Handles credential verification, the bearer-token lifecycle and password
// reset tokens for admin accounts (the only accounts that exist). Account management
// (listing, creating, deleting) lives in Admins_Model.
class Auth_Model extends CI_Model
{
    // 8 hours: reasonable for an admin tool, not a high-security
    // banking session. Easy to tune later without touching anything
    // else about how tokens work.
    const TOKEN_LIFETIME_HOURS = 8;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // Returns the admin row on success, or false if the email doesn't
    // exist or the password doesn't match. password_verify() is the
    // correct counterpart to PHP's own password_hash(); never compare
    // hashes directly with ==, since password_hash() salts each hash
    // differently even for the same password.
    public function verifyCredentials($email, $password)
    {
        $this->db->select('*');
        $this->db->from('admins');
        $this->db->where('email', $email);
        $admin = $this->db->get()->row_array();

        if (!$admin) {
            return false;
        }
        if (!password_verify($password, $admin['password_hash'])) {
            return false;
        }
        return $admin;
    }

    // Generates a real, cryptographically random token via random_bytes()
    // (not uniqid() or similar, which are predictable). Only the hash is
    // ever stored; the raw token is returned once, here, for the
    // Controller to send back to the client.
    public function createToken($adminId)
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_LIFETIME_HOURS . ' hours'));

        $this->db->insert('auth_tokens', array(
            'admin_id' => $adminId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ));

        return $rawToken;
    }

    // Returns the associated admin row if the token is genuinely valid
    // (exists AND not expired), or false otherwise. Hashes the given raw
    // token the same way createToken() did, then looks up that hash.
    public function validateToken($rawToken)
    {
        if (empty($rawToken)) {
            return false;
        }
        $tokenHash = hash('sha256', $rawToken);

        $this->db->select('admins.*');
        $this->db->from('auth_tokens');
        $this->db->join('admins', 'admins.id = auth_tokens.admin_id');
        $this->db->where('auth_tokens.token_hash', $tokenHash);
        $this->db->where('admins.status', 'approved');
        $this->db->where('auth_tokens.expires_at >', date('Y-m-d H:i:s'));

        return $this->db->get()->row_array();
    }

    // Logout: deletes the specific token's row outright, rather than
    // waiting for it to expire naturally.
    public function deleteToken($rawToken)
    {
        $tokenHash = hash('sha256', $rawToken);
        $this->db->where('token_hash', $tokenHash);
        return $this->db->delete('auth_tokens');
    }

    // Ends every login session of one admin, e.g. after their password is
    // reset by someone else: a session opened with the old password
    // shouldn't survive the change.
    public function deleteAllTokensForAdmin($adminId)
    {
        $this->db->where('admin_id', $adminId);
        return $this->db->delete('auth_tokens');
    }

    // 1 hour, single use. Only the hash is stored, like a login token: the
    // raw value exists once, in the email.
    const RESET_LIFETIME_MINUTES = 60;

    // Replaces any earlier reset link of that admin, so only the newest
    // one works. Returns the raw token for the email.
    public function createPasswordResetToken($adminId)
    {
        $this->db->where('admin_id', $adminId);
        $this->db->delete('password_resets');

        $rawToken = bin2hex(random_bytes(32));
        $this->db->insert('password_resets', array(
            'admin_id' => $adminId,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::RESET_LIFETIME_MINUTES . ' minutes')),
        ));
        return $rawToken;
    }

    // The admin id the token belongs to, or false when it is unknown or
    // expired.
    public function validatePasswordResetToken($rawToken)
    {
        if (empty($rawToken)) {
            return false;
        }
        $this->db->select('admin_id');
        $this->db->from('password_resets');
        $this->db->where('token_hash', hash('sha256', $rawToken));
        $this->db->where('expires_at >', date('Y-m-d H:i:s'));
        $row = $this->db->get()->row_array();
        return $row ? $row['admin_id'] : false;
    }

    public function deletePasswordResetToken($rawToken)
    {
        $this->db->where('token_hash', hash('sha256', $rawToken));
        return $this->db->delete('password_resets');
    }

    // Deletes expired login tokens. validateToken() already refuses them,
    // so this changes no behaviour; it only stops the table growing
    // forever. Returns how many rows went.
    public function purgeExpiredTokens()
    {
        $this->db->where('expires_at <', date('Y-m-d H:i:s'));
        $this->db->delete('auth_tokens');
        $deleted = $this->db->affected_rows();

        $this->db->where('expires_at <', date('Y-m-d H:i:s'));
        $this->db->delete('password_resets');
        return $deleted;
    }
}
