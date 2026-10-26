<?php
declare(strict_types=1);

namespace FocusTracker\Database;

/** Settled score snapshots; archived snapshots survive event invalidation. */
final class Score_Repository {
    public static function get(int $activity_id, int $user_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . Installer::scores_table() . ' WHERE activity_id = %d AND user_id = %d',
            $activity_id, $user_id
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function upsert(int $activity_id, int $user_id, array $metrics): bool {
        global $wpdb;
        $data = array(
            'activity_id' => $activity_id, 'user_id' => $user_id,
            'total_active_seconds' => $metrics['total_active_seconds'],
            'total_hidden_seconds' => $metrics['total_hidden_seconds'],
            'focus_ratio' => $metrics['focus_ratio'], 'blur_count' => $metrics['blur_count'],
            'avg_recovery_seconds' => $metrics['avg_recovery_seconds'],
            'activity_window_ratio' => $metrics['activity_window_ratio'],
            'score_focus' => $metrics['score_focus'], 'score_selfreg' => $metrics['score_selfreg'],
            'score_activity' => $metrics['score_activity'], 'score_total' => $metrics['score_total'],
            'computed_at' => current_time('mysql', true),
            'cache_signature' => (string) ($metrics['cache_signature'] ?? ''),
            'finalized' => empty($metrics['finalized']) ? 0 : 1,
            'abandoned_without_return' => empty($metrics['abandoned_without_return']) ? 0 : 1,
            'has_observation_gaps' => empty($metrics['has_observation_gaps']) ? 0 : 1,
            'has_delivery_gaps' => empty($metrics['has_delivery_gaps']) ? 0 : 1,
            'raw_events_expired' => empty($metrics['raw_events_expired']) ? 0 : 1,
        );
        $formats = array('%d','%d','%d','%d','%f','%d','%f','%f','%f','%f','%f','%f','%s','%s','%d','%d','%d','%d','%d');
        $columns = implode(', ', array_keys($data));
        $updates = array();
        foreach (array_keys($data) as $column) {
            if ($column !== 'activity_id' && $column !== 'user_id') {
                $updates[] = "{$column} = IF(raw_events_expired = 1, {$column}, VALUES({$column}))";
            }
        }
        $sql = 'INSERT INTO ' . Installer::scores_table() . " ({$columns}) VALUES (" . implode(', ', $formats)
            . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        return false !== $wpdb->query($wpdb->prepare($sql, array_values($data)));
    }

    /** Only a successfully saved final snapshot permits deletion of its source. */
    public static function mark_events_expired(int $activity_id, int $user_id): bool {
        global $wpdb;
        $row = self::get($activity_id, $user_id);
        if (null === $row || empty($row['finalized'])) {
            return false;
        }
        if (! empty($row['raw_events_expired'])) {
            return true;
        }
        return 1 === $wpdb->query($wpdb->prepare(
            'UPDATE ' . Installer::scores_table() . ' SET raw_events_expired = 1 WHERE activity_id = %d AND user_id = %d AND finalized = 1 AND raw_events_expired = 0',
            $activity_id, $user_id
        ));
    }

    public static function invalidate(int $activity_id, int $user_id): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Installer::scores_table() . ' WHERE activity_id = %d AND user_id = %d AND raw_events_expired = 0',
            $activity_id, $user_id
        ));
    }

    public static function invalidate_activity(int $activity_id): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Installer::scores_table() . ' WHERE activity_id = %d AND raw_events_expired = 0',
            $activity_id
        ));
    }
}
