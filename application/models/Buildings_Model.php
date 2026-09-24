<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The simplest resource left — no graph, no nested children, just flat
// fields. Same slugify-based id generation as tour_sections/tour_stops
// for consistency, though buildings are a small, deliberately-managed
// set rather than something created frequently.
class Buildings_Model extends CI_Model
{
    private $table = 'buildings';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // campus_id reads through COALESCE(campus_id, id) — an untouched row is
    // its own solo campus, same fallback constants.js's campusForBuilding
    // already used client-side before this column existed. That means
    // grouping buildings under a shared campus is a plain per-row UPDATE,
    // never a backfill: every existing/future row keeps working with zero
    // edits until an admin explicitly sets campus_id.
    private function selectWithCampus()
    {
        $this->db->select('*, COALESCE(campus_id, id) AS campus_id', false);
    }

    public function getAll()
    {
        $this->selectWithCampus();
        $this->db->from($this->table);
        $this->db->order_by('name', 'ASC');
        return $this->db->get()->result_array();
    }

    public function find($id)
    {
        $this->selectWithCampus();
        $this->db->from($this->table);
        $this->db->where('id', $id);
        return $this->db->get()->row_array();
    }

    private function slugify($text)
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        return $slug === '' ? 'building' : $slug;
    }

    private function generateUniqueId($name)
    {
        $base = $this->slugify($name);
        $id = $base;
        $n = 2;
        while ($this->find($id)) {
            $id = $base . $n;
            $n++;
        }
        return $id;
    }

    // lat/lng genuinely nullable — only buildings on a physically
    // separate campus have coordinates set, matching the schema's own
    // documented reasoning. campus_id is separately nullable too — it's
    // pure grouping (see selectWithCampus()), not a location, so a building
    // can leave it unset (solo campus) independent of whether it has coords.
    public function create($name, $floorCount, $lat = null, $lng = null, $campusId = null)
    {
        $id = $this->generateUniqueId($name);
        $now = date('Y-m-d H:i:s');

        $data = array(
            'id' => $id,
            'name' => trim($name),
            'floor_count' => $floorCount,
            'created_at' => $now,
            'updated_at' => $now,
        );
        if ($lat !== null && $lat !== '') {
            $data['lat'] = $lat;
        }
        if ($lng !== null && $lng !== '') {
            $data['lng'] = $lng;
        }
        if ($campusId !== null && $campusId !== '') {
            $data['campus_id'] = $campusId;
        }

        $this->db->insert($this->table, $data);
        return $this->find($id);
    }

    public function update($id, $data)
    {
        // An empty-string campus_id means "un-group me" — must land as SQL
        // NULL, not '', or selectWithCampus()'s COALESCE would stop falling
        // back to the building's own id and treat '' as a real campus.
        if (array_key_exists('campus_id', $data) && $data['campus_id'] === '') {
            $data['campus_id'] = null;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', $id);
        $this->db->update($this->table, $data);
        return $this->find($id);
    }

    // Checked before delete — CI3, by default, halts on a failed query
    // and shows its own raw error page rather than behaving like a
    // normal catchable PHP exception. Checking first and refusing
    // cleanly is simpler and safer than trying to suppress/catch a
    // database-level failure after the fact.
    public function hasNodes($buildingId)
    {
        $this->db->select('id');
        $this->db->from('nodes');
        $this->db->where('building', $buildingId);
        $this->db->limit(1);
        return $this->db->get()->num_rows() > 0;
    }

    // Exposed via the Controller with explicit error handling for the
    // case where nodes still reference this building — the schema's own
    // ON DELETE RESTRICT already prevents the delete at the database
    // level; the Controller's job is just turning that into a clear
    // message instead of a raw SQL error.
    public function delete($id)
    {
        $this->db->where('id', $id);
        return $this->db->delete($this->table);
    }
}
