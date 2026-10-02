<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Signage_rules.php';

// Signage slides (the kiosk bottom band's rotation, see Signage_rules) and
// the one settings row. Slides are few (a handful of campaigns at a time),
// so the live filter runs in PHP over the active rows rather than as a
// date query, which keeps "is this slide on right now" in one tested place
// (Signage_rules::isLive).
class Signage_Model extends CI_Model
{
    private $table = 'signage_slides';
    private $settingsTable = 'signage_settings';

    // The settings when no row has been saved yet. A missing row is the
    // normal state of a fresh install, not an error, so there is no seed.
    const DEFAULT_SETTINGS = array(
        'rotation_order' => 'sequence',
        'transition' => 'fade',
        'default_duration_seconds' => 10.0,
    );

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // Every slide, in rotation order (ties, which only a hand edit could
    // make, fall back to the oldest first).
    public function getAll()
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->order_by('sort_order', 'ASC');
        $this->db->order_by('id', 'ASC');
        return $this->db->get()->result_array();
    }

    // The slides on the kiosk at $now, in rotation order.
    public function getLive($now)
    {
        $live = array();
        foreach ($this->getAll() as $row) {
            if (Signage_rules::isLive($row, $now)) {
                $live[] = $row;
            }
        }
        return $live;
    }

    public function find($id)
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->where('id', $id);
        $row = $this->db->get()->row_array();
        return $row ? $row : null;
    }

    public function allIds()
    {
        $this->db->select('id');
        $this->db->from($this->table);
        return array_column($this->db->get()->result_array(), 'id');
    }

    // A new slide joins the end of the rotation.
    public function create(array $fields)
    {
        $this->db->select_max('sort_order', 'max_order');
        $this->db->from($this->table);
        $max = $this->db->get()->row_array();
        $fields['sort_order'] = ($max && $max['max_order'] !== null) ? (int) $max['max_order'] + 1 : 0;

        $now = date('Y-m-d H:i:s');
        $fields['created_at'] = $now;
        $fields['updated_at'] = $now;

        $this->db->insert($this->table, $fields);
        return $this->find($this->db->insert_id());
    }

    public function update($id, array $fields)
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', $id);
        $this->db->update($this->table, $fields);
        return $this->find($id);
    }

    public function delete($id)
    {
        $this->db->where('id', $id);
        return $this->db->delete($this->table);
    }

    // $ids: every slide id, in the new order (see Signage_rules::reorderIds).
    // One transaction, so a failure part-way can't leave two slides
    // sharing a position.
    public function reorder(array $ids)
    {
        $this->db->trans_start();
        foreach ($ids as $position => $id) {
            $this->db->where('id', $id);
            $this->db->update($this->table, array('sort_order' => $position));
        }
        $this->db->trans_complete();
        return $this->db->trans_status();
    }

    public function getSettings()
    {
        $this->db->select('rotation_order, transition, default_duration_seconds');
        $this->db->from($this->settingsTable);
        $this->db->where('id', 1);
        $row = $this->db->get()->row_array();
        return $row ? array_merge(self::DEFAULT_SETTINGS, $row) : self::DEFAULT_SETTINGS;
    }

    // Upserts the single row (id 1), filling unsent fields from the
    // current settings so a first save never stores a partial row.
    public function updateSettings(array $fields)
    {
        $row = array_merge($this->getSettings(), $fields);
        $row['id'] = 1;
        $row['updated_at'] = date('Y-m-d H:i:s');
        $this->db->replace($this->settingsTable, $row);
        return $this->getSettings();
    }
}
