<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Api_response.php';

// The shape checks every action repeats on its request: an id in the URL,
// fields in the body, and which fields an update may touch. Each helper
// either returns quietly or throws an Api_abort carrying the 400 reply,
// so an action reads straight down instead of opening with a block of
// "if (...) return fail". Messages are the ones the frontend has always
// received.
//
// What counts as "missing" differs on purpose, and is preserved exactly:
//   requireId / requireFilled use empty()  — "", "0", null and absent all
//                                            count as missing;
//   requirePresent uses isset()            — only null and absent do, so
//                                            0 and "" are accepted.
//
// Free of CodeIgniter dependencies, like Api_response.
class Api_input
{
    // The id from the URL must be present: "Missing {$what} id."
    public static function requireId($id, $what)
    {
        if (empty($id)) {
            self::refuse("Missing {$what} id.");
        }
    }

    // Every field must be set in $data (isset semantics), checked in the
    // order given: "Missing field: {$field}" names the first one absent.
    public static function requirePresent(array $data, array $fields)
    {
        foreach ($fields as $field) {
            if (!isset($data[$field])) {
                self::refuse("Missing field: {$field}");
            }
        }
    }

    // Every field must be non-empty in $data (empty() semantics), with one
    // shared $message for whichever is not.
    public static function requireFilled(array $data, array $fields, $message)
    {
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                self::refuse($message);
            }
        }
    }

    // The part of $data an update is allowed to change: only the $allowed
    // keys, values untouched. Refuses with "No valid fields to update."
    // when none are present, unless $alsoAcceptable says the request still
    // carries a change of another kind (a separate list, say).
    public static function patch(array $data, array $allowed, $alsoAcceptable = false)
    {
        $patch = array_intersect_key($data, array_flip($allowed));
        if (empty($patch) && !$alsoAcceptable) {
            self::refuse('No valid fields to update.');
        }
        return $patch;
    }

    private static function refuse($message)
    {
        throw new Api_abort(Api_response::fail(400, $message));
    }
}
