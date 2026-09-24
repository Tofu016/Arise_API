<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Neighbor_links.php';

// Mirrors useGraphCollection.js's tourStops behavior — same slug-based
// id generation as TourSections_Model, plus the bidirectional
// neighbor-link and marker/photo management that tour_sections never
// needed. Reads assemble the nested shape (a stop with its neighbors
// and markers-with-photos) from four separate, batched queries rather
// than one query per stop — avoids an N+1 query problem as the number
// of stops grows.
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
        $markersByStop = $this->_getMarkersGrouped();

        foreach ($stops as &$stop) {
            $stopId = $stop['id'];
            $stop['neighbors'] = isset($neighborsByStop[$stopId]) ? $neighborsByStop[$stopId] : array();
            $stop['markers'] = isset($markersByStop[$stopId]) ? $markersByStop[$stopId] : array();
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
        $markersByStop = $this->_getMarkersGrouped($id);
        $row['neighbors'] = isset($neighborsByStop[$id]) ? $neighborsByStop[$id] : array();
        $row['markers'] = isset($markersByStop[$id]) ? $markersByStop[$id] : array();

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

    private function _getMarkersGrouped($onlyStopId = null)
    {
        $this->db->select('*');
        $this->db->from('tour_stop_markers');
        if ($onlyStopId !== null) {
            $this->db->where('tour_stop_id', $onlyStopId);
        }
        $markerRows = $this->db->get()->result_array();

        if (empty($markerRows)) {
            return array();
        }

        $markerIds = array();
        foreach ($markerRows as $m) {
            $markerIds[] = $m['id'];
        }

        $this->db->select('*');
        $this->db->from('tour_stop_marker_photos');
        $this->db->where_in('marker_id', $markerIds);
        $this->db->order_by('sort_order', 'ASC');
        $photoRows = $this->db->get()->result_array();

        $photosByMarker = array();
        foreach ($photoRows as $p) {
            $photosByMarker[$p['marker_id']][] = array(
                'id' => $p['id'],
                'photo_path' => $p['photo_path'],
                'sort_order' => $p['sort_order'],
            );
        }

        $grouped = array();
        foreach ($markerRows as $m) {
            $grouped[$m['tour_stop_id']][] = array(
                'id' => $m['id'],
                'type' => $m['type'],
                'label' => $m['label'],
                'yaw' => $m['yaw'],
                'pitch' => $m['pitch'],
                'photos' => isset($photosByMarker[$m['id']]) ? $photosByMarker[$m['id']] : array(),
            );
        }
        return $grouped;
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
    public function create($name, $sectionId = null, $photoPath = null, $description = null, $requestedId = null)
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
        if (!empty($description)) {
            $data['description'] = $description;
        }

        $this->db->insert($this->table, $data);
        return $this->find($id);
    }

    // Safe only because the schema's own foreign keys (tour_stop_neighbors,
    // tour_stop_markers) now specify ON UPDATE CASCADE — MySQL rewrites
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
        // Neighbors, markers, and marker photos all cascade-delete via
        // the schema's own foreign keys — nothing extra to clean up
        // here manually.
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

    // ---------- Equipment markers ----------

    // $photoPaths is a plain array of paths — sort_order is assigned
    // here from the array's own order, since that's the carousel order
    // the admin actually set when uploading, and a relational table
    // needs that made explicit (Firestore's array index did this
    // implicitly).
    public function addMarker($stopId, $label, $yaw, $pitch, $photoPaths = array())
    {
        $this->db->insert('tour_stop_markers', array(
            'tour_stop_id' => $stopId,
            'type' => 'equipment',
            'label' => $label,
            'yaw' => $yaw,
            'pitch' => $pitch,
        ));
        $markerId = $this->db->insert_id();

        $this->_insertMarkerPhotos($markerId, $photoPaths);

        return $markerId;
    }

    public function updateMarker($markerId, $data, $photoPaths = null)
    {
        if (!empty($data)) {
            $this->db->where('id', $markerId);
            $this->db->update('tour_stop_markers', $data);
        }

        // Only touches photos if a new set was actually provided —
        // null specifically means "leave the photos alone", distinct
        // from an empty array, which means "clear them all".
        if ($photoPaths !== null) {
            $this->db->where('marker_id', $markerId);
            $this->db->delete('tour_stop_marker_photos');
            $this->_insertMarkerPhotos($markerId, $photoPaths);
        }
    }

    public function deleteMarker($markerId)
    {
        // tour_stop_marker_photos cascades via its own foreign key.
        $this->db->where('id', $markerId);
        return $this->db->delete('tour_stop_markers');
    }

    private function _insertMarkerPhotos($markerId, $photoPaths)
    {
        $sortOrder = 0;
        foreach ($photoPaths as $path) {
            $this->db->insert('tour_stop_marker_photos', array(
                'marker_id' => $markerId,
                'photo_path' => $path,
                'sort_order' => $sortOrder,
            ));
            $sortOrder++;
        }
    }
}
