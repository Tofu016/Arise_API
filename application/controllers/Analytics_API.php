<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Behavioral analytics for the Analytics dashboard (formerly the plain
// Feedback admin page — comments/ratings still live in Feedback_API,
// this controller only owns session/event tracking). track() is public,
// like Feedback_API::submit(); every read below is admin-only.
class Analytics_API extends MY_Controller
{
    private $allowedEventTypes = array(
        'stage_reached', 'room_searched', 'go_to', 'directions_requested', 'move', 'feedback_submitted', 'session_end',
    );
    private $allowedStages = array('start', 'campus', 'building', 'floor', 'exploring');
    private $allowedMoveKinds = array('walk', 'jump');
    // 'inactivity_timeout' is deliberately absent: only Cron_API sets it.
    private $allowedEndReasons = array('feedback', 'idle_timeout');
    // The client flushes every 10 events or 15s, so a real batch is far
    // below this; anything bigger is dropped past the cap.
    const MAX_EVENTS_PER_BATCH = 50;
    // Matches the varchar(64) node id / campus / building columns.
    const MAX_ID_LENGTH = 64;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Analytics_Model');
    }

    // POST /Analytics_API/track — public, no auth (same reasoning as
    // Feedback_API::submit: a kiosk visitor has no account). Body:
    //   { session_id, platform: "kiosk"|"desktop", campus?, building?,
    //     events: [{ type, stage?, node_id?, from_node_id?, to_node_id?,
    //                room_query?, matched?, move_kind?, feedback_id?,
    //                reason? }, ...] }
    // Silently drops any event whose shape doesn't match instead of
    // failing the whole batch — one malformed event from a stale client
    // build shouldn't lose every other event in the same batch.
    public function track()
    {
        $data = $this->getInput();

        $sessionId = isset($data['session_id']) ? trim((string) $data['session_id']) : '';
        $platform = isset($data['platform']) ? $data['platform'] : '';
        // Client ids come from crypto.randomUUID(); the column is char(36).
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sessionId) === 1;
        if (!$isUuid || !in_array($platform, array('kiosk', 'desktop'), true)) {
            return Api_response::fail(400, 'A UUID session_id and a valid platform are required.');
        }

        $campus = $this->shortString($data, 'campus');
        $building = $this->shortString($data, 'building');
        $this->Analytics_Model->ensureSession($sessionId, $platform, $campus, $building);

        $rawEvents = isset($data['events']) && is_array($data['events'])
            ? array_slice($data['events'], 0, self::MAX_EVENTS_PER_BATCH)
            : array();
        $events = array();
        foreach ($rawEvents as $raw) {
            $normalized = $this->normalizeEvent($raw);
            if ($normalized) {
                $events[] = $normalized;
            }
        }

        if ($events) {
            $this->Analytics_Model->recordEvents($sessionId, $events);
        }

        return Api_response::ok();
    }

    private function normalizeEvent($raw)
    {
        if (!is_array($raw) || !isset($raw['type']) || !in_array($raw['type'], $this->allowedEventTypes, true)) {
            return null;
        }
        $stage = isset($raw['stage']) && in_array($raw['stage'], $this->allowedStages, true) ? $raw['stage'] : null;
        $moveKind = isset($raw['move_kind']) && in_array($raw['move_kind'], $this->allowedMoveKinds, true) ? $raw['move_kind'] : null;
        $reason = isset($raw['reason']) && in_array($raw['reason'], $this->allowedEndReasons, true) ? $raw['reason'] : null;
        $roomQuery = isset($raw['room_query']) && is_scalar($raw['room_query']) ? trim((string) $raw['room_query']) : '';

        return array(
            'event_type' => $raw['type'],
            'stage' => $stage,
            'node_id' => $this->shortString($raw, 'node_id'),
            'from_node_id' => $this->shortString($raw, 'from_node_id'),
            'to_node_id' => $this->shortString($raw, 'to_node_id'),
            'room_query' => $roomQuery !== '' ? mb_substr($roomQuery, 0, 255) : null,
            'matched' => isset($raw['matched']) ? (int) (bool) $raw['matched'] : null,
            'move_kind' => $moveKind,
            // Only read by Analytics_Model (feedback validation and
            // applySideEffects), not inserted as events columns.
            'feedback_id' => isset($raw['feedback_id']) && is_numeric($raw['feedback_id']) ? (int) $raw['feedback_id'] : null,
            'reason' => $reason,
        );
    }

    // A non-empty scalar no longer than the id/campus/building columns, or
    // null. Oversized values are dropped rather than silently truncated
    // into a different, wrong id.
    private function shortString($source, $key)
    {
        if (!isset($source[$key]) || !is_scalar($source[$key])) {
            return null;
        }
        $value = trim((string) $source[$key]);
        if ($value === '' || mb_strlen($value) > self::MAX_ID_LENGTH) {
            return null;
        }
        return $value;
    }

    private function filtersFromQuery()
    {
        return array(
            'from' => $this->input->get('from'),
            'to' => $this->input->get('to'),
            'platform' => $this->input->get('platform'),
            'building' => $this->input->get('building'),
        );
    }

    // GET /Analytics_API/summary — admin only. Query: from, to, platform, building.
    public function summary()
    {
        $this->requireAdmin();
        return Api_response::ok($this->Analytics_Model->summary($this->filtersFromQuery()));
    }

    // GET /Analytics_API/funnel — admin only. Same filters; platform
    // defaults to kiosk (see Analytics_Model::funnel).
    public function funnel()
    {
        $this->requireAdmin();
        return Api_response::ok(array('stages' => $this->Analytics_Model->funnel($this->filtersFromQuery())));
    }

    // GET /Analytics_API/rooms — admin only. Query adds limit (default 10).
    public function rooms()
    {
        $this->requireAdmin();
        $limit = $this->input->get('limit') ? (int) $this->input->get('limit') : 10;
        return Api_response::ok(array('rooms' => $this->Analytics_Model->rooms($this->filtersFromQuery(), $limit)));
    }

    // GET /Analytics_API/searches (admin only). Query adds limit (default 10).
    public function searches()
    {
        $this->requireAdmin();
        $limit = $this->input->get('limit') ? (int) $this->input->get('limit') : 10;
        return Api_response::ok($this->Analytics_Model->searches($this->filtersFromQuery(), $limit));
    }

    // GET /Analytics_API/routes — admin only. Query adds limit (default 10).
    public function routes()
    {
        $this->requireAdmin();
        $limit = $this->input->get('limit') ? (int) $this->input->get('limit') : 10;
        return Api_response::ok(array('routes' => $this->Analytics_Model->routes($this->filtersFromQuery(), $limit)));
    }

    // GET /Analytics_API/movement — admin only.
    public function movement()
    {
        $this->requireAdmin();
        return Api_response::ok($this->Analytics_Model->movement($this->filtersFromQuery()));
    }

    // GET /Analytics_API/trends — admin only.
    public function trends()
    {
        $this->requireAdmin();
        return Api_response::ok(array('series' => $this->Analytics_Model->trends($this->filtersFromQuery())));
    }

    // GET /Analytics_API/heatmap — admin only.
    public function heatmap()
    {
        $this->requireAdmin();
        return Api_response::ok($this->Analytics_Model->heatmap($this->filtersFromQuery()));
    }
}
