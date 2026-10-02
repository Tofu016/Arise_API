<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Api_response.php';
require_once __DIR__ . '/Photo_store.php';

// What a valid signage slide and the signage settings look like (see
// Signage_API). Signage is the media shown in the kiosk's bottom band:
// each slide is one image, GIF or looping video, cropped to the band's
// shape, shown for its own duration in a rotation, optionally only
// between two dates.
//
// "Signage", never "ad"/"advert", in every identifier, path and table:
// ad blockers hide or refuse anything whose URL or class name looks like
// an advertisement, which would silently blank the admin's own previews.
//
// Each check either returns the cleaned database fields or throws an
// Api_abort carrying the 400 reply, like Api_input. Free of CodeIgniter
// dependencies, so tests call it directly.
class Signage_rules
{
    const MIN_DURATION = 3;
    const MAX_DURATION = 600;
    const ROTATION_ORDERS = array('sequence', 'shuffle');
    const TRANSITIONS = array('fade', 'cut');

    // The crop is a rectangle in fractions of the media's own width and
    // height (0..1), so it survives the file being re-encoded at another
    // resolution. A crop smaller than this is almost certainly a slip of
    // the handle, not a real choice.
    const MIN_CROP = 0.01;
    // Float slack for a crop dragged flush to the right/bottom edge, where
    // x + w can land a hair over 1 after rounding in the browser.
    const EDGE_SLACK = 0.000001;

    const CROP_FIELDS = array('crop_x', 'crop_y', 'crop_w', 'crop_h');

    // The database fields a create or update may set, cleaned. $creating
    // requires title and media_path; an update takes any subset. starts_at/
    // ends_at are only shape-checked here, since an update may change one
    // of them alone; their order is checked by requireWindowOrder once the
    // caller knows both.
    public static function slideFields(array $data, $creating)
    {
        $fields = array();

        if ($creating || array_key_exists('title', $data)) {
            $title = isset($data['title']) && is_string($data['title']) ? trim($data['title']) : '';
            if ($title === '') {
                self::refuse('title is required.');
            }
            if (mb_strlen($title) > 255) {
                self::refuse('title must be at most 255 characters.');
            }
            $fields['title'] = $title;
        }

        if ($creating || array_key_exists('media_path', $data)) {
            $path = isset($data['media_path']) ? $data['media_path'] : '';
            if (!Photo_store::isPhotoPath($path, 'signage')) {
                self::refuse('media_path must be an uploaded signage file (signage/...); upload the file first.');
            }
            $fields['media_path'] = $path;
        }

        $fields += self::cropFields($data);

        if (array_key_exists('duration_seconds', $data)) {
            $fields['duration_seconds'] = self::duration($data['duration_seconds'], 'duration_seconds');
        }

        if (array_key_exists('is_active', $data)) {
            $fields['is_active'] = self::flag($data['is_active'], 'is_active');
        }

        foreach (array('starts_at', 'ends_at') as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = self::dateTime($data[$key], $key);
            }
        }

        return $fields;
    }

    // A run window must end after it starts. Either end may be open (null).
    public static function requireWindowOrder($startsAt, $endsAt)
    {
        if ($startsAt !== null && $endsAt !== null && strcmp($endsAt, $startsAt) <= 0) {
            self::refuse('ends_at must be later than starts_at.');
        }
    }

    // Whether a slide row is on the kiosk at $now ("Y-m-d H:i:s", server
    // local time, the same clock the dates were entered against): switched
    // on, and inside its run window. starts_at is inclusive, ends_at
    // exclusive, so back-to-back campaigns never overlap or leave a gap.
    public static function isLive(array $row, $now)
    {
        if (empty($row['is_active'])) {
            return false;
        }
        if (!empty($row['starts_at']) && strcmp($row['starts_at'], $now) > 0) {
            return false;
        }
        if (!empty($row['ends_at']) && strcmp($row['ends_at'], $now) <= 0) {
            return false;
        }
        return true;
    }

    // The new rotation order: $ids must name every existing slide exactly
    // once. Returns the ids as strings, in the requested order.
    public static function reorderIds($ids, array $existingIds)
    {
        if (!is_array($ids)) {
            self::refuse('ids must be a list of every slide id, in the new order.');
        }
        $ids = array_map('strval', array_values($ids));
        $existing = array_map('strval', $existingIds);
        $sortedIds = $ids;
        sort($sortedIds);
        sort($existing);
        if ($sortedIds !== $existing) {
            self::refuse('ids must list every slide exactly once. Reload the page and try again.');
        }
        return $ids;
    }

    // The settings fields an update may set, cleaned; at least one.
    public static function settingsFields(array $data)
    {
        $fields = array();

        if (array_key_exists('rotation_order', $data)) {
            if (!in_array($data['rotation_order'], self::ROTATION_ORDERS, true)) {
                self::refuse('rotation_order must be one of: ' . implode(', ', self::ROTATION_ORDERS) . '.');
            }
            $fields['rotation_order'] = $data['rotation_order'];
        }

        if (array_key_exists('transition', $data)) {
            if (!in_array($data['transition'], self::TRANSITIONS, true)) {
                self::refuse('transition must be one of: ' . implode(', ', self::TRANSITIONS) . '.');
            }
            $fields['transition'] = $data['transition'];
        }

        if (array_key_exists('default_duration_seconds', $data)) {
            $fields['default_duration_seconds'] = self::duration($data['default_duration_seconds'], 'default_duration_seconds');
        }

        if (empty($fields)) {
            self::refuse('No valid fields to update.');
        }
        return $fields;
    }

    // All four crop values or none: a partial crop has no sensible meaning
    // (moving x alone would push the rectangle off the media).
    private static function cropFields(array $data)
    {
        $given = array_intersect_key($data, array_flip(self::CROP_FIELDS));
        if (empty($given)) {
            return array();
        }
        if (count($given) !== count(self::CROP_FIELDS)) {
            self::refuse('crop_x, crop_y, crop_w and crop_h must be sent together.');
        }

        $crop = array();
        foreach (self::CROP_FIELDS as $key) {
            $value = $data[$key];
            if (!is_numeric($value) || !is_finite((float) $value)) {
                self::refuse("{$key} must be a number between 0 and 1.");
            }
            $crop[$key] = (float) $value;
        }

        if ($crop['crop_x'] < 0 || $crop['crop_y'] < 0) {
            self::refuse('The crop must start inside the media.');
        }
        if ($crop['crop_w'] < self::MIN_CROP || $crop['crop_h'] < self::MIN_CROP) {
            self::refuse('The crop is too small.');
        }
        if ($crop['crop_x'] + $crop['crop_w'] > 1 + self::EDGE_SLACK || $crop['crop_y'] + $crop['crop_h'] > 1 + self::EDGE_SLACK) {
            self::refuse('The crop must stay inside the media.');
        }

        // Absorb the edge slack so the stored rectangle never overhangs.
        $crop['crop_w'] = min($crop['crop_w'], 1 - $crop['crop_x']);
        $crop['crop_h'] = min($crop['crop_h'], 1 - $crop['crop_y']);
        foreach ($crop as $key => $value) {
            $crop[$key] = round($value, 6);
        }
        return $crop;
    }

    // Seconds in tenths (7.5, not 7.25), matching the decimal(4,1) columns.
    // More precision is refused rather than rounded away, so what the
    // admin typed is exactly what's stored. Checked on the value times ten
    // with a float tolerance, since 7.3 * 10 is 72.99999... in binary.
    private static function duration($value, $field)
    {
        if (is_bool($value) || !is_numeric($value) || !is_finite((float) $value)) {
            self::refuse("{$field} must be a number of seconds.");
        }
        $tenths = (float) $value * 10;
        if (abs($tenths - round($tenths)) > 1e-6) {
            self::refuse("{$field} can have at most one decimal place (like 7.5).");
        }
        $seconds = round($tenths) / 10;
        if ($seconds < self::MIN_DURATION || $seconds > self::MAX_DURATION) {
            self::refuse("{$field} must be between " . self::MIN_DURATION . ' and ' . self::MAX_DURATION . ' seconds.');
        }
        return $seconds;
    }

    private static function flag($value, $field)
    {
        if ($value === true || $value === 1 || $value === '1') {
            return 1;
        }
        if ($value === false || $value === 0 || $value === '0') {
            return 0;
        }
        self::refuse("{$field} must be true or false.");
    }

    // null or "" clears the date. Accepts "Y-m-d H:i:s", "Y-m-d H:i", and
    // the "T"-separated form a datetime-local input produces; stores
    // "Y-m-d H:i:s". Rejects impossible dates (Feb 30) rather than letting
    // MySQL quietly store zeroes.
    private static function dateTime($value, $field)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            self::refuse("{$field} must be a date and time (YYYY-MM-DD HH:MM).");
        }
        $seconds = isset($m[6]) ? (int) $m[6] : 0;
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || $seconds > 59) {
            self::refuse("{$field} is not a real date and time.");
        }
        return sprintf('%s-%s-%s %s:%s:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], $seconds);
    }

    private static function refuse($message)
    {
        throw new Api_abort(Api_response::fail(400, $message));
    }
}
