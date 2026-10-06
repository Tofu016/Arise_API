<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Same conventions throughout — getAll() public (room and facility details
// are shown to any visitor tapping a room or facility in the navigator, not
// just admins), writes behind requireAdmin(). A dialog belongs to a room (a
// node's "Rooms served" entry) or a facility (a facility marker's label);
// both are keyed by name in room_name, so the name is unique across the
// two. The field keeps its room_name name because the mobile app reads it.
// room_name's uniqueness is
// checked explicitly before insert/update, same reasoning as the
// building-existence and marker-type checks built for nodes.
class PlacardDialogs_API extends MY_Controller
{
    // saveOcr's limit. A whole campus's rooms fit well within it; it only
    // stops a runaway request from holding one transaction open.
    const MAX_OCR_ROOMS = 2000;
    // ocr_settings.scanner_message's column width.
    const MAX_SCANNER_MESSAGE = 300;
    // How many AR 360 images one room may have; a visitor pages through them.
    const MAX_OCR_PHOTOS = 20;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('PlacardDialogs_Model');
    }

    // GET /PlacardDialogs_API/getAll — public.
    public function getAll()
    {
        $dialogs = $this->PlacardDialogs_Model->getAll();
        return Api_response::ok(array('dialogs' => $dialogs));
    }

    // GET /PlacardDialogs_API/getOcrSettings: public. The page-wide OCR
    // settings the mobile app reads: scanner_message, shown at the top of
    // the placard scanner (null for none).
    public function getOcrSettings()
    {
        return Api_response::ok(array('settings' => $this->PlacardDialogs_Model->getOcrSettings()));
    }

    // POST /PlacardDialogs_API/create — admin only.
    // Body: room_name (required); description, department, use,
    // link, search_terms, photos (all optional; photos is the ordered array of
    // {path, kind ('flat' or '360'), thumb_x, thumb_y}; thumb_x/thumb_y are a
    // flat photo's square thumbnail focus; view_yaw/view_pitch and
    // thumb_yaw/thumb_pitch, degrees, are a 360 photo's first view and the
    // centre of its flattened thumbnail, thumb_fov its width, 60 to 110; cell_yaw/cell_pitch/cell_fov the same for the directory cell, cell_fov 10 to 170); ocr_enabled, placard_name and
    // extra_search_terms (optional, see saveOcr)
    public function create()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $roomName = isset($data['room_name']) ? trim($data['room_name']) : '';

        if ($roomName === '') {
            return Api_response::fail(400, 'room_name is required.');
        }

        if ($this->PlacardDialogs_Model->roomNameExists($roomName)) {
            return Api_response::fail(409, 'A room or facility with this name already exists.');
        }

        $allowed = array('description', 'department', 'contact_number', 'link');
        $fields = array_merge(array_intersect_key($data, array_flip($allowed)), self::ocrFields($data));
        $searchTerms = self::termList($data, 'search_terms') ?: array();
        $extraTerms = self::termList($data, 'extra_search_terms') ?: array();

        $photos = (isset($data['photos']) && is_array($data['photos'])) ? $data['photos'] : array();

        $dialog = $this->PlacardDialogs_Model->create($roomName, $fields, $searchTerms, $photos, $extraTerms);
        return Api_response::ok(array('dialog' => $dialog));
    }

    // PATCH /PlacardDialogs_API/update/{id} — admin only.
    public function update($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'dialog');

        $data = $this->getInput();

        if (isset($data['room_name'])) {
            $newName = trim($data['room_name']);
            if ($newName === '') {
                return Api_response::fail(400, 'room_name cannot be blank.');
            }
            if ($this->PlacardDialogs_Model->roomNameExists($newName, $id)) {
                return Api_response::fail(409, 'A room or facility with this name already exists.');
            }
        }

        $allowed = array('room_name', 'description', 'department', 'contact_number', 'link');
        $searchTerms = self::termList($data, 'search_terms');
        $extraTerms = self::termList($data, 'extra_search_terms');
        $photos = (isset($data['photos']) && is_array($data['photos'])) ? $data['photos'] : null;
        $ocr = self::ocrFields($data);
        // A body carrying only search terms, photos or OCR fields is still a change.
        $patch = Api_input::patch($data, $allowed, $searchTerms !== null || $extraTerms !== null || $photos !== null || !empty($ocr));

        $dialog = $this->PlacardDialogs_Model->update($id, array_merge($patch, $ocr), $searchTerms, $photos, $extraTerms);
        return Api_response::ok(array('dialog' => $dialog));
    }

    // POST /PlacardDialogs_API/saveOcr — admin only. The OCR Management
    // page's save, all in one transaction.
    // Body: rooms, a list of { room_name (required), ocr_enabled,
    // placard_name, search_terms, extra_search_terms, ocr_photos }, and
    // scanner_message; either may be left out, not both. A room with no record
    // yet gets one. search_terms are the ones generated from the Placard
    // name and extra_search_terms the ones an admin typed; each replaces its
    // own set, and a missing one counts as empty. ocr_photos is the ordered
    // list of the room's own 360 image paths for the AR portal after a scan;
    // it replaces the room's list, and a missing one is left as it is (older
    // admin builds don't send it). A blank placard_name or scanner_message is
    // stored as none.
    public function saveOcr()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $rooms = isset($data['rooms']) && is_array($data['rooms']) ? $data['rooms'] : array();
        $settings = null;
        if (array_key_exists('scanner_message', $data)) {
            $message = is_string($data['scanner_message']) ? trim($data['scanner_message']) : '';
            if (mb_strlen($message) > self::MAX_SCANNER_MESSAGE) {
                return Api_response::fail(400, 'scanner_message is too long.');
            }
            $settings = array('scanner_message' => $message === '' ? null : $message);
        }
        if (empty($rooms) && $settings === null) {
            return Api_response::fail(400, 'rooms is required.');
        }
        if (count($rooms) > self::MAX_OCR_ROOMS) {
            return Api_response::fail(400, 'Too many rooms in one save.');
        }

        $rows = array();
        foreach ($rooms as $room) {
            $roomName = is_array($room) && isset($room['room_name']) && is_string($room['room_name']) ? trim($room['room_name']) : '';
            if ($roomName === '') {
                return Api_response::fail(400, 'Each room needs a room_name.');
            }
            $ocr = self::ocrFields(array_merge(array('ocr_enabled' => 0, 'placard_name' => null), $room));
            $row = array(
                'room_name' => $roomName,
                'ocr_enabled' => $ocr['ocr_enabled'],
                'placard_name' => $ocr['placard_name'],
                'search_terms' => self::termList($room, 'search_terms') ?: array(),
                'extra_search_terms' => self::termList($room, 'extra_search_terms') ?: array(),
            );
            if (array_key_exists('ocr_photos', $room)) {
                $row['ocr_photos'] = self::ocrPhotoList($room['ocr_photos']);
            }
            $rows[] = $row;
        }

        if (!$this->PlacardDialogs_Model->saveOcr($rows, $settings)) {
            return Api_response::fail(500, "Couldn't save the OCR settings.");
        }
        return Api_response::ok(array('dialogs' => $this->PlacardDialogs_Model->getAll()));
    }

    // The OCR fields present in $data, ready to store: ocr_enabled as 0/1,
    // placard_name trimmed and null when blank. A Placard name longer than
    // the column refuses the request.
    private static function ocrFields(array $data)
    {
        $out = array();
        if (array_key_exists('ocr_enabled', $data)) {
            $out['ocr_enabled'] = !empty($data['ocr_enabled']) && $data['ocr_enabled'] !== 'false' ? 1 : 0;
        }
        if (array_key_exists('placard_name', $data)) {
            $name = is_string($data['placard_name']) ? trim($data['placard_name']) : '';
            if (mb_strlen($name) > 255) {
                throw new Api_abort(Api_response::fail(400, 'placard_name is too long.'));
            }
            $out['placard_name'] = $name === '' ? null : $name;
        }
        return $out;
    }

    // A room's AR 360 image paths, ready to store: trimmed, blanks and
    // repeats dropped, in the order sent. Anything but a list of strings, too
    // many images or an over-long path refuses the request.
    private static function ocrPhotoList($photos)
    {
        if (!is_array($photos)) {
            throw new Api_abort(Api_response::fail(400, 'ocr_photos must be a list of photo paths.'));
        }
        $paths = array();
        foreach ($photos as $path) {
            if (!is_string($path)) {
                throw new Api_abort(Api_response::fail(400, 'ocr_photos must be a list of photo paths.'));
            }
            $path = trim($path);
            if (mb_strlen($path) > 500) {
                throw new Api_abort(Api_response::fail(400, 'An ocr_photos path is too long.'));
            }
            if ($path !== '' && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }
        if (count($paths) > self::MAX_OCR_PHOTOS) {
            throw new Api_abort(Api_response::fail(400, 'A room can have at most ' . self::MAX_OCR_PHOTOS . ' AR 360 images.'));
        }
        return $paths;
    }

    // A search term list from $data[$key], or null when it isn't a list.
    private static function termList(array $data, $key)
    {
        return isset($data[$key]) && is_array($data[$key]) ? array_values($data[$key]) : null;
    }

    // DELETE /PlacardDialogs_API/delete/{id} — admin only.
    public function delete($id = null)
    {
        $this->requireAdmin();

        Api_input::requireId($id, 'dialog');

        $this->PlacardDialogs_Model->delete($id);
        return Api_response::ok();
    }
}
