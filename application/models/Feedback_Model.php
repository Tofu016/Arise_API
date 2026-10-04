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

    // Sort orders the Analytics dashboard's Comments section offers. Keyed by
    // the public `sort` query value so nothing user-supplied reaches
    // order_by() directly. "unreviewed" (the default) puts anything not yet
    // reviewed on top, newest first within each group, since an admin
    // reviewing feedback wants to see what's new, not scroll to the bottom.
    private $sortOrders = array(
        'unreviewed' => array(array('reviewed_at IS NULL', 'DESC'), array('created_at', 'DESC')),
        'newest' => array(array('created_at', 'DESC')),
        'oldest' => array(array('created_at', 'ASC')),
        'rating_desc' => array(array('rating', 'DESC'), array('created_at', 'DESC')),
        'rating_asc' => array(array('rating', 'ASC'), array('created_at', 'DESC')),
    );

    // $filters is optional, all additive: from/to (date, inclusive),
    // minRating/maxRating (a rating range, both inclusive), hasComment (bool),
    // reviewed (bool: '1' reviewed only, '0' unreviewed only).
    private function applyFilters($filters)
    {
        if (!empty($filters['from'])) {
            $this->db->where('created_at >=', $filters['from'] . ' 00:00:00');
        }
        if (!empty($filters['to'])) {
            $this->db->where('created_at <=', $filters['to'] . ' 23:59:59');
        }
        if (!empty($filters['minRating'])) {
            $this->db->where('rating >=', (int) $filters['minRating']);
        }
        if (!empty($filters['maxRating'])) {
            $this->db->where('rating <=', (int) $filters['maxRating']);
        }
        if (isset($filters['hasComment']) && $filters['hasComment'] !== '' && $filters['hasComment'] !== null) {
            if ($filters['hasComment']) {
                $this->db->where('comment IS NOT NULL', null, false)->where("comment !=", '');
            } else {
                $this->db->group_start()->where('comment', null)->or_where('comment', '')->group_end();
            }
        }
        if (isset($filters['reviewed']) && $filters['reviewed'] !== '' && $filters['reviewed'] !== null) {
            $this->db->where($filters['reviewed'] ? 'reviewed_at IS NOT NULL' : 'reviewed_at IS NULL', null, false);
        }
    }

    // One page of feedback plus the totals the Comments section needs to
    // offer "Load more" and its "N new" badge without fetching every row.
    // $limit null returns everything from $offset on (the unpaginated
    // behavior older callers relied on).
    public function getAll($filters = array(), $sort = 'unreviewed', $limit = null, $offset = 0)
    {
        $this->applyFilters($filters);
        $total = $this->db->count_all_results($this->table);

        // Ignores the reviewed filter: the "N new" badge counts what's still
        // waiting in this range even while the list shows only reviewed rows.
        $this->applyFilters(array_merge($filters, array('reviewed' => null)));
        $this->db->where('reviewed_at IS NULL', null, false);
        $unreviewed = $this->db->count_all_results($this->table);

        $this->applyFilters($filters);
        $order = isset($this->sortOrders[$sort]) ? $this->sortOrders[$sort] : $this->sortOrders['unreviewed'];
        foreach ($order as $clause) {
            // Escaping off so the "reviewed_at IS NULL" expression survives;
            // every column/direction here comes from the whitelist above.
            $this->db->order_by($clause[0], $clause[1], false);
        }
        if ($limit !== null) {
            $this->db->limit((int) $limit, max(0, (int) $offset));
        }
        return array(
            'feedback' => $this->db->get($this->table)->result_array(),
            'total' => $total,
            'unreviewedCount' => $unreviewed,
        );
    }

    // Counts per star rating (1-5) for the same filters as getAll, minus
    // hasComment: the Rating distribution chart is deliberately the one
    // place that counts every rating, comment or not, since Comments
    // itself now only ever shows the ones with a written comment. Every
    // rating is present in the result, zero-filled, so the chart never
    // has to guess whether a missing key means zero or "not fetched yet".
    public function ratingCounts($filters = array())
    {
        $this->applyFilters($filters);
        $this->db->select('rating, COUNT(*) AS count')->group_by('rating');
        $rows = $this->db->get($this->table)->result_array();
        $counts = array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0);
        foreach ($rows as $row) {
            $counts[(int) $row['rating']] = (int) $row['count'];
        }
        return $counts;
    }

    // Marks a row reviewed (now) or back to unreviewed (NULL), so an admin
    // can undo a misclick or flag something to come back to.
    public function setReviewed($id, $reviewed)
    {
        $this->db->where('id', $id)->update($this->table, array(
            'reviewed_at' => $reviewed ? date('Y-m-d H:i:s') : null,
        ));
        return $this->find($id);
    }

    // Powers a badge/count in the admin panel.
    public function countUnreviewed()
    {
        return (int) $this->db->where('reviewed_at', null)->count_all_results($this->table);
    }
}
