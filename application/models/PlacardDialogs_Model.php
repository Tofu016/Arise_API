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
        $photosByDialog = $this->_getPhotosGrouped();

        foreach ($rows as &$row) {
            $id = $row['id'];
            $row['search_terms'] = isset($termsByDialog[$id]) ? $termsByDialog[$id] : array();
            $this->_attachPhotos($row, isset($photosByDialog[$id]) ? $photosByDialog[$id] : array());
            $this->_castOcr($row);
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
        $photosByDialog = $this->_getPhotosGrouped($id);
        $this->_attachPhotos($row, isset($photosByDialog[$id]) ? $photosByDialog[$id] : array());
        $this->_castOcr($row);
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

        // is_extra: typed in by an admin on the OCR Management page, as
        // opposed to generated from the Placard name.
        $grouped = array();
        foreach ($rows as $row) {
            $grouped[$row['placard_dialog_id']][] = array(
                'id' => $row['id'],
                'term' => $row['term'],
                'is_extra' => (int) $row['is_extra'],
            );
        }
        return $grouped;
    }

    // The OCR columns as the clients read them: a 0/1 flag, and null for a
    // Placard name never set.
    private function _castOcr(&$row)
    {
        $row['ocr_enabled'] = (int) $row['ocr_enabled'];
        if ($row['placard_name'] === '') {
            $row['placard_name'] = null;
        }
    }

    // `photos` is the room's whole ordered list. photo_path and photo_360_path
    // are derived (first flat, first 360) because the mobile app reads them.
    private function _attachPhotos(&$row, $photos)
    {
        $row['photos'] = $photos;
        $row['photo_path'] = '';
        $row['photo_360_path'] = '';
        foreach ($photos as $photo) {
            if ($photo['kind'] === '360' && $row['photo_360_path'] === '') {
                $row['photo_360_path'] = $photo['path'];
            } elseif ($photo['kind'] === 'flat' && $row['photo_path'] === '') {
                $row['photo_path'] = $photo['path'];
            }
        }
    }

    // Photos in display order, each { path, kind ('flat' or '360'), thumb_x,
    // thumb_y }: the square thumbnail's focus as object-position percentages
    // (50/50 = centered; only flat photos use it).
    private function _getPhotosGrouped($onlyDialogId = null)
    {
        $this->db->select('placard_dialog_id, photo_path, kind, thumb_x, thumb_y');
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
                'kind' => $row['kind'],
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

    public function create($roomName, $data, $searchTerms = array(), $photos = array(), $extraTerms = array())
    {
        $id = $this->_insertDialog($roomName, $data, $searchTerms, $extraTerms);
        $this->_replacePhotos($id, $photos);

        return $this->find($id);
    }

    // $searchTerms (the generated ones): null leaves them untouched, an
    // array (including empty) replaces that set. $extraTerms and $photos
    // follow the same convention, each on its own set.
    public function update($id, $data, $searchTerms = null, $photos = null, $extraTerms = null)
    {
        $this->_updateDialog($id, $data, $searchTerms, $extraTerms);

        if ($photos !== null) {
            $this->_replacePhotos($id, $photos);
        }

        return $this->find($id);
    }

    // The OCR Management page's save: every row it changed, in one
    // transaction, so a failure part way leaves the rooms as they were
    // rather than half saved. Each row is matched to its record by name and
    // creates one when the room has none yet. Each row: room_name,
    // ocr_enabled (0/1), placard_name (null for none), search_terms and
    // extra_search_terms (both replace their set). Returns false when the
    // transaction failed.
    public function saveOcr(array $rows)
    {
        $this->db->trans_start();
        foreach ($rows as $row) {
            $data = array('ocr_enabled' => $row['ocr_enabled'], 'placard_name' => $row['placard_name']);
            $id = $this->_idForRoomName($row['room_name']);
            if ($id === null) {
                $data['description'] = '';
                $this->_insertDialog($row['room_name'], $data, $row['search_terms'], $row['extra_search_terms']);
            } else {
                $this->_updateDialog($id, $data, $row['search_terms'], $row['extra_search_terms']);
            }
        }
        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    // room_name's collation ignores case, the same match the clients make.
    private function _idForRoomName($roomName)
    {
        $this->db->select('id');
        $this->db->from($this->table);
        $this->db->where('room_name', trim($roomName));
        $row = $this->db->get()->row_array();
        return $row ? $row['id'] : null;
    }

    private function _insertDialog($roomName, $data, $searchTerms, $extraTerms)
    {
        $now = date('Y-m-d H:i:s');
        $data['room_name'] = trim($roomName);
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $this->db->insert($this->table, $data);
        $id = $this->db->insert_id();

        $this->_insertSearchTerms($id, $searchTerms, 0);
        $this->_insertSearchTerms($id, $extraTerms, 1);
        return $id;
    }

    private function _updateDialog($id, $data, $searchTerms, $extraTerms)
    {
        if (!empty($data)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->where('id', $id);
            $this->db->update($this->table, $data);
        }
        if ($searchTerms !== null) {
            $this->_replaceSearchTerms($id, $searchTerms, 0);
        }
        if ($extraTerms !== null) {
            $this->_replaceSearchTerms($id, $extraTerms, 1);
        }
    }

    private function _replaceSearchTerms($dialogId, $terms, $isExtra)
    {
        $this->db->where('placard_dialog_id', $dialogId);
        $this->db->where('is_extra', $isExtra);
        $this->db->delete('placard_search_terms');
        $this->_insertSearchTerms($dialogId, $terms, $isExtra);
    }

    public function delete($id)
    {
        // placard_search_terms and placard_photos cascade via its own foreign key.
        $this->db->where('id', $id);
        return $this->db->delete($this->table);
    }

    private function _replacePhotos($dialogId, $paths)
    {
        $this->db->where('placard_dialog_id', $dialogId);
        $this->db->delete('placard_photos');

        $order = 0;
        foreach ($paths as $item) {
            // A bare path string is accepted as a flat photo with a centered thumbnail.
            $path = is_array($item) ? (isset($item['path']) ? $item['path'] : '') : $item;
            if (!is_string($path) || trim($path) === '') {
                continue;
            }
            $this->db->insert('placard_photos', array(
                'placard_dialog_id' => $dialogId,
                'photo_path' => trim($path),
                'kind' => (is_array($item) && isset($item['kind']) && $item['kind'] === '360') ? '360' : 'flat',
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

    // Blank, non-string and over-long terms are dropped, and a repeat is
    // stored once.
    private function _insertSearchTerms($dialogId, $terms, $isExtra)
    {
        $seen = array();
        foreach ($terms as $term) {
            if (!is_string($term)) {
                continue;
            }
            $term = trim($term);
            if ($term === '' || mb_strlen($term) > 255 || isset($seen[$term])) {
                continue;
            }
            $seen[$term] = true;
            $this->db->insert('placard_search_terms', array(
                'placard_dialog_id' => $dialogId,
                'term' => $term,
                'is_extra' => $isExtra,
            ));
        }
    }
}
