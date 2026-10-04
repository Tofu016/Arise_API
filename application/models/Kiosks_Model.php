<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Registered kiosks (see Kiosk_rules) and the failed pairing attempts used
// to rate-limit code guessing. Hashes never leave this model: every read
// returns the pairing state as plain fields (paired, code_pending).
class Kiosks_Model extends CI_Model
{
    private $table = 'kiosks';
    private $failuresTable = 'kiosk_pair_failures';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    private function publicSelect()
    {
        $this->db->select(
            'kiosks.id, kiosks.name, kiosks.node_id, nodes.name AS node_name, kiosks.paired_at, kiosks.last_seen_at, ' .
            'kiosks.created_at, kiosks.pairing_expires_at, (kiosks.token_hash IS NOT NULL) AS paired, ' .
            '(kiosks.pairing_code_hash IS NOT NULL) AS code_pending'
        );
        $this->db->from($this->table);
        $this->db->join('nodes', 'nodes.id = kiosks.node_id', 'left');
    }

    public function getAll()
    {
        $this->publicSelect();
        $this->db->order_by('kiosks.name', 'ASC');
        return $this->db->get()->result_array();
    }

    public function find($id)
    {
        $this->publicSelect();
        $this->db->where('kiosks.id', $id);
        $row = $this->db->get()->row_array();
        return $row ? $row : null;
    }

    public function create(array $fields, $codeHash, $expiresAt)
    {
        $fields['pairing_code_hash'] = $codeHash;
        $fields['pairing_expires_at'] = $expiresAt;
        $this->db->insert($this->table, $fields);
        return $this->find($this->db->insert_id());
    }

    public function update($id, array $fields)
    {
        $this->db->where('id', $id);
        $this->db->update($this->table, $fields);
        return $this->find($id);
    }

    // A fresh pairing code. Also revokes the current token, so "reset"
    // always means the old device stops being trusted.
    public function issueCode($id, $codeHash, $expiresAt)
    {
        $this->db->where('id', $id);
        $this->db->update($this->table, array(
            'pairing_code_hash' => $codeHash,
            'pairing_expires_at' => $expiresAt,
            'token_hash' => null,
            'paired_at' => null,
        ));
        return $this->find($id);
    }

    public function delete($id)
    {
        $this->db->where('id', $id);
        return $this->db->delete($this->table);
    }

    // Single use: swaps a live code for a token in one conditional update,
    // so two devices racing with the same code cannot both win. Returns the
    // kiosk, or null when the code is wrong or has expired.
    public function redeemCode($codeHash, $tokenHash, $now)
    {
        $this->db->select('id');
        $this->db->from($this->table);
        $this->db->where('pairing_code_hash', $codeHash);
        $this->db->where('pairing_expires_at >', $now);
        $row = $this->db->get()->row_array();
        if (!$row) {
            return null;
        }

        $this->db->where('id', $row['id']);
        $this->db->where('pairing_code_hash', $codeHash);
        $this->db->update($this->table, array(
            'pairing_code_hash' => null,
            'pairing_expires_at' => null,
            'token_hash' => $tokenHash,
            'paired_at' => $now,
            'last_seen_at' => $now,
        ));
        if ($this->db->affected_rows() !== 1) {
            return null;
        }
        return $this->find($row['id']);
    }

    public function findByToken($tokenHash)
    {
        $this->db->select('id');
        $this->db->from($this->table);
        $this->db->where('token_hash', $tokenHash);
        $row = $this->db->get()->row_array();
        return $row ? $this->find($row['id']) : null;
    }

    public function touch($id, $now)
    {
        $this->db->where('id', $id);
        return $this->db->update($this->table, array('last_seen_at' => $now));
    }

    public function clearToken($id)
    {
        $this->db->where('id', $id);
        return $this->db->update($this->table, array('token_hash' => null, 'paired_at' => null));
    }

    public function recentFailures($ip, $since)
    {
        $this->db->from($this->failuresTable);
        $this->db->where('ip', $ip);
        $this->db->where('attempted_at >=', $since);
        return $this->db->count_all_results();
    }

    public function recordFailure($ip, $now)
    {
        return $this->db->insert($this->failuresTable, array('ip' => $ip, 'attempted_at' => $now));
    }
}
