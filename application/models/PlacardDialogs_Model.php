<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Simpler than nodes — no graph structure, just one child
// table (search terms, feeding the mobile app's AR placard-scanner).
// room_name is UNIQUE in the schema — checked explicitly before insert/
// update rather than letting a duplicate surface as a raw SQL error,
// same reasoning as the building-existence and marker-type checks
// built for nodes. Uses MySQL's own AUTO_INCREMENT for ids, unlike
// every other resource so far — matches the confirmed, updated schema
// (auto-generated ids, not room-name-keyed documents).
class PlacardDialogs_Model extends CI_Model
{
    private $table = 'placard_dialogs';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function getAll()
    {
        $rows = $this->_getDialogRows();
        $termsByDialog = $this->_getSearchTermsGrouped();
        $photosByDialog = $this->_getExtraPhotosGrouped();

        foreach ($rows as &$row) {
            $id = $row['id'];
            $row['search_terms'] = isset($termsByDialog[$id]) ? $termsByDialog[$id] : array();
            $row['extra_photos'] = isset($photosByDialog[$id]) ? $photosByDialog[$id] : array();
        }
        unset($row);

        return $rows;
    }

    public function find($id)
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->where('id', $id);
        $row = $this->db->get()->row_array();
        if (!$row) {
            return null;
        }

        $termsByDialog = $this->_getSearchTermsGrouped($id);
        $row['search_terms'] = isset($termsByDialog[$id]) ? $termsByDialog[$id] : array();
        $photosByDialog = $this->_getExtraPhotosGrouped($id);
        $row['extra_photos'] = isset($photosByDialog[$id]) ? $photosByDialog[$id] : array();
        return $row;
    }

    private function _getDialogRows()
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->order_by('room_name', 'ASC');
        return $this->db->get()->result_array();
    }

    private function _getSearchTermsGrouped($onlyDialogId = null)
    {
        $this->db->select('*');
        $this->db->from('placard_search_terms');
        if ($onlyDialogId !== null) {
            $this->db->where('placard_dialog_id', $onlyDialogId);
        }
        $rows = $this->db->get()->result_array();

        $grouped = array();
        foreach ($rows as $row) {
            $grouped[$row['placard_dialog_id']][] = array(
                'id' => $row['id'],
                'term' => $row['term'],
            );
        }
        return $grouped;
    }

    // Photos in display order, each { path, thumb_x, thumb_y }: the square
    // thumbnail's focus as object-position percentages (50/50 = centered). These are the photos after the main
    // one (photo_path), which stays a plain column so the mobile app keeps
    // reading a single photo.
    private function _getExtraPhotosGrouped($onlyDialogId = null)
    {
        $this->db->select('placard_dialog_id, photo_path, thumb_x, thumb_y');
        $this->db->from('placard_photos');
        if ($onlyDialogId !== null) {
            $this->db->where('placard_dialog_id', $onlyDialogId);
        }
        $this->db->order_by('sort_order', 'ASC');
        $this->db->order_by('id', 'ASC');

        $grouped = array();
        foreach ($this->db->get()->result_array() as $row) {
            $grouped[$row['placard_dialog_id']][] = array(
                'path' => $row['photo_path'],
                'thumb_x' => (int) $row['thumb_x'],
                'thumb_y' => (int) $row['thumb_y'],
            );
        }
        return $grouped;
    }

    // $excludeId lets update() check "is this name taken by a DIFFERENT
    // row" without flagging the row's own current name as a conflict
    // with itself.
    public function roomNameExists($roomName, $excludeId = null)
    {
        $this->db->select('id');
        $this->db->from($this->table);
        $this->db->where('room_name', $roomName);
        if ($excludeId !== null) {
            $this->db->where('id !=', $excludeId);
        }
        return $this->db->get()->num_rows() > 0;
    }

    public function create($roomName, $data, $searchTerms = array(), $extraPhotos = array())
    {
        $now = date('Y-m-d H:i:s');
        $data['room_name'] = trim($roomName);
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $this->db->insert($this->table, $data);
        $id = $this->db->insert_id();

        $this->_insertSearchTerms($id, $searchTerms);
        $this->_replaceExtraPhotos($id, $extraPhotos);

        return $this->find($id);
    }

    // $searchTerms: null leaves them untouched, an array (including
    // empty) replaces the full set.
    public function update($id, $data, $searchTerms = null, $extraPhotos = null)
    {
        if (!empty($data)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->where('id', $id);
            $this->db->update($this->table, $data);
        }

        if ($searchTerms !== null) {
            $this->db->where('placard_dialog_id', $id);
            $this->db->delete('placard_search_terms');
            $this->_insertSearchTerms($id, $searchTerms);
        }

        // Same null-vs-array convention as $searchTerms.
        if ($extraPhotos !== null) {
            $this->_replaceExtraPhotos($id, $extraPhotos);
        }

        return $this->find($id);
    }

    public function delete($id)
    {
        // placard_search_terms and placard_photos cascade via its own foreign key.
        $this->db->where('id', $id);
        return $this->db->delete($this->table);
    }

    private function _replaceExtraPhotos($dialogId, $paths)
    {
        $this->db->where('placard_dialog_id', $dialogId);
        $this->db->delete('placard_photos');

        $order = 0;
        foreach ($paths as $item) {
            // A bare path string is accepted as a photo with a centered thumbnail.
            $path = is_array($item) ? (isset($item['path']) ? $item['path'] : '') : $item;
            if (!is_string($path) || trim($path) === '') {
                continue;
            }
            $this->db->insert('placard_photos', array(
                'placard_dialog_id' => $dialogId,
                'photo_path' => trim($path),
                'thumb_x' => is_array($item) ? self::clampPercent(isset($item['thumb_x']) ? $item['thumb_x'] : 50) : 50,
                'thumb_y' => is_array($item) ? self::clampPercent(isset($item['thumb_y']) ? $item['thumb_y'] : 50) : 50,
                'sort_order' => $order++,
            ));
        }
    }

    public static function clampPercent($value)
    {
        return max(0, min(100, (int) round((float) $value)));
    }

    private function _insertSearchTerms($dialogId, $terms)
    {
        foreach ($terms as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }
            $this->db->insert('placard_search_terms', array(
                'placard_dialog_id' => $dialogId,
                'term' => $term,
            ));
        }
    }
}
