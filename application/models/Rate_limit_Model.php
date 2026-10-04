<?php
defined('BASEPATH') or exit('No direct script access allowed');

// The hit log behind Rate_limit: one row per counted request or failure.
class Rate_limit_Model extends CI_Model
{
    private $table = 'rate_limit_hits';

    public function __construct()
    {
        parent::__construct();
    }

    public function countSince($bucket, $subject, $since)
    {
        $this->db->where('bucket', $bucket);
        $this->db->where('subject', $subject);
        $this->db->where('hit_at >=', $since);
        return $this->db->get($this->table)->num_rows();
    }

    public function oldestSince($bucket, $subject, $since)
    {
        $this->db->where('bucket', $bucket);
        $this->db->where('subject', $subject);
        $this->db->where('hit_at >=', $since);
        $this->db->order_by('hit_at', 'ASC');
        $this->db->limit(1);
        $row = $this->db->get($this->table)->row_array();
        return $row ? $row['hit_at'] : null;
    }

    public function record($bucket, $subject, $now)
    {
        return $this->db->insert($this->table, array('bucket' => $bucket, 'subject' => $subject, 'hit_at' => $now));
    }

    public function purgeOlderThan($cutoff)
    {
        $this->db->where('hit_at <', $cutoff);
        $this->db->delete($this->table);
        return $this->db->affected_rows();
    }
}
