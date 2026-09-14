<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Feedback_Model extends CI_Model
{
    private $table = 'app_feedback';

    public function __construct()
    {
        parent::__construct();
    }

    public function create($data)
    {
        $this->db->insert($this->table, array(
            'rating' => $data['rating'],
            'comment' => isset($data['comment']) ? $data['comment'] : null,
            'name' => isset($data['name']) ? $data['name'] : null,
            'email' => isset($data['email']) ? $data['email'] : null,
        ));
        return $this->find($this->db->insert_id());
    }

    public function find($id)
    {
        return $this->db->get_where($this->table, array('id' => $id))->row_array();
    }

    // Most recent first — an admin reviewing feedback wants to see
    // what's new, not scroll to the bottom of a long list.
    public function getAll()
    {
        return $this->db->order_by('created_at', 'DESC')->get($this->table)->result_array();
    }

    public function markReviewed($id)
    {
        $this->db->where('id', $id)->update($this->table, array(
            'reviewed_at' => date('Y-m-d H:i:s'),
        ));
        return $this->find($id);
    }

    // Powers a badge/count in the admin panel — independent of whether
    // the notification email itself ever actually sends, since that
    // depends on SMTP being configured at all.
    public function countUnreviewed()
    {
        return (int) $this->db->where('reviewed_at', null)->count_all_results($this->table);
    }
}
