<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Email_Model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    // Batch-limited on purpose — a single run shouldn't try to blast
    // through an unbounded backlog if the job hasn't run in a while for
    // some reason (the SMTP server going down, the task getting
    // disabled, etc.). Runs again on the next scheduled tick regardless.
    public function getPending($limit = 20)
    {
        $this->db->select('*');
        $this->db->from('email_queue');
        $this->db->where('sent_at', null);
        $this->db->order_by('created_at', 'ASC');
        $this->db->limit($limit);
        $query = $this->db->get();
        return $query->result_array();
    }

    public function markSent($id)
    {
        $this->db->where('id', $id);
        return $this->db->update('email_queue', array(
            'sent_at' => date('Y-m-d H:i:s'),
        ));
    }
}
