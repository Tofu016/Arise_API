<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Neighbor_links.php';

// Mirrors useGraphCollection.js's tourStops behavior — same slug-based
// id generation as TourSections_Model, plus the bidirectional
// neighbor-link management that tour_sections never
// needed. Reads assemble the nested shape (a stop with its neighbors)
// from batched queries rather than one query per stop — avoids an N+1
// query problem as the number of stops grows.
class TourStops_Model extends CI_Model
{
    private $table = 'tour_stops';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // ---------- Reads ----------

    public function getAll()
    {
        $stops = $this->_getStopRows();
        $neighborsByStop = $this->_getNeighborsGrouped();

        foreach ($stops as &$stop) {
            $stopId = $stop['id'];
            $stop['neighbors'] = isset($neighborsByStop[$stopId]) ? $neighborsByStop[$stopId] : array();
        }
        unset($stop);

        return $stops;
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

        $neighborsByStop = $this->_getNeighborsGrouped($id);
        $row['neighbors'] = isset($neighborsByStop[$id]) ? $neighborsByStop[$id] : array();

        return $row;
    }

    private function _getStopRows()
    {
        $this->db->select('*');
        $this->db->from($this->table);
        $this->db->order_by('name', 'ASC');
        return $this->db->get()->result_array();
    }

    // The edge table and its owner column are the only things that differ
    // from Nodes_Model's neighbour links — see Neighbor_links.
    private function neighborLinks()
    {
        return new Neighbor_links($this->db, 'tour_stop_neighbors', 'tour_stop_id');
    }

    // Optionally scoped to a single stop_id (used by find()) — otherwise
    // fetches every edge in one query and groups in PHP.
    private function _getNeighborsGrouped($onlyStopId = null)
    {
        return $this->neighborLinks()->groupedByOwner($onlyStopId);
    }

    // ---------- Stop CRUD ----------

    // Same slugify + collision-avoidance approach as
    // TourSections_Model — kept as its own private copy here rather
    // than shared, since the two Models have no other reason to depend
    // on each other; duplicating ~10 lines is cheaper than introducing
    // a coupling between otherwise-independent resources.
    private function slugify($text)
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        return $slug === '' ? 'stop' : $slug;
    }

    private function generateUniqueId($name)
    {
        $base = 'tour_' . $this->slugify($name);
        $id = $base;
        $n = 2;
        while ($this->_stopExists($id)) {
            $id = $base . $n;
            $n++;
        }
        return $id;
    }

    private function _stopExists($id)
    {
        $this->db->select('id');
        $this->db->from($this->table);
        $this->db->where('id', $id);
        return $this->db->get()->num_rows() > 0;
    }

    public function idExists($id)
    {
        return $this->_stopExists($id);
    }

    // $requestedId: the admin-suggested/edited id from TourStopForm.jsx —
    // the original Firestore version always let the CALLER choose the
    // doc id upfront (TourStopsPage.jsx selects it immediately after
    // creating, without waiting for a server response), so this preserves
    // that same contract rather than always generating its own. Falls
    // back to auto-generation only when no id is actually provided.
    public function create($name, $sectionId = null, $photoPath = null, $description = null, $requestedId = null, $coverPhotoPath = null)
    {
        $id = !empty($requestedId) ? $requestedId : $this->generateUniqueId($name);
        $now = date('Y-m-d H:i:s');

        $data = array(
            'id' => $id,
            'name' => trim($name),
            'created_at' => $now,
            'updated_at' => $now,
        );
        // section_id is genuinely nullable — a stop can exist with no
        // section, matching the confirmed original behavior — so this
        // is only set when actually provided, not forced to an empty
        // string.
        if (!empty($sectionId)) {
            $data['section_id'] = $sectionId;
        }
        if (!empty($photoPath)) {
            $data['photo_path'] = $photoPath;
        }
        if (!empty($coverPhotoPath)) {
            $data['cover_photo_path'] = $coverPhotoPath;
        }
        if (!empty($description)) {
            $data['description'] = $description;
        }

        $this->db->insert($this->table, $data);
        return $this->find($id);
    }

    // Safe only because the schema's own foreign key (tour_stop_neighbors)
    // now specifies ON UPDATE CASCADE — MySQL rewrites
    // every referencing row automatically. Firestore's version had to do
    // this by hand (write new doc, delete old, fix up every other item's
    // neighbor list, all as one batch) purely because Firestore has no
    // equivalent cascade mechanism at all.
    public function renameStop($oldId, $newId)
    {
        $this->db->where('id', $oldId);
        $this->db->update($this->table, array(
            'id' => $newId,
            'updated_at' => date('Y-m-d H:i:s'),
        ));
        return $this->find($newId);
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
        // Neighbors cascade-delete via the schema's own foreign key —
        // nothing extra to clean up here manually.
        $this->db->where('id', $id);
        return $this->db->delete($this->table);
    }

    // ---------- Neighbor links (bidirectional) ----------

    // A "connect A and B" action always writes BOTH directions in one
    // call, each with its own yaw/pitch — see Neighbor_links::link.
    public function addNeighbor($stopId, $neighborId, $yaw, $pitch, $reverseYaw, $reversePitch)
    {
        $this->neighborLinks()->link($stopId, $neighborId, $yaw, $pitch, $reverseYaw, $reversePitch);
    }

    public function removeNeighbor($stopId, $neighborId)
    {
        $this->neighborLinks()->unlink($stopId, $neighborId);
    }

    // Updates ONE existing edge's angle only — the direction FROM
    // stopId TOWARD neighborId. See Neighbor_links::setAngle for why
    // addNeighbor() alone can't do this.
    public function updateNeighborAngle($stopId, $neighborId, $yaw, $pitch)
    {
        return $this->neighborLinks()->setAngle($stopId, $neighborId, $yaw, $pitch);
    }

    // The arrival view for this one edge only — see
    // Neighbor_links::setDefaultView. $yaw/$pitch null clears it.
    public function updateNeighborDefaultView($stopId, $neighborId, $yaw, $pitch)
    {
        return $this->neighborLinks()->setDefaultView($stopId, $neighborId, $yaw, $pitch);
    }
}
