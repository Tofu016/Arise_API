<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The abuse limits on the public endpoints, and the small pure helpers
// MY_Controller::enforceRateLimit builds on. A "bucket" names one limit
// (e.g. login_ip), a "subject" is who it is counted against (a hashed IP,
// email or visitor id), and each recorded hit is one row in
// rate_limit_hits (see Rate_limit_Model).
//
// Subjects are hashed so the table never holds a raw IP or email. The
// numbers live here, in one place, rather than spread across controllers.
//
// Free of CodeIgniter dependencies, so tests call it directly.
class Rate_limit
{
    // Feedback: one submission per visitor every 30 seconds. The visitor is
    // the client's own id when it sent one (so students behind one campus IP
    // do not block each other), else the IP. The per-IP ceiling is a loose
    // backstop for a script that rotates visitor ids.
    const FEEDBACK_VISITOR_WINDOW = 30;
    const FEEDBACK_IP_MAX = 30;
    const FEEDBACK_IP_WINDOW = 600;

    // Login: failed attempts only, counted per IP and per email, so a
    // correct password still works until a lockout trips, and then waits it
    // out. Both limits are the same size on purpose: a tighter email limit
    // would let a stranger lock a real admin out with a few bad guesses.
    const LOGIN_MAX_FAILURES = 10;
    const LOGIN_WINDOW = 900;

    // Registration and password-reset requests are counted on every call,
    // not just failures: each one can send an email. Registration is per IP;
    // a reset is limited per IP and per email, so nobody can flood one
    // inbox from many addresses or many inboxes from one.
    const REGISTER_IP_MAX = 5;
    const REGISTER_WINDOW = 3600;
    const RESET_IP_MAX = 10;
    const RESET_EMAIL_MAX = 3;
    const RESET_WINDOW = 3600;

    // Analytics track: a real session flushes about four times a minute, so
    // the per-session cap is generous; the per-IP cap has to cover every
    // kiosk and phone behind one campus address.
    const TRACK_SESSION_MAX = 30;
    const TRACK_IP_MAX = 600;
    const TRACK_WINDOW = 60;

    // Hit rows older than this are never read by any limit above.
    const KEEP_SECONDS = 86400;

    // The honeypot field the feedback form carries. Named like something a
    // bot would want to fill, but not a name a browser autofills.
    const HONEYPOT_FIELD = 'hp_contact_url';

    public static function subject($value)
    {
        return hash('sha256', (string) $value);
    }

    public static function windowStart($now, $windowSeconds)
    {
        return date('Y-m-d H:i:s', strtotime($now . ' -' . (int) $windowSeconds . ' seconds'));
    }

    // Seconds until the oldest hit in the window falls out of it, at least
    // one. With no known hit, the whole window.
    public static function retryAfter($oldestHitAt, $now, $windowSeconds)
    {
        if (!$oldestHitAt) {
            return max(1, (int) $windowSeconds);
        }
        return max(1, strtotime($oldestHitAt) + (int) $windowSeconds - strtotime($now));
    }

    // A bot that fills every field fills the honeypot; a person never sees it.
    public static function honeypotTripped(array $data)
    {
        if (!isset($data[self::HONEYPOT_FIELD])) {
            return false;
        }
        $value = $data[self::HONEYPOT_FIELD];
        // A form field is always a string; any other shape is a script.
        return !is_string($value) || trim($value) !== '';
    }

    // Client visitor ids are crypto.randomUUID(); anything else is ignored
    // so a caller cannot pick its own bucket by sending junk.
    public static function visitorId($raw)
    {
        $id = is_string($raw) ? strtolower(trim($raw)) : '';
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1 ? $id : null;
    }
}
