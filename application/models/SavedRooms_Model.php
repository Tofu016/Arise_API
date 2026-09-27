<?php
defined('BASEPATH') or exit('No direct script access allowed');

// A user's saved rooms (the mobile app's bookmark on a room card). A saved
// room points at the room's details record (placard_dialogs.id) rather than
// its name, so an admin renaming the room doesn't break anyone's list; the
// foreign keys remove the saved rows with the user or the room's details.
// Every query here is scoped to one user id.
class SavedRooms_Model extends CI_Model
{
    private $table = 'saved_rooms';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // Newest first, each with the room's current name.
    public function getForUser($userId)
    {
        $this->db->select('saved_rooms.placard_dialog_id, placard_dialogs.room_name, saved_rooms.created_at');
        $this->db->from($this->table);
        $this->db->join('placard_dialogs', 'placard_dialogs.id = saved_rooms.placard_dialog_id');
        $this->db->where('saved_rooms.user_id', $userId);
        $this->db->order_by('saved_rooms.created_at', 'DESC');
        $this->db->order_by('saved_rooms.id', 'DESC');
        $rows = $this->db->get()->result_array();

        foreach ($rows as &$row) {
            $row['placard_dialog_id'] = (int) $row['placard_dialog_id'];
        }
        unset($row);
        return $rows;
    }

    public function countForUser($userId)
    {
        $this->db->select('id');
        $this->db->from($this->table);
        $this->db->where('user_id', $userId);
        return $this->db->get()->num_rows();
    }

    public function isSaved($userId, $placardDialogId)
    {
        $this->db->select('id');
        $this->db->from($this->table);
        $this->db->where('user_id', $userId);
        $this->db->where('placard_dialog_id', $placardDialogId);
        return $this->db->get()->num_rows() > 0;
    }

    public function save($userId, $placardDialogId)
    {
        return $this->db->insert($this->table, array(
            'user_id' => $userId,
            'placard_dialog_id' => $placardDialogId,
        ));
    }

    // Only ever this user's row: another user's saved room with the same
    // id is untouched.
    public function remove($userId, $placardDialogId)
    {
        $this->db->where('user_id', $userId);
        $this->db->where('placard_dialog_id', $placardDialogId);
        return $this->db->delete($this->table);
    }
}
