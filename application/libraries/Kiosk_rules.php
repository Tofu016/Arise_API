<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Api_response.php';

// What a kiosk record, its pairing code and its token look like (see
// Kiosks_API). A kiosk is a physical device an admin registered and tied to
// a map node; "pairing" is how that device proves, once, that it is the one.
//
// The pairing code is typed on the kiosk's on-screen keyboard, so it is
// digits only; it is short-lived and single-use, and its guessing is
// rate-limited by IP (see Kiosks_API::pair). The token the device gets back
// is long and random, and only its hash is stored, like auth_tokens.
//
// Free of CodeIgniter dependencies, so tests call it directly.
class Kiosk_rules
{
    const CODE_LENGTH = 8;
    const CODE_LIFETIME_MINUTES = 30;
    const MAX_NAME_LENGTH = 120;
    // Failed pairing attempts allowed per IP inside the window below.
    const MAX_FAILURES = 5;
    const FAILURE_WINDOW_MINUTES = 10;

    public static function generateCode()
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }

    public static function generateToken()
    {
        return bin2hex(random_bytes(32));
    }

    // Digits only, so a code typed with a space or dash still matches.
    public static function normalizeCode($raw)
    {
        return is_string($raw) ? preg_replace('/\D/', '', $raw) : '';
    }

    public static function hash($value)
    {
        return hash('sha256', $value);
    }

    public static function codeExpiry($now)
    {
        return date('Y-m-d H:i:s', strtotime($now . ' +' . self::CODE_LIFETIME_MINUTES . ' minutes'));
    }

    // The database fields a create or update may set, cleaned. $creating
    // requires a name; node_id may be empty (a kiosk with no location yet).
    public static function kioskFields(array $data, $creating)
    {
        $fields = array();
        if (array_key_exists('name', $data) || $creating) {
            $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
            if ($name === '') {
                throw new Api_abort(Api_response::fail(400, 'name is required.'));
            }
            if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
                throw new Api_abort(Api_response::fail(400, 'name must be ' . self::MAX_NAME_LENGTH . ' characters or fewer.'));
            }
            $fields['name'] = $name;
        }
        if (array_key_exists('node_id', $data)) {
            $nodeId = is_string($data['node_id']) ? trim($data['node_id']) : '';
            $fields['node_id'] = $nodeId === '' ? null : $nodeId;
        }
        return $fields;
    }
}
