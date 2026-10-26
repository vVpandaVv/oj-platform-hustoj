<?php
declare(strict_types=1);

namespace FocusTracker\Database;

/** Stores verified question snapshots and responses, never an editor draft. */
final class Check_Repository {
    public static function begin_for_user(int $user_id): ?array {
        global $wpdb;
        if (false === $wpdb->query('START TRANSACTION')) { return null; }
        if (! \FocusTracker\Privacy\Privacy_Guard::lock($user_id)) { self::finish(false); return null; }
        if (false === $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Installer::check_states_table() . ' (user_id, issued_day, issued_today, next_allowed_at_ms)
             VALUES (%d, %s, 0, 0) ON DUPLICATE KEY UPDATE user_id = user_id', $user_id, ''
        ))) { self::finish(false); return null; }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Installer::check_states_table() . ' WHERE user_id = %d FOR UPDATE', $user_id
        ), ARRAY_A);
        if (! is_array($row) || '' !== (string) $wpdb->last_error) { self::finish(false); return null; }
        return $row;
    }

    public static function finish(bool $commit): bool {
        global $wpdb;
        $saved = false !== $wpdb->query($commit ? 'COMMIT' : 'ROLLBACK');
        if ($commit && ! $saved) { $wpdb->query('ROLLBACK'); }
        return $saved;
    }

    public static function pending(int $user_id, int $now_ms): ?array {
        global $wpdb;
        if (false === $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::checks_table() . ' SET status = CASE WHEN window_end_ms IS NOT NULL AND expires_at_ms >= window_end_ms THEN %s ELSE %s END WHERE user_id = %d AND status = %s AND expires_at_ms <= %d',
            'invalidated', 'unanswered', $user_id, 'pending', $now_ms
        ))) { return null; }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Installer::checks_table() . ' WHERE user_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
            $user_id, 'pending'
        ), ARRAY_A);
        return is_array($row) ? self::decode($row) : null;
    }

    /** Does not classify expiry before the caller rechecks the schedule. */
    public static function pending_snapshot(int $user_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::checks_table() . ' WHERE user_id = %d AND status = %s ORDER BY id DESC LIMIT 1', $user_id, 'pending'), ARRAY_A);
        return is_array($row) ? self::decode($row) : null;
    }

    public static function find(int $id, int $user_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Installer::checks_table() . ' WHERE id = %d AND user_id = %d', $id, $user_id
        ), ARRAY_A);
        return is_array($row) ? self::decode($row) : null;
    }

    public static function recent(int $user_id, int $from_ms): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . Installer::checks_table() . ' WHERE user_id = %d AND issued_at_ms >= %d ORDER BY id DESC LIMIT 100',
            $user_id, $from_ms
        ), ARRAY_A);
        return array_map(array(__CLASS__, 'decode'), is_array($rows) ? $rows : array());
    }

    public static function create(array $data): ?array {
        global $wpdb;
        if (false === $wpdb->insert(Installer::checks_table(), $data)) { return null; }
        return self::find((int) $wpdb->insert_id, (int) $data['user_id']);
    }

    public static function save_state(int $user_id, array $state): bool {
        global $wpdb;
        return false !== $wpdb->update(Installer::check_states_table(), $state, array('user_id' => $user_id));
    }

    public static function respond(int $id, int $user_id, string $status, ?string $answer_id, ?string $response_uid, int $now_ms): bool {
        global $wpdb;
        $updated = $wpdb->update(Installer::checks_table(), array(
            'status' => $status, 'answer_id' => $answer_id, 'response_uid' => $response_uid, 'responded_at_ms' => $now_ms,
        ), array('id' => $id, 'user_id' => $user_id, 'status' => 'pending'));
        return is_int($updated) && $updated > 0;
    }

    /** Called by the authorised activity edit transaction, without source data. */
    public static function pending_for_activity(int $activity_id): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Installer::checks_table() .
            ' WHERE status = %s AND (activity_ids_json = %s OR activity_ids_json LIKE %s OR activity_ids_json LIKE %s OR activity_ids_json LIKE %s) ORDER BY id ASC FOR UPDATE',
            'pending', '[' . $activity_id . ']', '[' . $activity_id . ',%', '%,' . $activity_id . ',%', '%,' . $activity_id . ']'), ARRAY_A);
        return array_map(array(__CLASS__, 'decode'), is_array($rows) ? $rows : array());
    }

    public static function bind_window(array $row, array $ids, int $start, int $end): bool {
        global $wpdb;
        $values = array('activity_ids_json' => wp_json_encode($ids), 'window_start_ms' => $start, 'window_end_ms' => $end, 'expires_at_ms' => min($row['expires_at_ms'], $end));
        return false !== $wpdb->update(Installer::checks_table(), $values, array('id' => $row['id'], 'user_id' => $row['user_id'], 'status' => 'pending'));
    }

    public static function purge(int $before_ms): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Installer::checks_table() . ' WHERE issued_at_ms < %d', $before_ms));
    }

    private static function decode(array $row): array {
        foreach (array('id', 'user_id', 'problem_id', 'issued_at_ms', 'expires_at_ms') as $key) { $row[$key] = (int) $row[$key]; }
        $row['submission_id'] = null === $row['submission_id'] ? null : (int) $row['submission_id'];
        foreach (array('window_start_ms', 'window_end_ms') as $key) { $row[$key] = isset($row[$key]) ? (int) $row[$key] : null; }
        $candidate = json_decode((string) $row['candidate_json'], true);
        $row['candidate'] = is_array($candidate) ? $candidate : array();
        return $row;
    }
}
