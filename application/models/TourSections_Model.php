<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Mirrors useTourSections.js's own logic exactly — id generated here,
// silently, from the label (slug + numeric suffix on collision), same
// as the original Firestore-backed hook did. Matches the client's own
// naming convention from their reference template (Login_Model).
class TourSections_Model extends CI_Model
{
    private $table = 'tour_sections';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function getAll()
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->order_by('label', 'ASC');
        $query = $this->db->get();
        return $query->result_array();
    }

    public function find($id)
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->where('id', $id);
        $query = $this->db->get();
        return $query->row_array();
    }

    // Same slug rule as tourConstants.js's own slugify() — lowercase,
    // non-alphanumeric runs collapsed to a single underscore, no leading/
    // trailing underscores.
    private function slugify($text)
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        return $slug === '' ? 'section' : $slug;
    }

    // Same collision-avoidance as useTourSections.js's addSection: try
    // the bare slug first, then slug2, slug3, ... until one isn't
    // already in use. A real SELECT per candidate rather than loading
    // every id into PHP and checking in memory — this table is small
    // and admin-only/low-frequency, so the extra queries are cheap, and
    // it avoids ever needing to load the whole table just to pick an id.
    private function generateUniqueId($label)
    {
        $base = $this->slugify($label);
        $id = $base;
        $n = 2;
        while ($this->find($id)) {
            $id = $base . $n;
            $n++;
        }
        return $id;
    }

    // Returns the newly-created row (including its generated id) on
    // success, matching addSection's own return shape — the caller
    // (the Controller) needs the id to send back to the client.
    public function create($label, $coverPhotoPath = null)
    {
        $trimmedLabel = trim($label);
        if ($trimmedLabel === '') {
            return false;
        }

        $id = $this->generateUniqueId($trimmedLabel);
        $now = date('Y-m-d H:i:s');

        $data = array(
            'id' => $id,
            'label' => $trimmedLabel,
            'created_at' => $now,
            'updated_at' => $now,
        );
        // Only included when genuinely set — same reasoning as the
        // original JS (Firestore rejects undefined values outright;
        // here it's just about not overwriting with an empty string
        // when no cover photo was actually uploaded yet).
        if ($coverPhotoPath !== null && $coverPhotoPath !== '') {
            $data['cover_photo_path'] = $coverPhotoPath;
        }

        $this->db->insert($this->table, $data);
        return $this->find($id);
    }

    public function update($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', $id);
        $this->db->update($this->table, $data);
        return $this->find($id);
    }

    public function delete($id)
    {
        // Deliberately doesn't cascade-clean any tour_stops referencing
        // this section — the schema's own foreign key already handles
        // this correctly (ON DELETE SET NULL on tour_stops.section_id),
        // matching the original JS hook's own documented behavior of not
        // touching stops when their section is deleted.
        $this->db->where('id', $id);
        return $this->db->delete($this->table);
    }
}
