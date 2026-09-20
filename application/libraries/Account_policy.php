<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Api_response.php';

// What an account must satisfy: who may register, and how short a
// password may be. One place for the numbers and the messages, so
// registering and resetting a password can't drift apart. Each check
// returns quietly or throws the Api_abort carrying the refusal.
//
// Deliberately narrow — these are today's rules exactly, gaps included:
// the email is not checked for being well-formed, and length counts bytes
// rather than characters. Changing either is a decision, not a cleanup.
//
// Free of CodeIgniter dependencies, like Api_response.
class Account_policy
{
    const MIN_PASSWORD_LENGTH = 8;

    // Same rule as the original enforceEmailDomain blocking trigger.
    const EMAIL_DOMAIN = '@sdca.edu.ph';

    // 400 when the password is too short. Applied to a new account's
    // password and to a reset.
    public static function requirePassword($password)
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new Api_abort(Api_response::fail(400, 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'));
        }
    }

    // 403 unless the email ends with the school domain. The caller passes
    // the email already lowercased; nothing is normalised here. Uses
    // substr/strlen rather than str_ends_with(), which is PHP 8.0+ only
    // and this runs on PHP 7.4.
    public static function requireEmailDomain($email)
    {
        if (substr($email, -strlen(self::EMAIL_DOMAIN)) !== self::EMAIL_DOMAIN) {
            throw new Api_abort(Api_response::fail(403, 'Registration is only open to ' . self::EMAIL_DOMAIN . ' email addresses.'));
        }
    }
}
