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
            return Api_response::fail(400, 'Rating must be between 1 and 5.');
        }

        $feedback = $this->Feedback_Model->create(array(
            'rating' => $rating,
            'comment' => isset($data['comment']) && trim($data['comment']) !== '' ? trim($data['comment']) : null,
            'name' => isset($data['name']) && trim($data['name']) !== '' ? trim($data['name']) : null,
            'email' => isset($data['email']) && trim($data['email']) !== '' ? trim($data['email']) : null,
        ));

        return Api_response::ok(array('feedback' => $feedback));
    }

    // GET /Feedback_API/getAll (admin only). Optional query filters (all
    // additive, see Feedback_Model::getAll): from, to, minRating, maxRating,
    // hasComment. Paging: limit (1-100, omitted = every row), offset. sort:
    // unreviewed (default), newest, oldest, rating_desc, rating_asc. Returns
    // { feedback, total, unreviewedCount }, the counts covering every row
    // matching the filters, not just this page.
    public function getAll()
    {
        $this->requireAdmin();
        $limit = $this->input->get('limit');
        $limit = $limit !== null && $limit !== '' ? min(100, max(1, (int) $limit)) : null;
        $offset = (int) $this->input->get('offset');
        $sort = $this->input->get('sort') ?: 'unreviewed';
        return Api_response::ok($this->Feedback_Model->getAll($this->filtersFromQuery(), $sort, $limit, $offset));
    }

    // GET /Feedback_API/ratingCounts (admin only). Same from/to/minRating/
    // maxRating filters as getAll, minus hasComment (see
    // Feedback_Model::ratingCounts). Returns { counts: { "1": n, ..., "5": n } }.
    public function ratingCounts()
    {
        $this->requireAdmin();
        return Api_response::ok(array('counts' => $this->Feedback_Model->ratingCounts($this->filtersFromQuery())));
    }

    // The from/to/minRating/maxRating filters getAll and ratingCounts share.
    private function filtersFromQuery()
    {
        return array(
            'from' => $this->input->get('from'),
            'to' => $this->input->get('to'),
            'minRating' => $this->input->get('minRating'),
            'maxRating' => $this->input->get('maxRating'),
            'hasComment' => $this->input->get('hasComment'),
        );
    }

    // PATCH /Feedback_API/markReviewed/{id} — admin only.
    public function markReviewed($id)
    {
        $this->requireAdmin();
        return Api_response::ok(array('feedback' => $this->Feedback_Model->markReviewed($id)));
    }

    // GET /Feedback_API/unreadCount — admin only.
    public function unreadCount()
    {
        $this->requireAdmin();
        return Api_response::ok(array('count' => $this->Feedback_Model->countUnreviewed()));
    }
}
