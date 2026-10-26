<?php
declare(strict_types=1);

namespace FocusTracker\Database;

use FocusTracker\Support\Clock;
use RuntimeException;

/** Daily totals contain no code, question text, names, or individual answers. */
final class Statistics_Repository {
    public static function scope_key(array $scope): string {
        return hash('sha256', (string) json_encode(array($scope['type'], $scope['id'])));
    }

    public static function sessions(int $user_id, int $start, int $end, bool $lock = false): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Installer::participations_table() . ' WHERE user_id = %d AND started_at_ms < %d
             AND COALESCE(ended_at_ms, last_heartbeat_at_ms + 45000) > %d ORDER BY id ASC' . ($lock ? ' FOR UPDATE' : ''), $user_id, $end, $start
        ), ARRAY_A);
        self::assert_read();
        foreach ($rows as &$row) {
            foreach (array('id', 'user_id', 'started_at_ms', 'last_heartbeat_at_ms', 'event_revision') as $key) { $row[$key] = (int) $row[$key]; }
            $row['ended_at_ms'] = null === $row['ended_at_ms'] ? null : (int) $row['ended_at_ms'];
            $row['problem_id'] = null === $row['problem_id'] ? null : (int) $row['problem_id'];
            $row['raw_events_expired'] = ! empty($row['raw_events_expired']);
            $row['has_delivery_gaps'] = ! empty($row['has_delivery_gaps']);
            $row['_activityIds'] = null === $row['activity_id'] ? array() : array((int) $row['activity_id']);
        }
        unset($row);
        if (! empty($rows)) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $links = $wpdb->get_results($wpdb->prepare('SELECT participation_id, activity_id FROM ' . Installer::activity_links_table() . " WHERE participation_id IN ({$placeholders})", $ids), ARRAY_A);
            self::assert_read();
            $by_id = array();
            foreach ($links as $link) { $by_id[(int) $link['participation_id']][] = (int) $link['activity_id']; }
            foreach ($rows as &$row) {
                $row['_activityIds'] = array_values(array_unique(array_merge($row['_activityIds'], $by_id[$row['id']] ?? array())));
                sort($row['_activityIds'], SORT_NUMERIC);
            }
            unset($row);
        }
        return $rows;
    }

    public static function activities(array $scope, int $start, int $end): array {
        if ($scope['type'] === 'self') { return array(); }
        // Removing a schedule from the console does not erase its historical
        // authorization for already collected learning evidence.
        $rows = Activity_Repository::find_overlapping_for_context($scope['type'], $scope['id'], Clock::ms_to_mysql($start), Clock::ms_to_mysql($end), false, true);
        self::assert_read();
        return $rows;
    }

    /** Current schedule bounds for new window-limited physical sessions. */
    public static function capture_activities(array $sessions): array {
        global $wpdb;
        $ids = array();
        foreach ($sessions as $session) {
            if (str_starts_with((string) $session['session_uid'], 'g6-')) { $ids = array_merge($ids, $session['_activityIds']); }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) { return array(); }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Installer::activities_table() . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ') ORDER BY id ASC', $ids), ARRAY_A);
        self::assert_read();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['problem_ids'] = $row['problem_ids'] === null ? null : array_map('intval', json_decode((string) $row['problem_ids'], true) ?: array());
            if ($row['status'] === Activity_Repository::STATUS_CLOSED) { $row['ends_at'] = min($row['ends_at'], $row['updated_at']); }
        }
        unset($row);
        return $rows;
    }

    public static function checks(int $user_id, int $start, int $end, bool $lock = false): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, problem_id, context_type, context_id, issued_at_ms, expires_at_ms, window_end_ms, status FROM ' . Installer::checks_table() .
            ' WHERE user_id = %d AND issued_at_ms >= %d AND issued_at_ms < %d ORDER BY id ASC' . ($lock ? ' FOR UPDATE' : ''), $user_id, $start, $end
        ), ARRAY_A);
        self::assert_read();
        return $rows;
    }

    public static function finalize_expired_checks(int $user_id, int $start, int $end, int $now): void {
        global $wpdb;
        if (false === $wpdb->query($wpdb->prepare('UPDATE ' . Installer::checks_table() .
            ' SET status = CASE WHEN window_end_ms IS NOT NULL AND expires_at_ms >= window_end_ms THEN %s ELSE %s END WHERE user_id = %d AND issued_at_ms >= %d AND issued_at_ms < %d AND status = %s AND expires_at_ms <= %d',
            'invalidated', 'unanswered', $user_id, $start, $end, 'pending', $now))) { throw new RuntimeException('Check expiry could not be finalised.'); }
    }

    public static function get(int $user_id, array $scope, string $day, string $zone): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::statistics_table() .
            ' WHERE user_id = %d AND scope_key = %s AND local_day = %s AND time_zone = %s', $user_id, self::scope_key($scope), $day, $zone), ARRAY_A);
        self::assert_read();
        if (! is_array($row)) { return null; }
        $row['metrics'] = json_decode((string) $row['metrics_json'], true);
        if (! is_array($row['metrics'])) { throw new RuntimeException('Invalid daily statistics snapshot.'); }
        $row['archived'] = ! empty($row['archived']);
        return $row;
    }

    public static function save(int $user_id, array $scope, string $day, string $zone, array $metrics, string $signature, bool $archived): bool {
        global $wpdb;
        $table = Installer::statistics_table();
        $json = wp_json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        return false !== $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (user_id, scope_key, scope_type, scope_id, local_day, time_zone, metrics_json, source_signature, archived, updated_at)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE
               metrics_json = IF(archived = 1 AND VALUES(archived) = 0, metrics_json, VALUES(metrics_json)),
               source_signature = IF(archived = 1 AND VALUES(archived) = 0, source_signature, VALUES(source_signature)),
               updated_at = IF(archived = 1 AND VALUES(archived) = 0, updated_at, VALUES(updated_at)),
               archived = IF(archived = 1, 1, VALUES(archived))",
            $user_id, self::scope_key($scope), $scope['type'], $scope['id'], $day, $zone, $json, $signature, $archived ? 1 : 0, current_time('mysql', true)
        ));
    }

    public static function archive_contexts(int $user_id, int $start, int $end): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT DISTINCT a.context_type, a.context_id FROM ' . Installer::activities_table() . " a
             WHERE a.context_type IN ('class', 'group') AND a.starts_at < %s AND a.ends_at > %s
             AND EXISTS (SELECT 1 FROM " . Installer::participations_table() . ' p WHERE p.user_id = %d
                 AND p.started_at_ms < %d AND COALESCE(p.ended_at_ms, p.last_heartbeat_at_ms + 45000) > %d
                 AND (p.activity_id = a.id OR EXISTS (SELECT 1 FROM ' . Installer::activity_links_table() . ' l WHERE l.participation_id = p.id AND l.activity_id = a.id)))',
            Clock::ms_to_mysql($end), Clock::ms_to_mysql($start), $user_id, $end, $start
        ), ARRAY_A);
        self::assert_read();
        $scopes = array_map(static fn (array $row): array => array('type' => $row['context_type'], 'id' => $row['context_id']), $rows);
        foreach (self::checks($user_id, $start, $end, true) as $check) {
            if (in_array($check['context_type'], array('class', 'group'), true) && is_string($check['context_id'])) {
                $scopes[] = array('type' => $check['context_type'], 'id' => $check['context_id']);
                if (preg_match('/^[1-9][0-9]*$/D', $check['context_id'])) { $scopes[] = array('type' => $check['context_type'], 'id' => $check['context_type'] . ':' . $check['context_id']); }
            }
        }
        $unique = array();
        foreach ($scopes as $scope) { $unique[self::scope_key($scope)] = $scope; }
        return array_values($unique);
    }

    public static function old_checks(int $cutoff, int $after_id = 0, int $limit = 100): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, user_id, issued_at_ms FROM ' . Installer::checks_table() . ' WHERE issued_at_ms < %d AND id > %d ORDER BY id ASC LIMIT %d', $cutoff, $after_id, max(1, min(100, $limit))), ARRAY_A);
        self::assert_read();
        return $rows;
    }

    /** A different site timezone cannot redistribute already removed checks. */
    public static function has_archived_check_overlap(int $user_id, int $start, int $end): bool {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT local_day, time_zone FROM ' . Installer::statistics_table()
            . ' WHERE user_id = %d AND scope_type = %s AND local_day >= %s AND local_day <= %s AND metrics_json LIKE %s LIMIT 16',
            $user_id, 'self', gmdate('Y-m-d', intdiv($start, 1000) - 2 * DAY_IN_SECONDS), gmdate('Y-m-d', intdiv($end, 1000) + 2 * DAY_IN_SECONDS), '%"_checksArchived":true%'), ARRAY_A);
        self::assert_read();
        if (count($rows) >= 16) { return true; }
        foreach ($rows as $row) {
            try { $day = new \DateTimeImmutable($row['local_day'] . ' 00:00:00', new \DateTimeZone($row['time_zone'])); }
            catch (\Throwable $error) { return true; }
            if ($day->getTimestamp() * 1000 < $end && $day->modify('+1 day')->getTimestamp() * 1000 > $start) { return true; }
        }
        return false;
    }

    public static function assert_read(): void {
        global $wpdb;
        if ('' !== (string) $wpdb->last_error) { throw new RuntimeException('Statistics storage unavailable.'); }
    }
}
