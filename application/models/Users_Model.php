<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Admin management of existing accounts — listing, changing roles,
// deleting. Deliberately separate from Auth_Model, which owns the
// authentication flows (login/register/tokens/reset) touching this
// same table — different concern, same reasoning as tour_sections and
// tour_stops being separate Models despite structural similarity.
class Users_Model extends CI_Model
{
    private $allowedRoles = array('pending', 'user', 'admin');

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function getAllowedRoles()
    {
        return $this->allowedRoles;
    }

    public function isValidRole($role)
    {
        return in_array($role, $this->allowedRoles, true);
    }

    // password_hash is deliberately never selected here — this data
    // goes straight into an API response, and a hash (even a properly
    // salted one) has no reason to ever leave the server.
    public function getAll()
    {
        $this->db->select('id, email, name, role, created_at, updated_at');
        $this->db->from('users');
        $this->db->order_by('created_at', 'ASC');
        return $this->db->get()->result_array();
    }

    public function find($id)
    {
        $this->db->select('id, email, name, role, created_at, updated_at');
        $this->db->from('users');
        $this->db->where('id', $id);
        return $this->db->get()->row_array();
    }

    public function updateRole($id, $newRole)
    {
        $this->db->where('id', $id);
        $this->db->update('users', array(
            'role' => $newRole,
            'updated_at' => date('Y-m-d H:i:s'),
        ));
        return $this->find($id);
    }

    // Cascades to auth_tokens and password_resets via their own foreign
    // keys — no separate Auth-side cleanup needed, unlike the original
    // Firebase version, which had to explicitly delete a separate Auth
    // record alongside the Firestore profile.
    public function delete($id)
    {
        $this->db->where('id', $id);
        return $this->db->delete('users');
    }
}
