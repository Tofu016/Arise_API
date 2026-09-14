<?php
defined('BASEPATH') or exit('No direct script access allowed');

// General app/experience feedback from MainPage visitors — a rating
// plus an optional comment and optional name/email. Deliberately not
// tied to any specific room, office, or node; that was explicitly
// ruled out when this was scoped. submit() is genuinely public — no
// requireAdmin() at all — matching MainPage itself no longer requiring
// an account to use.
class Feedback_API extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Feedback_Model');
    }

    // POST /Feedback_API/submit — public, no auth required.
    public function submit()
    {
        $data = $this->getInput();

        $rating = isset($data['rating']) ? (int) $data['rating'] : 0;
        if ($rating < 1 || $rating > 5) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'Rating must be between 1 and 5.'));
            return;
        }

        $feedback = $this->Feedback_Model->create(array(
            'rating' => $rating,
            'comment' => isset($data['comment']) && trim($data['comment']) !== '' ? trim($data['comment']) : null,
            'name' => isset($data['name']) && trim($data['name']) !== '' ? trim($data['name']) : null,
            'email' => isset($data['email']) && trim($data['email']) !== '' ? trim($data['email']) : null,
        ));

        echo json_encode(array('success' => true, 'feedback' => $feedback));
    }

    // GET /Feedback_API/getAll — admin only.
    public function getAll()
    {
        $this->requireAdmin();
        echo json_encode(array('success' => true, 'feedback' => $this->Feedback_Model->getAll()));
    }

    // PATCH /Feedback_API/markReviewed/{id} — admin only.
    public function markReviewed($id)
    {
        $this->requireAdmin();
        echo json_encode(array('success' => true, 'feedback' => $this->Feedback_Model->markReviewed($id)));
    }

    // GET /Feedback_API/unreadCount — admin only.
    public function unreadCount()
    {
        $this->requireAdmin();
        echo json_encode(array('success' => true, 'count' => $this->Feedback_Model->countUnreviewed()));
    }
}
