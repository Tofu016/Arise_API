<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The links between neighbouring panoramas: an edge table with one row per
// direction, each carrying its own yaw and pitch, since the angle to walk
// differs depending on which way you go. Nodes and tour stops both work
// this way and differ only in which table holds the edges and what its
// owner column is called ("node_id" vs "tour_stop_id"), so both models
// hand those two names to one of these and delegate.
//
// Queries go through the CodeIgniter query builder passed in; a recording
// fake stands in for it in tests. Behaviour is exactly what the two models
// each did before, including that link() writes its two rows without a
// transaction and does not check either insert.
class Neighbor_links
{
    private $db;
    private $table;
    private $ownerColumn;

    public function __construct($db, $table, $ownerColumn)
    {
        $this->db = $db;
        $this->table = $table;
        $this->ownerColumn = $ownerColumn;
    }

    // "Connect A and B": always writes BOTH directions in one call, each
    // with its own yaw/pitch, mirroring useGraphCollection.js's own link
    // behaviour.
    public function link($a, $b, $yaw, $pitch, $reverseYaw, $reversePitch)
    {
        $this->db->insert($this->table, array(
            $this->ownerColumn => $a,
            'neighbor_id' => $b,
            'yaw' => $yaw,
            'pitch' => $pitch,
        ));
        $this->db->insert($this->table, array(
            $this->ownerColumn => $b,
            'neighbor_id' => $a,
            'yaw' => $reverseYaw,
            'pitch' => $reversePitch,
        ));
    }

    // Removes both directions.
    public function unlink($a, $b)
    {
        $this->db->where($this->ownerColumn, $a);
        $this->db->where('neighbor_id', $b);
        $this->db->delete($this->table);

        $this->db->where($this->ownerColumn, $b);
        $this->db->where('neighbor_id', $a);
        $this->db->delete($this->table);
    }

    // Updates ONE existing edge's angle only — the direction FROM $from
    // TOWARD $to — without touching the reverse direction. This is what
    // the original setHotspot() needs: a link gets added first (often with
    // placeholder angles), then each direction's real angle is set
    // independently once the admin places it on the panorama. link()
    // alone can't do this; it always writes both new rows.
    public function setAngle($from, $to, $yaw, $pitch)
    {
        $this->db->where($this->ownerColumn, $from);
        $this->db->where('neighbor_id', $to);
        return $this->db->update($this->table, array(
            'yaw' => $yaw,
            'pitch' => $pitch,
        ));
    }

    // Every edge, or only those owned by $onlyOwner, as
    // array(ownerId => list of array('neighbor_id', 'yaw', 'pitch')).
    // One query however many owners there are, grouped in PHP.
    public function groupedByOwner($onlyOwner = null)
    {
        $this->db->select('*');
        $this->db->from($this->table);
        if ($onlyOwner !== null) {
            $this->db->where($this->ownerColumn, $onlyOwner);
        }
        $rows = $this->db->get()->result_array();

        $grouped = array();
        foreach ($rows as $row) {
            $grouped[$row[$this->ownerColumn]][] = array(
                'neighbor_id' => $row['neighbor_id'],
                'yaw' => $row['yaw'],
                'pitch' => $row['pitch'],
            );
        }
        return $grouped;
    }
}
