<?php
defined('BASEPATH') or exit('No direct script access allowed');

// How a request's Authorization header identifies a user. The header is
// read in exactly one place, so the guards and logout can't disagree about
// what counts as a bearer token.
//
// Pure, like Api_response: the token lookup is passed in, so nothing here
// touches the database or the framework.
class Auth_session
{
    // The token in an "Authorization: Bearer <token>" header, or null when
    // the header is absent, empty, or not a bearer header. The scheme is
    // matched case-insensitively and the token is trimmed. A header that is
    // only "Bearer " yields "" — a token, just a blank one — which the
    // lookup will refuse.
    public static function tokenFrom($header)
    {
        if (empty($header) || stripos($header, 'Bearer ') !== 0) {
            return null;
        }
        return trim(substr($header, 7));
    }

    // The user row the header identifies, or null for an anonymous request
    // (no bearer token, or one the lookup does not accept). $validateToken
    // is callable($token): the user row for a valid token, anything falsy
    // otherwise.
    public static function userFor($header, $validateToken)
    {
        $token = self::tokenFrom($header);
        if ($token === null) {
            return null;
        }
        return call_user_func($validateToken, $token) ?: null;
    }
}
