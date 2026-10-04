<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Two tables: analytics_sessions (one row per kiosk/web session, with a
// denormalized furthest_stage so the funnel query doesn't need to scan
// every event) and analytics_events (one row per tracked action). See
// DEPLOY.md's "Schema changes for a live database" entry for both.
//
// Room/route labels and building lat/lng are deliberately NOT resolved
// here — node_id/building are returned as-is and the frontend, which
// already has the full node/building list loaded, joins them locally.
// Same pattern useFeedback.js's own comment describes: rows are used as
// the backend returns them.
class Analytics_Model extends CI_Model
{
    private $sessions = 'analytics_sessions';
    private $events = 'analytics_events';

    // Stage order for funnel cumulative counts and for deciding whether an
    // incoming stage_reached event actually advances furthest_stage (a
    // late/duplicate event for a stage already passed must not regress it).
    private $stageOrder = array('start', 'campus', 'building', 'floor', 'exploring', 'feedback');

    public function __construct()
    {
        parent::__construct();
    }

    // Every timestamp in both tables is written from PHP (Asia/Manila, set in
    // MY_Controller and Cron_API) rather than left to MySQL's
    // CURRENT_TIMESTAMP default, whose timezone is the DB server's and may
    // differ. Mixing the two made durations negative or hours off.
    private function now()
    {
        return date('Y-m-d H:i:s');
    }

    // Creates the session row the first time it's seen; a later track()
    // call for the same id only touches campus/building (COALESCE-style:
    // never overwrites a known value with null).
    public function ensureSession($sessionId, $platform, $campus, $building, $hasGate = false)
    {
        $existing = $this->db->get_where($this->sessions, array('id' => $sessionId))->row_array();
        if (!$existing) {
            $this->db->insert($this->sessions, array(
                'id' => $sessionId,
                'platform' => $platform,
                'campus' => $campus,
                'building' => $building,
                'started_at' => $this->now(),
                // Only the Compact layout has the campus/building/floor gate
                // (see useKioskSession.js), whatever the platform: without
                // it a session is "exploring" from the first moment, so its
                // funnel baseline reflects that instead of sitting at
                // 'start' until a stage_reached event arrives.
                'furthest_stage' => $hasGate ? 'start' : 'exploring',
            ));
            return;
        }
        $patch = array();
        if ($campus !== null && empty($existing['campus'])) {
            $patch['campus'] = $campus;
        }
        if ($building !== null && empty($existing['building'])) {
            $patch['building'] = $building;
        }
        if ($patch) {
            $this->db->where('id', $sessionId)->update($this->sessions, $patch);
        }
    }

    // $events: list of normalized rows (see Analytics_API::track), already
    // validated. Inserted as-is, then each is given the chance to move the
    // session forward (furthest_stage, gave_feedback, ended_at). One
    // transaction per batch, so a failure mid-batch can't leave the events
    // table and the session row out of step.
    public function recordEvents($sessionId, array $events)
    {
        $this->db->trans_start();
        foreach ($events as $event) {
            if ($event['event_type'] === 'feedback_submitted' && !$this->isLinkableFeedback($sessionId, $event['feedback_id'])) {
                continue;
            }
            $this->db->insert($this->events, array(
                'session_id' => $sessionId,
                'event_type' => $event['event_type'],
                'stage' => $event['stage'],
                'node_id' => $event['node_id'],
                'from_node_id' => $event['from_node_id'],
                'to_node_id' => $event['to_node_id'],
                'room_query' => $event['room_query'],
                'matched' => $event['matched'],
                'move_kind' => $event['move_kind'],
                'created_at' => $this->now(),
            ));
            $this->applySideEffects($sessionId, $event);
        }
        $this->db->trans_complete();
    }

    // track() is public, so a feedback_submitted event is only trusted when
    // it points at a real, recent app_feedback row that no other session has
    // already claimed. Otherwise anyone could inflate the feedback rate.
    private function isLinkableFeedback($sessionId, $feedbackId)
    {
        if (!$feedbackId) {
            return false;
        }
        $exists = $this->db
            ->where('id', $feedbackId)
            ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->count_all_results('app_feedback');
        if (!$exists) {
            return false;
        }
        $claimedElsewhere = $this->db
            ->where('feedback_id', $feedbackId)
            ->where('id !=', $sessionId)
            ->count_all_results($this->sessions);
        return $claimedElsewhere === 0;
    }

    private function applySideEffects($sessionId, $event)
    {
        if ($event['event_type'] === 'stage_reached' && $event['stage']) {
            $this->advanceStage($sessionId, $event['stage']);
        }

        // Doesn't end the session: a kiosk visitor can tap "Keep exploring"
        // after rating, so the session really ends on the client's
        // session_end (sent on reset, or on the web layout right before its
        // session id rotates).
        if ($event['event_type'] === 'feedback_submitted') {
            $this->advanceStage($sessionId, 'feedback');
            $this->db->where('id', $sessionId)->update($this->sessions, array(
                'gave_feedback' => 1,
                'feedback_id' => $event['feedback_id'],
            ));
        }

        if ($event['event_type'] === 'session_end') {
            $this->db->where('id', $sessionId)->where('ended_at', null)->update($this->sessions, array(
                'ended_at' => $this->now(),
                'end_reason' => $event['reason'] ?: 'idle_timeout',
            ));
        }
    }

    private function advanceStage($sessionId, $stage)
    {
        $rank = array_search($stage, $this->stageOrder, true);
        if ($rank === false) {
            return;
        }
        $session = $this->db->get_where($this->sessions, array('id' => $sessionId))->row_array();
        if (!$session) {
            return;
        }
        $currentRank = array_search($session['furthest_stage'], $this->stageOrder, true);
        if ($currentRank === false || $rank > $currentRank) {
            $patch = array('furthest_stage' => $stage);
            // A gated session row is created the moment the attract screen
            // mounts, which can be hours before anyone walks up. Restarting
            // the clock on the first real tap keeps that idle wait out of
            // durations, trends and the date filter.
            if ($session['furthest_stage'] === 'start') {
                $patch['started_at'] = $this->now();
            }
            $this->db->where('id', $sessionId)->update($this->sessions, $patch);
        }
    }

    // Shared WHERE clause (date range, platform, building) for every read
    // endpoint below. $alias lets the same helper work whether the caller
    // is querying analytics_sessions directly or joining through it.
    private function applyFilters($filters, $alias = 'analytics_sessions')
    {
        if (!empty($filters['from'])) {
            $this->db->where("{$alias}.started_at >=", $filters['from'] . ' 00:00:00');
        }
        if (!empty($filters['to'])) {
            $this->db->where("{$alias}.started_at <=", $filters['to'] . ' 23:59:59');
        }
        if (!empty($filters['platform'])) {
            $this->db->where("{$alias}.platform", $filters['platform']);
        }
        // A session matches a building if it started there (kiosk pick /
        // web filter) or walked/jumped into any node in it, so sessions
        // that crossed buildings aren't attributed to their first one only.
        if (!empty($filters['building'])) {
            $building = $this->db->escape($filters['building']);
            $this->db->where(
                "({$alias}.building = {$building} OR {$alias}.id IN ("
                . "SELECT fe.session_id FROM {$this->events} fe JOIN nodes fn ON fn.id = fe.to_node_id"
                . " WHERE fe.event_type = 'move' AND fn.building = {$building}))",
                null,
                false
            );
        }
    }

    public function summary($filters)
    {
        $this->db->select('COUNT(*) AS session_count, SUM(gave_feedback) AS feedback_count');
        $this->applyFilters($filters);
        $row = $this->db->get($this->sessions)->row_array();
        $sessionCount = (int) $row['session_count'];
        $feedbackCount = (int) $row['feedback_count'];

        // Kiosk sessions still on the attract screen had no visitor, so they
        // have no meaningful duration.
        $this->db->select('AVG(TIMESTAMPDIFF(SECOND, started_at, ended_at)) AS avg_duration');
        $this->applyFilters($filters);
        $this->db->where('ended_at IS NOT NULL', null, false);
        $this->db->where('furthest_stage !=', 'start');
        $durationRow = $this->db->get($this->sessions)->row_array();

        $movement = $this->movementTotals($filters);

        return array(
            'sessionCount' => $sessionCount,
            'avgDurationSeconds' => $durationRow['avg_duration'] !== null ? round((float) $durationRow['avg_duration'], 1) : null,
            'feedbackRate' => $sessionCount > 0 ? round($feedbackCount / $sessionCount, 4) : 0,
            'walkCount' => $movement['walk'],
            'jumpCount' => $movement['jump'],
        );
    }

    // See Analytics_Model's stageOrder comment: a web session has no real
    // campus/building/floor gate to drop out at, so its funnel is the two
    // stages that actually apply. A kiosk (or unfiltered/default) funnel
    // gets the full sequence.
    public function funnel($filters)
    {
        $isWeb = isset($filters['platform']) && $filters['platform'] === 'web';
        $stages = $isWeb ? array('exploring', 'feedback') : array('start', 'campus', 'building', 'floor', 'exploring', 'feedback');
        // filtersFromQuery() always sets the key (null when absent), so an
        // array union (+) would never apply this default.
        if (!$isWeb) {
            $filters['platform'] = 'kiosk';
        }

        $this->db->select('furthest_stage, COUNT(*) AS count');
        $this->applyFilters($filters);
        $this->db->group_by('furthest_stage');
        $rows = $this->db->get($this->sessions)->result_array();

        $countsByStage = array();
        foreach ($rows as $row) {
            $countsByStage[$row['furthest_stage']] = (int) $row['count'];
        }

        // Cumulative: a session at furthest_stage X counts toward every
        // stage up to and including X.
        $result = array();
        foreach ($stages as $i => $stage) {
            $count = 0;
            foreach ($stages as $j => $laterStage) {
                if ($j >= $i && isset($countsByStage[$laterStage])) {
                    $count += $countsByStage[$laterStage];
                }
            }
            $result[] = array('stage' => $stage, 'count' => $count);
        }
        return $result;
    }

    // Destinations: go_to carries node_id, directions_requested carries only
    // to_node_id, so both are folded into one column here.
    public function rooms($filters, $limit)
    {
        $destination = 'COALESCE(analytics_events.node_id, analytics_events.to_node_id)';
        $this->db->select("{$destination} AS node_id, COUNT(*) AS count", false);
        $this->db->from($this->events);
        $this->db->join($this->sessions, "{$this->sessions}.id = {$this->events}.session_id");
        $this->db->where_in('event_type', array('go_to', 'directions_requested'));
        $this->db->where("{$destination} IS NOT NULL", null, false);
        $this->applyFilters($filters);
        $this->db->group_by($destination, false);
        $this->db->order_by('count', 'DESC');
        $this->db->limit($limit);
        return $this->withIntCounts($this->db->get()->result_array());
    }

    private function withIntCounts(array $rows)
    {
        foreach ($rows as &$row) {
            $row['count'] = (int) $row['count'];
        }
        return $rows;
    }

    // What visitors typed into room search: the top queries, and how often a
    // search found no room at all. Case-folded so "Library"/"library" count
    // as one query.
    public function searches($filters, $limit)
    {
        $this->db->select('LOWER(analytics_events.room_query) AS query, COUNT(*) AS count, SUM(analytics_events.matched) AS matched_count', false);
        $this->db->from($this->events);
        $this->db->join($this->sessions, "{$this->sessions}.id = {$this->events}.session_id");
        $this->db->where('event_type', 'room_searched');
        $this->db->where('analytics_events.room_query IS NOT NULL', null, false);
        $this->applyFilters($filters);
        $this->db->group_by('LOWER(analytics_events.room_query)', false);
        $this->db->order_by('count', 'DESC');
        $this->db->limit($limit);
        $queries = $this->db->get()->result_array();

        $this->db->select('COUNT(*) AS total, SUM(analytics_events.matched = 0) AS unmatched', false);
        $this->db->from($this->events);
        $this->db->join($this->sessions, "{$this->sessions}.id = {$this->events}.session_id");
        $this->db->where('event_type', 'room_searched');
        $this->applyFilters($filters);
        $totals = $this->db->get()->row_array();
        $total = (int) $totals['total'];

        return array(
            'queries' => array_map(function ($row) {
                return array(
                    'query' => $row['query'],
                    'count' => (int) $row['count'],
                    'matched' => (int) $row['matched_count'] > 0,
                );
            }, $queries),
            'total' => $total,
            'noMatchRate' => $total > 0 ? round((int) $totals['unmatched'] / $total, 4) : 0,
        );
    }

    public function routes($filters, $limit)
    {
        $this->db->select('from_node_id, to_node_id, COUNT(*) AS count');
        $this->db->from($this->events);
        $this->db->join($this->sessions, "{$this->sessions}.id = {$this->events}.session_id");
        $this->db->where('event_type', 'directions_requested');
        $this->applyFilters($filters);
        $this->db->group_by('from_node_id, to_node_id');
        $this->db->order_by('count', 'DESC');
        $this->db->limit($limit);
        return $this->withIntCounts($this->db->get()->result_array());
    }

    private function movementTotals($filters)
    {
        $this->db->select('move_kind, COUNT(*) AS count');
        $this->db->from($this->events);
        $this->db->join($this->sessions, "{$this->sessions}.id = {$this->events}.session_id");
        $this->db->where('event_type', 'move');
        $this->applyFilters($filters);
        $this->db->group_by('move_kind');
        $rows = $this->db->get()->result_array();

        $totals = array('walk' => 0, 'jump' => 0);
        foreach ($rows as $row) {
            $totals[$row['move_kind']] = (int) $row['count'];
        }
        return $totals;
    }

    public function movement($filters)
    {
        $totals = $this->movementTotals($filters);

        $this->db->select("DATE(analytics_events.created_at) AS date, move_kind, COUNT(*) AS count");
        $this->db->from($this->events);
        $this->db->join($this->sessions, "{$this->sessions}.id = {$this->events}.session_id");
        $this->db->where('event_type', 'move');
        $this->applyFilters($filters);
        $this->db->group_by('date, move_kind');
        $this->db->order_by('date', 'ASC');
        $rows = $this->db->get()->result_array();

        $byDate = array();
        foreach ($rows as $row) {
            if (!isset($byDate[$row['date']])) {
                $byDate[$row['date']] = array('date' => $row['date'], 'walk' => 0, 'jump' => 0);
            }
            $byDate[$row['date']][$row['move_kind']] = (int) $row['count'];
        }

        return array('totals' => $totals, 'series' => array_values($byDate));
    }

    public function trends($filters)
    {
        $this->db->select("DATE(started_at) AS date, COUNT(*) AS sessions, SUM(gave_feedback) AS feedback_count");
        $this->applyFilters($filters);
        $this->db->group_by('date');
        $this->db->order_by('date', 'ASC');
        $rows = $this->db->get($this->sessions)->result_array();

        $series = array();
        foreach ($rows as $row) {
            $sessions = (int) $row['sessions'];
            $feedbackCount = (int) $row['feedback_count'];
            $series[] = array(
                'date' => $row['date'],
                'sessions' => $sessions,
                'feedbackRate' => $sessions > 0 ? round($feedbackCount / $sessions, 4) : 0,
            );
        }
        return $series;
    }

    // Web sessions have no explicit end event (see useAnalytics.js's
    // own comment on why: no reliable unload signal) — a session is
    // considered abandoned once it's gone $minutes without a new event
    // and still has no ended_at. Run from Cron_API::closeStaleSessions;
    // paired-kiosk sessions are excluded since idle_timeout/feedback
    // already close those client-side and a kiosk left mid-flyover
    // shouldn't get silently closed under it.
    // ended_at is the session's last activity, not the time this cron run
    // happened to notice it; otherwise a once-a-day cron would stretch
    // web durations by up to a day.
    public function closeStaleSessions($minutes = 30)
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));

        $this->db->select('analytics_sessions.id, MAX(analytics_events.created_at) AS last_event_at, analytics_sessions.started_at');
        $this->db->from($this->sessions);
        $this->db->join($this->events, "{$this->events}.session_id = {$this->sessions}.id", 'left');
        $this->db->where('analytics_sessions.platform', 'web');
        $this->db->where('analytics_sessions.ended_at', null);
        $this->db->group_by('analytics_sessions.id');
        $rows = $this->db->get()->result_array();

        $closed = 0;
        foreach ($rows as $row) {
            $lastActivity = $row['last_event_at'] ?: $row['started_at'];
            if ($lastActivity < $cutoff) {
                $this->db->where('id', $row['id'])->update($this->sessions, array(
                    'ended_at' => $lastActivity,
                    'end_reason' => 'inactivity_timeout',
                ));
                $closed++;
            }
        }
        return $closed;
    }

    public function heatmap($filters)
    {
        // Arrivals (walks and jumps) per building, from the destination
        // node's own building, so a session that crosses buildings counts
        // toward each one it actually visited.
        $this->db->select('arrival_node.building AS building, COUNT(*) AS count');
        $this->db->from($this->events);
        $this->db->join($this->sessions, "{$this->sessions}.id = {$this->events}.session_id");
        $this->db->join('nodes arrival_node', "arrival_node.id = {$this->events}.to_node_id");
        $this->db->where('event_type', 'move');
        $this->applyFilters($filters);
        $this->db->group_by('arrival_node.building');
        $this->db->order_by('count', 'DESC');
        $buildings = $this->db->get()->result_array();

        // Timing grid: day-of-week (0=Sunday) x hour, from every event
        // (not just sessions), so it reflects actual activity volume. The
        // attract screen's own 'start' event fires on a kiosk reset, with no
        // visitor present, so it's left out.
        $this->db->select("DAYOFWEEK(analytics_events.created_at) - 1 AS dow, HOUR(analytics_events.created_at) AS hour, COUNT(*) AS count");
        $this->db->from($this->events);
        $this->db->join($this->sessions, "{$this->sessions}.id = {$this->events}.session_id");
        $this->db->where("(analytics_events.event_type <> 'stage_reached' OR analytics_events.stage IS NULL OR analytics_events.stage <> 'start')", null, false);
        $this->applyFilters($filters);
        $this->db->group_by('dow, hour');
        $timing = $this->db->get()->result_array();

        return array(
            'buildings' => array_map(function ($row) {
                return array('building' => $row['building'], 'count' => (int) $row['count']);
            }, $buildings),
            'timing' => array_map(function ($row) {
                return array('dow' => (int) $row['dow'], 'hour' => (int) $row['hour'], 'count' => (int) $row['count']);
            }, $timing),
        );
    }
}
