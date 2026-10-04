<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Admin accounts: listing, creating, approving, changing a password, deleting.
// Separate from Auth_Model, which owns login and the token lifecycle
// touching this same table.
class Admins_Model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // password_hash is deliberately never selected here: this data goes
    // straight into an API response, and a hash (even a properly salted
    // one) has no reason to ever leave the server.
    public function getAll()
    {
        $this->db->select('id, email, name, status, created_at, updated_at');
        $this->db->from('admins');
        $this->db->order_by('created_at', 'ASC');
        return $this->db->get()->result_array();
    }

    public function find($id)
    {
        $this->db->select('id, email, name, status, created_at, updated_at');
        $this->db->from('admins');
        $this->db->where('id', $id);
        return $this->db->get()->row_array();
    }

    public function findByEmail($email)
    {
        $this->db->select('id, email, name, status');
        $this->db->from('admins');
        $this->db->where('email', $email);
        return $this->db->get()->row_array();
    }

    public function emailExists($email)
    {
        $this->db->where('email', $email);
        return $this->db->count_all_results('admins') > 0;
    }

    // $status is 'approved' for an account an admin or the CLI makes, and
    // 'pending' for one that registered itself and awaits approval.
    public function create($email, $passwordHash, $name, $status = 'approved')
    {
        $now = date('Y-m-d H:i:s');
        $this->db->insert('admins', array(
            'email' => $email,
            'password_hash' => $passwordHash,
            'name' => $name,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        return $this->find($this->db->insert_id());
    }

    public function approve($id)
    {
        $this->db->where('id', $id);
        $this->db->update('admins', array(
            'status' => 'approved',
            'updated_at' => date('Y-m-d H:i:s'),
        ));
        return $this->find($id);
    }

    public function updatePassword($id, $passwordHash)
    {
        $this->db->where('id', $id);
        $this->db->update('admins', array(
            'password_hash' => $passwordHash,
            'updated_at' => date('Y-m-d H:i:s'),
        ));
        return $this->find($id);
    }

    // Cascades to auth_tokens via its foreign key.
    public function delete($id)
    {
        $this->db->where('id', $id);
        return $this->db->delete('admins');
    }
}
