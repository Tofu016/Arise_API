<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The web app sidebar Directory's visibility settings (see Directory_Model).
// getPublic is open, since every visitor's sidebar reads it; saving is
// admin-only.
class Directory_API extends MY_Controller
{
    const MAX_ITEMS = 5000;
    const MAX_LENGTH = 255;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Directory_Model');
    }

    // GET /Directory_API/getPublic: public.
    public function getPublic()
    {
        return Api_response::ok(array('settings' => $this->Directory_Model->getConfig()));
    }

    // PATCH /Directory_API/settings: admin only.
    // Body: any of show_saved (bool), hidden_campuses, hidden_buildings
    // (lists of ids), and building_rooms: a map of building id to
    // { incoming (bool), listed (room names), removed (room names) }. A
    // building lists its `listed` rooms; with `incoming` on it also lists
    // every room not in `removed`, so rooms created later appear. A building
    // with no entry behaves as incoming on with nothing removed.
    public function settings()
    {
        $this->requireAdmin();

        $data = $this->getInput();
        $fields = array();
        if (array_key_exists('show_saved', $data)) {
            $fields['show_saved'] = (bool) $data['show_saved'];
        }
        foreach (array('hidden_campuses', 'hidden_buildings') as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $list = $data[$key];
            if (!is_array($list) || count($list) > self::MAX_ITEMS) {
                return Api_response::fail(400, "$key must be a list of at most " . self::MAX_ITEMS . ' names.');
            }
            foreach ($list as $item) {
                if (!is_string($item) || $item === '' || strlen($item) > self::MAX_LENGTH) {
                    return Api_response::fail(400, "$key may only hold non-empty names.");
                }
            }
            $fields[$key] = array_values(array_unique($list));
        }
        if (array_key_exists('building_rooms', $data)) {
            $clean = $this->cleanBuildingRooms($data['building_rooms']);
            if ($clean === null) {
                return Api_response::fail(400, 'building_rooms must map building ids to { incoming, listed, removed }.');
            }
            $fields['building_rooms'] = $clean;
        }
        if (empty($fields)) {
            return Api_response::fail(400, 'No valid fields to update.');
        }

        return Api_response::ok(array('settings' => $this->Directory_Model->updateConfig($fields)));
    }

    // Returns the cleaned map, or null when the shape is wrong.
    private function cleanBuildingRooms($map)
    {
        if (!is_array($map) || count($map) > self::MAX_ITEMS) {
            return null;
        }
        $clean = array();
        foreach ($map as $buildingId => $entry) {
            if (!is_string($buildingId) || $buildingId === '' || strlen($buildingId) > self::MAX_LENGTH
                || !is_array($entry) || !isset($entry['incoming']) || !is_bool($entry['incoming'])) {
                return null;
            }
            $cleanEntry = array('incoming' => $entry['incoming']);
            foreach (array('listed', 'removed') as $key) {
                $names = isset($entry[$key]) ? $entry[$key] : array();
                if (!is_array($names) || count($names) > self::MAX_ITEMS) {
                    return null;
                }
                foreach ($names as $name) {
                    if (!is_string($name) || $name === '' || strlen($name) > self::MAX_LENGTH) {
                        return null;
                    }
                }
                $cleanEntry[$key] = array_values(array_unique($names));
            }
            $clean[$buildingId] = $cleanEntry;
        }
        return $clean;
    }
}
