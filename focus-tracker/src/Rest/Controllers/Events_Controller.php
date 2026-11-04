<?php
declare(strict_types=1);

namespace FocusTracker\Rest\Controllers;

use FocusTracker\Tracking\Activity_Window_Gate;
use FocusTracker\Tracking\Page_Context;
use FocusTracker\Database\Event_Repository;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Database\Score_Repository;
use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Diagnostics;
use FocusTracker\Support\Request_Limiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Ingests batched client events from resources/js/tracker.js (see
 * docs/DESIGN.md §3, §6). permission_callback deliberately does NOT rely on
 * the X-WP-Nonce header — navigator.sendBeacon() can't set custom headers,
 * so the client puts the nonce in the URL as `_wpnonce` instead.
 */
final class Events_Controller {
    private const MAX_EVENTS_PER_BATCH = 500;
    private const CLOCK_SKEW_BEFORE_MS = 5000;
    private const CLOCK_SKEW_AFTER_MS = 30000;
    private const MAX_QUEUE_AGE_MS = 86400000;

    public static function register_routes(): void {
        register_rest_route('focus-tracker/v1', '/events', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'handle'),
            'permission_callback' => array(__CLASS__, 'authorize'),
        ));
    }

    public static function authorize(WP_REST_Request $request): bool|WP_Error {
        if (! is_user_logged_in()) {
            return new WP_Error('focus_tracker_unauthenticated', __('로그인이 필요합니다.', 'focus-tracker'), array('status' => 401));
        }

        if (! Capabilities::can_participate()) {
            return new WP_Error('focus_tracker_forbidden', __('추적 대상이 아닙니다.', 'focus-tracker'), array('status' => 403));
        }
        $size = Request_Limiter::body_limit($request, 524288);
        if ($size !== null) { return $size; }

        // The nonce travels as a `_wpnonce` query-string parameter, not the
        // X-WP-Nonce header — navigator.sendBeacon() can't set custom
        // headers, and WordPress core's own cookie-auth nonce check
        // (rest_cookie_check_errors()) already looks at $_REQUEST['_wpnonce']
        // before this callback even runs, so the query string satisfies
        // both core's check and this explicit one. See docs/DESIGN.md §3.3.
        $nonce = (string) $request->get_param('_wpnonce');
        if (false === wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('focus_tracker_bad_nonce', __('요청을 검증할 수 없습니다.', 'focus-tracker'), array('status' => 403));
        }

        return true;
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $size = Request_Limiter::body_limit($request, 524288);
        if ($size !== null) { Diagnostics::record('ingest', 'invalid'); return $size; }
        $limit = Request_Limiter::check(get_current_user_id(), 'ingest');
        if ($limit !== null) { Diagnostics::record('ingest', $limit->get_error_code() === 'focus_tracker_rate_limited' ? 'limited' : 'store'); return $limit; }
        $result = self::process($request);
        if ($result instanceof WP_Error) {
            $status = (int) ($result->get_error_data()['status'] ?? 500);
            Diagnostics::record('ingest', $status >= 500 ? 'store' : ($status === 403 ? 'access' : 'invalid'));
        } else {
            Diagnostics::record('ingest', empty($result->get_data()['stored']) && ! empty($result->get_data()['policyDropped']) ? 'inactive' : 'success');
        }
        return $result;
    }

    private static function process(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = self::json_body($request);
        $uid = $params['session_uid'] ?? null;
        $source_start = filter_var($params['session_started_at'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        $raw = $params['events'] ?? null;
        $type = $params['context_type'] ?? 'general';
        $context = $params['context_id'] ?? null;
        $now = Clock::now_ms();
        if (! is_string($uid) || $uid === '' || strlen($uid) > 64 || preg_match('/^[A-Za-z0-9_-]+$/D', $uid) !== 1
            || $source_start === false || $source_start > $now + self::CLOCK_SKEW_AFTER_MS
            || ! is_array($raw) || ! array_is_list($raw) || count($raw) < 1 || count($raw) > self::MAX_EVENTS_PER_BATCH
            || ! is_string($type) || ($context !== null && ! is_string($context) && ! is_int($context))) {
            return new WP_Error('focus_tracker_invalid_payload', '잘못된 요청입니다.', array('status' => 400));
        }
        $user = get_current_user_id();
        if (isset($params['user_id']) && (int) $params['user_id'] !== $user) {
            return new WP_Error('focus_tracker_account_changed', '로그인 계정이 변경되어 이전 계정의 기록을 저장하지 않았습니다.', array('status' => 403));
        }
        $page = Page_Context::from_payload($params, $user, $now, true);
        if ($page instanceof WP_Error) { return $page; }
        $problem = $page['problemId'];
        $page_context = Page_Context::context($page, $type, $context);
        if ($page_context instanceof WP_Error) { return $page_context; }
        $type = $page_context['type']; $context = $page_context['id'];
        // Reject a collision with a pre-gate legacy session as well. New rows
        // have deterministic, scope-bound logical segment identifiers below.
        $legacy = Participation_Repository::find_by_session_uid($uid);
        if ($legacy !== null && (int) $legacy['user_id'] !== $user) { return new WP_Error('focus_tracker_forbidden', '본인 세션이 아닙니다.', array('status' => 403)); }
        $minimum = max($source_start - self::CLOCK_SKEW_BEFORE_MS, $now - self::MAX_QUEUE_AGE_MS);
        $privacy_cutoff = \FocusTracker\Privacy\Privacy_Guard::cutoff($user);
        if ($privacy_cutoff === PHP_INT_MAX) { return self::store_failed(); }
        $privacy_dropped = 0;
        $events = array();
        $packet_sequences = array();
        $source_latest = $source_start;
        foreach ($raw as $event) {
            if (! is_array($event) || ! is_string($event['type'] ?? null) || ! in_array($event['type'], Event_Repository::VALID_TYPES, true)) { return self::invalid_event(); }
            $at = filter_var($event['occurred_at'] ?? null, FILTER_VALIDATE_INT);
            $seq = filter_var($event['seq'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 2147483647)));
            if ($at === false || $seq === false || $at < $minimum || $at > $now + self::CLOCK_SKEW_AFTER_MS) { return self::invalid_event(); }
            if (isset($packet_sequences[$seq])) { continue; }
            $packet_sequences[$seq] = true;
            if ($page['version'] > 0 && ($at < $page['issuedAtMs'] || ($event['type'] === 'session_end' ? $at > $page['expiresAtMs'] : $at >= $page['expiresAtMs']))) { ++$privacy_dropped; continue; }
            if ($at <= $privacy_cutoff) { ++$privacy_dropped; continue; }
            $events[] = array('type' => $event['type'], 'occurred_at' => $at, 'seq' => $seq,
                'meta' => isset($event['meta']) && is_array($event['meta']) ? self::sanitize_meta($event['meta']) : null);
            $source_latest = max($source_latest, $at);
        }
        $windows = Activity_Window_Gate::windows($user, $problem, $now - self::MAX_QUEUE_AGE_MS, $now + self::MAX_QUEUE_AGE_MS, $type, $context, false, $page['version'] > 0);
        if ($windows instanceof WP_Error) { return $windows; }
        $runs = Activity_Window_Gate::union($windows);
        $partition = self::partition_events($events, $runs, $source_start, $now);
        $allowed = $partition['allowed'];
        $dropped = $privacy_dropped + $partition['dropped'];
        if (empty($allowed)) { return self::acknowledge(0, $dropped, array()); }
        $scope_id = $context === null ? '' : (string) $context;
        if ($type !== 'general') {
            foreach ($windows as $window) {
                if ($window['contextType'] === $type && Activity_Window_Gate::same_context($type, $window['contextId'], $scope_id)) { $scope_id = $window['contextId']; break; }
            }
        }
        // The stable prefix identifies the physical source across subsequent
        // schedule edits; only the suffix identifies its logical window run.
        $identity = array($uid, $source_start, $problem, $type, $scope_id);
        if ($privacy_cutoff > 0) { $identity[] = $privacy_cutoff; }
        if ($page['version'] > 0) { $identity[] = array('page-v1', $user, Page_Context::columns($page)); }
        $source_prefix = 'g6-' . substr(hash('sha256', (string) wp_json_encode($identity)), 0, 32) . '-';
        global $wpdb;
        if (false === $wpdb->query('START TRANSACTION')) { return self::store_failed(); }
        if (! \FocusTracker\Privacy\Privacy_Guard::lock($user)) { $wpdb->query('ROLLBACK'); return \FocusTracker\Privacy\Privacy_Guard::error() ?? self::store_failed(); }
        if (\FocusTracker\Privacy\Privacy_Guard::cutoff($user) !== $privacy_cutoff) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
        $stored = 0;
        $ids = array();
        $invalidations = array();
        try {
            // Re-read the schedule under the write transaction. A closed or
            // shortened schedule must win over an earlier optimistic read.
            $fresh = Activity_Window_Gate::windows($user, $problem, $now - self::MAX_QUEUE_AGE_MS, $now + self::MAX_QUEUE_AGE_MS, $type, $context, true, $page['version'] > 0);
            if ($fresh instanceof WP_Error) { $wpdb->query('ROLLBACK'); return $fresh; }
            if (wp_json_encode($fresh) !== wp_json_encode($windows)) {
                // A legitimate teacher edit is a new collection policy, not a
                // storage outage. Repartition the original validated packet
                // under the current locked windows; keep every valid pre-end
                // observation and acknowledge excluded observations once.
                $windows = $fresh;
                $runs = Activity_Window_Gate::union($windows);
                $partition = self::partition_events($events, $runs, $source_start, $now);
                $allowed = $partition['allowed'];
                $dropped = $privacy_dropped + $partition['dropped'];
                if (empty($allowed)) {
                    if (false === $wpdb->query('COMMIT')) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
                    return self::acknowledge(0, $dropped, array());
                }
            }
            // The learner's privacy lock already serializes their ingests.
            // Its subsequent cutoff read opens an RR snapshot after any prior
            // ingest committed. Do not lock this prefix range: an empty range
            // can share an index gap with another learner's new session and
            // deadlock when both requests insert their independent rows.
            $source_rows = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . \FocusTracker\Database\Installer::participations_table()
                . ' WHERE session_uid >= %s AND session_uid < %s ORDER BY id ASC',
                $source_prefix, $source_prefix . 'g'
            ), ARRAY_A);
            if (! is_array($source_rows) || '' !== (string) $wpdb->last_error) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
            if (! empty($source_rows)) {
                foreach ($source_rows as $source_row) {
                    if ((int) $source_row['user_id'] !== $user) { $wpdb->query('ROLLBACK'); return new WP_Error('focus_tracker_forbidden', '본인 세션이 아닙니다.', array('status' => 403)); }
                }
                // Retention also updates old participation metadata. Lock only
                // these known primary keys, never an absent source/index gap,
                // and use their current expiry state rather than the RR copy.
                $source_ids = array_map('intval', array_column($source_rows, 'id'));
                sort($source_ids, SORT_NUMERIC);
                $current_rows = array();
                foreach ($source_ids as $source_id) {
                    // Single unique-key equality keeps this a point lock even
                    // when MySQL would choose a full scan for an IN list.
                    $current_row = $wpdb->get_row($wpdb->prepare(
                        'SELECT * FROM ' . \FocusTracker\Database\Installer::participations_table() . ' WHERE id = %d FOR UPDATE', $source_id
                    ), ARRAY_A);
                    if (! is_array($current_row) || '' !== (string) $wpdb->last_error) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
                    $current_rows[] = $current_row;
                }
                $source_rows = $current_rows;
            }
            $source_by_uid = array();
            foreach ($source_rows as $source_row) {
                if ((int) $source_row['user_id'] !== $user) { $wpdb->query('ROLLBACK'); return new WP_Error('focus_tracker_forbidden', '본인 세션이 아닙니다.', array('status' => 403)); }
                foreach (array('id', 'user_id', 'started_at_ms') as $key) { $source_row[$key] = (int) $source_row[$key]; }
                $source_row['problem_id'] = $source_row['problem_id'] === null ? null : (int) $source_row['problem_id'];
                $source_row['page_context_version'] = (int) ($source_row['page_context_version'] ?? 0);
                $source_row['ended_at_ms'] = $source_row['ended_at_ms'] === null ? null : (int) $source_row['ended_at_ms'];
                $source_by_uid[$source_row['session_uid']] = $source_row;
            }
            $seen = array();
            $controls = array();
            $linked = array();
            $source_ids = array_map('intval', array_column(array_filter($source_rows, static fn (array $row): bool => empty($row['raw_events_expired'])), 'id'));
            if (! empty($source_ids)) {
                $links = $wpdb->get_results($wpdb->prepare('SELECT participation_id, activity_id FROM ' . \FocusTracker\Database\Installer::activity_links_table()
                    . ' WHERE participation_id IN (' . implode(',', array_fill(0, count($source_ids), '%d')) . ')', $source_ids), ARRAY_A);
                if (! is_array($links) || '' !== (string) $wpdb->last_error) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
                foreach ($links as $link) { $linked[(int) $link['participation_id']][(int) $link['activity_id']] = true; }
                $incoming_sequences = array_values(array_unique(array_merge(array(0, 2147483648), array_column($events, 'seq'))));
                $seen_rows = $wpdb->get_results($wpdb->prepare(
                    'SELECT participation_id, sequence_number FROM ' . \FocusTracker\Database\Installer::events_table()
                    . ' WHERE participation_id IN (' . implode(',', array_fill(0, count($source_ids), '%d')) . ')'
                    . ' AND sequence_number IN (' . implode(',', array_fill(0, count($incoming_sequences), '%d')) . ')',
                    array_merge($source_ids, $incoming_sequences)
                ), ARRAY_A);
                if (! is_array($seen_rows) || '' !== (string) $wpdb->last_error) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
                foreach ($seen_rows as $seen_row) {
                    $sequence = (int) $seen_row['sequence_number']; $seen_id = (int) $seen_row['participation_id'];
                    if ($sequence === 0 || $sequence === 2147483648) { $controls[$seen_id][$sequence] = true; }
                    else { $seen[$sequence] = $seen_id; }
                }
            }
            foreach ($allowed as $index => $batch) {
                $run = $runs[$index];
                $logical_start = max($source_start, $run['startMs'], $privacy_cutoff > 0 ? $privacy_cutoff + 1 : 0, $page['issuedAtMs']);
                $segment_uid = $source_prefix . substr(hash('sha256', (string) $run['startMs']), 0, 28);
                $participation = $source_by_uid[$segment_uid] ?? null;
                if ($participation !== null && ! empty($participation['raw_events_expired'])) { $dropped += count($batch); continue; }
                $batch = array_values(array_filter($batch, static function (array $event) use ($seen, &$ids): bool {
                    if (! isset($seen[$event['seq']])) { return true; }
                    $ids[] = $seen[$event['seq']];
                    return false;
                }));
                // Existing rows still need final/deadline/link metadata applied
                // on a replay. A moved, absent segment must not be created from
                // sequences already stored in a different logical run.
                if ($participation === null && empty($batch)) { continue; }
                $inside = array_filter($batch, static fn (array $event): bool => $event['occurred_at'] < $run['endMs']);
                if ($participation === null && empty($inside)) { $dropped += count($batch); continue; }
                if ($participation === null) {
                    $participation = Participation_Repository::find_or_create(array('activity_id' => null, 'user_id' => $user,
                        'problem_id' => $problem, 'session_uid' => $segment_uid, 'started_at_ms' => $logical_start) + Page_Context::columns($page));
                }
                if ($participation === null) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
                if ($participation['user_id'] !== $user) { $wpdb->query('ROLLBACK'); return new WP_Error('focus_tracker_forbidden', '본인 세션이 아닙니다.', array('status' => 403)); }
                if ($participation['problem_id'] !== $problem || $participation['started_at_ms'] !== $logical_start) {
                    $wpdb->query('ROLLBACK'); return new WP_Error('focus_tracker_session_mismatch', '세션 정보가 일치하지 않습니다.', array('status' => 400));
                }
                foreach (Page_Context::columns($page) as $column => $value) {
                    if (($participation[$column] ?? ($column === 'page_category' ? 'unclassified' : ($column === 'page_context_version' ? 0 : null))) !== $value) {
                        $wpdb->query('ROLLBACK'); return new WP_Error('focus_tracker_page_mismatch', '기록 중인 페이지 정보가 일치하지 않습니다.', array('status' => 400));
                    }
                }
                $id = (int) $participation['id'];
                // The synthetic start does not assert visibility before the
                // first real, permitted snapshot. Zero is reserved for it.
                // Never send existing controls back through ON DUPLICATE KEY:
                // duplicate unique-key updates can lock the InnoDB supremum.
                if (empty($controls[$id][0])) {
                    $batch[] = array('type' => 'session_start', 'occurred_at' => $logical_start, 'seq' => 0,
                        'meta' => array('visible' => false, 'focused' => false));
                }
                if ($now >= $run['endMs'] && empty($controls[$id][2147483648])) {
                    $batch[] = array('type' => 'session_end', 'occurred_at' => $run['endMs'], 'seq' => 2147483648,
                        'meta' => array('visible' => false, 'focused' => false));
                }
                $inserted = Event_Repository::insert_batch($id, $batch);
                if ($inserted === false) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
                // The privacy guard precedes this transaction's RR snapshot,
                // so previous ingests and our own inserts are already visible.
                // An aggregate locking read can scan/lock an index supremum
                // and deadlock independent learners' event INSERTs.
                $summary = $wpdb->get_row($wpdb->prepare(
                    'SELECT MAX(occurred_at_ms) AS latest_at,
                        MAX(CASE WHEN event_type = %s THEN occurred_at_ms ELSE NULL END) AS ended_at,
                        MIN(CASE WHEN event_type = %s AND sequence_number > 0 AND sequence_number < 2147483648 THEN occurred_at_ms ELSE NULL END) AS real_ended_at,
                        MAX(CASE WHEN sequence_number > 0 AND sequence_number < 2147483648 THEN occurred_at_ms ELSE NULL END) AS real_latest_at,
                        MAX(CASE WHEN meta LIKE %s THEN 1 ELSE 0 END) AS has_gaps'
                    . ' FROM ' . \FocusTracker\Database\Installer::events_table()
                    . ' WHERE participation_id = %d', 'session_end', 'session_end', '%"queue_dropped":1%', $id
                ), ARRAY_A);
                if (! is_array($summary) || $summary['latest_at'] === null || '' !== (string) $wpdb->last_error) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
                $end = $run['endMs'];
                if ($summary['ended_at'] !== null) { $end = min($end, (int) $summary['ended_at']); }
                if ($summary['real_ended_at'] !== null) { $end = min($end, (int) $summary['real_ended_at']); }
                if (! empty($params['is_final'])) { $end = min($end, max($source_latest, (int) $summary['real_latest_at']), $now); }
                if ($participation['end_reason'] === Participation_Repository::END_REASON_PAGEHIDE && $participation['ended_at_ms'] !== null) {
                    $end = min($end, max((int) $participation['ended_at_ms'], (int) $summary['real_latest_at']));
                }
                // The deadline is a metadata bound, not a future observation.
                // It prevents the scorer's heartbeat grace crossing a schedule
                // boundary if the browser's final control packet is lost.
                $changed = Participation_Repository::record_ingestion_state($participation, $end,
                    $end === $run['endMs'] ? 'activity_window' : Participation_Repository::END_REASON_PAGEHIDE,
                    min((int) $summary['latest_at'], $run['endMs'], $now), $inserted, ! empty($summary['has_gaps']));
                if ($changed === null) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
                foreach ($windows as $window) {
                    if (in_array($window['activityId'], $run['activityIds'], true)) {
                        $new_link = empty($linked[$id][$window['activityId']]);
                        if ($new_link && ! Participation_Repository::link_activity($id, $window['activityId'])) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
                        $linked[$id][$window['activityId']] = true;
                        if ($changed || $new_link) { $invalidations[$window['activityId']] = true; }
                    }
                }
                if ('' !== (string) $wpdb->last_error) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
                $stored += $inserted;
                $ids[] = $id;
                foreach ($batch as $event) { if ($event['seq'] > 0 && $event['seq'] < 2147483648) { $seen[$event['seq']] = $id; } }
            }
            if (! Score_Repository::invalidate_for_user_activities($user, array_keys($invalidations))) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
            if (false === $wpdb->query('COMMIT')) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); return self::store_failed(); }
        return self::acknowledge($stored, $dropped, array_values(array_unique($ids)));
    }

    /** @return array{allowed:array, dropped:int} */
    private static function partition_events(array $events, array $runs, int $source_start, int $now): array {
        $allowed = array(); $dropped = 0;
        foreach ($events as $event) {
            $matched = false;
            foreach ($runs as $index => $run) {
                if ($event['occurred_at'] <= $now && $event['occurred_at'] >= max($source_start, $run['startMs'])
                    && ($event['occurred_at'] < $run['endMs'] || ($event['type'] === 'session_end' && $event['occurred_at'] === $run['endMs']))) {
                    $allowed[$index][] = $event; $matched = true; break;
                }
            }
            if (! $matched) { ++$dropped; }
        }
        return array('allowed' => $allowed, 'dropped' => $dropped);
    }

    private static function acknowledge(int $stored, int $dropped, array $ids): WP_REST_Response {
        return rest_ensure_response(array('stored' => $stored, 'acknowledged' => true,
            'policyDropped' => $dropped, 'policy_dropped' => $dropped,
            'participation_id' => $ids[0] ?? null, 'participation_ids' => $ids));
    }
    /** @return array<string, mixed> */
    private static function sanitize_meta(array $meta): array {
        $clean = array();
        foreach (array('visible', 'focused') as $key) {
            if (isset($meta[$key]) && is_bool($meta[$key])) {
                $clean[$key] = $meta[$key];
            }
        }
        foreach (array('len_delta', 'queue_dropped') as $key) {
            if (isset($meta[$key]) && (is_numeric($meta[$key]) || is_bool($meta[$key]))) {
                $clean[$key] = max(-10000000, min(10000000, (int) $meta[$key]));
            }
        }
        if (isset($meta['subtype'])) {
            $clean['subtype'] = substr(sanitize_key((string) $meta['subtype']), 0, 32);
        }

        return $clean;
    }

    private static function invalid_event(): WP_Error {
        return new WP_Error('focus_tracker_invalid_event', __('이벤트 시각 또는 순서 정보가 올바르지 않습니다.', 'focus-tracker'), array('status' => 400));
    }

    private static function store_failed(): WP_Error {
        return new WP_Error('focus_tracker_store_failed', __('기록을 저장하지 못했습니다. 다시 전송해 주세요.', 'focus-tracker'), array('status' => 503));
    }

    /** @return array<string, mixed> */
    private static function json_body(WP_REST_Request $request): array {
        $params = $request->get_json_params();

        return is_array($params) ? $params : array();
    }
}
