<?php
declare(strict_types=1);

namespace FocusTracker\Database;

use FocusTracker\Support\Clock;

/**
 * One row per browser tab/page-load a student tracked (see
 * docs/DESIGN.md §4). A single real attempt at an activity can span several
 * of these (page refresh, or navigating /problems/{id} -> /submit/{id}
 * starts a new tracker.js session each time) — Scoring\Activity_Score_Service
 * merges every participation for a (activity_id, user_id) pair before
 * scoring.
 */
final class Participation_Repository {
    public const END_REASON_PAGEHIDE = 'pagehide';
    public const END_REASON_BEACON = 'beacon';
    public const END_REASON_TIMEOUT = 'timeout';
    public const END_REASON_ADMIN_CLOSED = 'admin_closed';

    /** @return array<string, mixed>|null */
    public static function find_by_session_uid(string $session_uid): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Installer::participations_table() . ' WHERE session_uid = %s', $session_uid),
            ARRAY_A
        );

        return is_array($row) ? self::decode($row) : null;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . Installer::participations_table() . ' WHERE id = %d', $id),
            ARRAY_A
        );

        return is_array($row) ? self::decode($row) : null;
    }

    /**
     * @param array{activity_id: ?int, user_id: int, problem_id: ?int, session_uid: string, started_at_ms: int} $data
     */
    public static function find_or_create(array $data): ?array {
        $existing = self::find_by_session_uid($data['session_uid']);
        if (null !== $existing) {
            return $existing;
        }

        global $wpdb;

        $started_at_ms = $data['started_at_ms'];
        $now_mysql = current_time('mysql', true);

        $inserted = $wpdb->insert(
            Installer::participations_table(),
            array(
                'activity_id' => $data['activity_id'],
                'user_id' => $data['user_id'],
                'problem_id' => $data['problem_id'],
                'session_uid' => $data['session_uid'],
                'started_at' => Clock::ms_to_mysql($started_at_ms),
                'started_at_ms' => $started_at_ms,
                'last_heartbeat_at' => Clock::ms_to_mysql($started_at_ms),
                'last_heartbeat_at_ms' => $started_at_ms,
                'created_at' => $now_mysql,
                'page_category' => $data['page_category'] ?? 'unclassified',
                'page_subtype' => $data['page_subtype'] ?? null,
                'page_key' => $data['page_key'] ?? null,
                'page_context_version' => $data['page_context_version'] ?? 0,
            ),
            array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d')
        );

        if (false !== $inserted) {
            return self::find((int) $wpdb->insert_id);
        }

        // Lost a race with a concurrent flush for the same brand-new
        // session_uid (unique key) — the other request's row now exists.
        return self::find_by_session_uid($data['session_uid']);
    }

    public static function touch_heartbeat(int $id, int $at_ms): bool {
        global $wpdb;

        return false !== $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Installer::participations_table() . "
                 SET ended_at = CASE WHEN end_reason = 'timeout' THEN NULL ELSE ended_at END,
                     ended_at_ms = CASE WHEN end_reason = 'timeout' THEN NULL ELSE ended_at_ms END,
                     end_reason = CASE WHEN end_reason = 'timeout' THEN NULL ELSE end_reason END,
                     last_heartbeat_at = %s, last_heartbeat_at_ms = %d
                 WHERE id = %d AND last_heartbeat_at_ms < %d",
                Clock::ms_to_mysql($at_ms),
                $at_ms,
                $id,
                $at_ms
            )
        );
    }

    public static function close(int $id, int $ended_at_ms, string $reason): bool {
        global $wpdb;

        // Delayed/retried pagehide requests must never shorten a session.
        return false !== $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::participations_table() . '
             SET ended_at = %s, ended_at_ms = %d, end_reason = %s
             WHERE id = %d AND (ended_at_ms IS NULL OR ended_at_ms < %d)',
            Clock::ms_to_mysql($ended_at_ms), $ended_at_ms, $reason, $id, $ended_at_ms
        ));
    }

    /** @return array<string, mixed>|null Most recent participation tied to an activity, for the student self-view page. */
    public static function find_latest_activity_participation_for_user(int $user_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT p.*, a.id AS activity_id FROM ' . Installer::participations_table() . ' p
                 JOIN ' . Installer::activities_table() . ' a ON (a.id = p.activity_id OR EXISTS (
                    SELECT 1 FROM ' . Installer::activity_links_table() . ' l WHERE l.participation_id = p.id AND l.activity_id = a.id
                 ))
                 WHERE p.user_id = %d AND a.status != \'deleted\'
                 ORDER BY a.starts_at DESC, p.started_at_ms DESC LIMIT 1',
                $user_id
            ),
            ARRAY_A
        );

        return is_array($row) ? self::decode($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public static function list_for_activity_and_user(int $activity_id, int $user_id): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT p.* FROM ' . Installer::participations_table() . ' p
                 WHERE p.user_id = %d AND (p.activity_id = %d OR EXISTS (
                    SELECT 1 FROM ' . Installer::activity_links_table() . ' l
                    WHERE l.participation_id = p.id AND l.activity_id = %d
                 )) ORDER BY p.started_at_ms ASC',
                $user_id,
                $activity_id,
                $activity_id
            ),
            ARRAY_A
        );

        return array_map(array(__CLASS__, 'decode'), is_array($rows) ? $rows : array());
    }

    public static function link_activity(int $participation_id, int $activity_id): bool {
        global $wpdb;

        return false !== $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Installer::activity_links_table() . ' (participation_id, activity_id)
             VALUES (%d, %d) ON DUPLICATE KEY UPDATE activity_id = activity_id',
            $participation_id, $activity_id
        ));
    }

    /** @return array<int, list<array<string, mixed>>> Request-local roster input. */
    public static function list_for_activity_and_users(int $activity_id, array $user_ids): array {
        $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
        if (empty($user_ids)) { return array(); }
        global $wpdb;
        $holders = implode(',', array_fill(0, count($user_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT p.* FROM ' . Installer::participations_table() . " p
             WHERE p.user_id IN ({$holders}) AND (p.activity_id = %d OR EXISTS (
                 SELECT 1 FROM " . Installer::activity_links_table() . ' l
                 WHERE l.participation_id = p.id AND l.activity_id = %d
             )) ORDER BY p.started_at_ms ASC, p.id ASC',
            array_merge($user_ids, array($activity_id, $activity_id))
        ), ARRAY_A);
        if (! is_array($rows) || '' !== (string) $wpdb->last_error) { throw new \RuntimeException('Score participation storage unavailable.'); }
        $result = array_fill_keys($user_ids, array());
        foreach ($rows as $row) { $result[(int) $row['user_id']][] = self::decode($row); }
        return $result;
    }

    /** @return list<int> Includes associations created before the link table existed. */
    public static function activity_ids(int $participation_id): array {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT activity_id FROM ' . Installer::activity_links_table() . ' WHERE participation_id = %d
             UNION SELECT activity_id FROM ' . Installer::participations_table() . ' WHERE id = %d AND activity_id IS NOT NULL',
            $participation_id, $participation_id
        ), ARRAY_A);

        return array_map(static fn (array $row): int => (int) $row['activity_id'], is_array($rows) ? $rows : array());
    }

    public static function mark_delivery_gap(int $id): bool {
        global $wpdb;

        return false !== $wpdb->update(Installer::participations_table(), array('has_delivery_gaps' => 1), array('id' => $id), array('%d'), array('%d'));
    }

    public static function record_event_revision(int $id, int $inserted): bool {
        global $wpdb;

        return false !== $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::participations_table() . ' SET event_revision = event_revision + %d WHERE id = %d',
            max(0, $inserted), $id
        ));
    }

    /** Caller holds the learner guard and this existing row's primary-key lock. */
    public static function record_ingestion_state(array $current, int $end_ms, string $reason, int $heartbeat_ms, int $inserted, bool $has_gaps): ?bool {
        global $wpdb;
        $set = array(); $values = array();
        if ($current['ended_at_ms'] === null || (int) $current['ended_at_ms'] !== $end_ms || ($current['end_reason'] ?? null) !== $reason) {
            $set[] = 'ended_at = %s'; $values[] = Clock::ms_to_mysql($end_ms);
            $set[] = 'ended_at_ms = %d'; $values[] = $end_ms;
            $set[] = 'end_reason = %s'; $values[] = $reason;
        }
        if ($heartbeat_ms > (int) $current['last_heartbeat_at_ms']) {
            $set[] = 'last_heartbeat_at = %s'; $values[] = Clock::ms_to_mysql($heartbeat_ms);
            $set[] = 'last_heartbeat_at_ms = %d'; $values[] = $heartbeat_ms;
        }
        if ($inserted > 0) { $set[] = 'event_revision = event_revision + %d'; $values[] = $inserted; }
        if ($has_gaps && empty($current['has_delivery_gaps'])) { $set[] = 'has_delivery_gaps = 1'; }
        if ($set === array()) { return false; }
        $values[] = (int) $current['id'];
        return $wpdb->query($wpdb->prepare('UPDATE ' . Installer::participations_table() . ' SET ' . implode(', ', $set) . ' WHERE id = %d', $values)) === false ? null : true;
    }

    /** Reconcile saved observations when an authorized teacher edits a window/problem set. */
    public static function link_matching_sessions(array $activity, array $learner_ids): bool {
        global $wpdb;
        $saved = true;
        foreach (array_chunk($learner_ids, 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $type = (string) $activity['context_type'];
            $context_id = (string) $activity['context_id'];
            $bare_id = str_starts_with($context_id, $type . ':') ? substr($context_id, strlen($type) + 1) : $context_id;
            $params = array_merge($chunk, array(Clock::mysql_to_ms((string) $activity['ends_at']), Clock::mysql_to_ms((string) $activity['starts_at']), $type, $bare_id, $type . ':' . $bare_id));
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT p.id, p.problem_id, p.page_context_version FROM ' . Installer::participations_table() . " p
                 WHERE p.user_id IN ({$placeholders}) AND p.started_at_ms <= %d
                   AND COALESCE(p.ended_at_ms, p.last_heartbeat_at_ms) >= %d
                   AND ((p.session_uid NOT LIKE 'g6-%%' AND p.page_context_version = 0) OR EXISTS (
                     SELECT 1 FROM " . Installer::activities_table() . " captured
                     WHERE captured.context_type = %s AND captured.context_id IN (%s, %s)
                       AND (captured.id = p.activity_id OR EXISTS (
                         SELECT 1 FROM " . Installer::activity_links_table() . " original_link
                         WHERE original_link.participation_id = p.id AND original_link.activity_id = captured.id
                       ))
                   ))",
                $params
            ), ARRAY_A);
            if ('' !== (string) $wpdb->last_error || ! is_array($rows)) {
                return false;
            }
            foreach ($rows as $row) {
                $signed_nonproblem = $row['problem_id'] === null && (int) ($row['page_context_version'] ?? 0) > 0;
                if (! $signed_nonproblem && null !== $activity['problem_ids'] && ! in_array((int) $row['problem_id'], $activity['problem_ids'], true)) {
                    continue;
                }
                $saved = self::link_activity((int) $row['id'], (int) $activity['id']) && $saved;
            }
        }
        return $saved;
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array {
        $row['id'] = (int) $row['id'];
        $row['activity_id'] = null === $row['activity_id'] ? null : (int) $row['activity_id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['problem_id'] = null === $row['problem_id'] ? null : (int) $row['problem_id'];
        $row['started_at_ms'] = (int) $row['started_at_ms'];
        $row['last_heartbeat_at_ms'] = (int) $row['last_heartbeat_at_ms'];
        $row['ended_at_ms'] = null === $row['ended_at_ms'] ? null : (int) $row['ended_at_ms'];
        $row['raw_events_expired'] = ! empty($row['raw_events_expired']);
        $row['has_delivery_gaps'] = ! empty($row['has_delivery_gaps']);
        $row['event_revision'] = (int) ($row['event_revision'] ?? 0);
        $row['page_category'] = (string) ($row['page_category'] ?? 'unclassified');
        $row['page_subtype'] = isset($row['page_subtype']) ? (string) $row['page_subtype'] : null;
        $row['page_key'] = isset($row['page_key']) ? (string) $row['page_key'] : null;
        $row['page_context_version'] = (int) ($row['page_context_version'] ?? 0);

        return $row;
    }
}
