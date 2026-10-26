<?php
declare(strict_types=1);

namespace FocusTracker\Support;

/** Bounded change history: actor and operation only, never source or answers. */
final class Audit_Log {
    private const OPTION = 'focus_tracker_audit_log';
    public static function record(string $operation, int $target = 0, array $fields = array()): void {
        if (! in_array($operation, array('settings', 'activity_created', 'activity_updated', 'activity_deleted'), true)) { return; }
        $allowed = array('weight_focus', 'weight_selfreg', 'weight_activity', 'retention_days', 'check_retention_days', 'aggregate_retention_days', 'checks_enabled', 'check_cooldown_seconds', 'check_daily_limit', 'tracked_url_patterns', 'activity_window_seconds', 'name', 'starts_at', 'ends_at', 'status', 'problem_ids');
        $entry = array('atMs' => Clock::now_ms(), 'actorId' => get_current_user_id(), 'operation' => $operation, 'targetId' => max(0, $target), 'fields' => array_values(array_intersect($allowed, $fields)));
        self::change(static fn (array $rows): array => array_slice(array_merge($rows, array($entry)), -100));
        if (function_exists('do_action')) { do_action('focus_tracker_audit_event', $entry); }
    }

    public static function recent(): array {
        $rows = get_option(self::OPTION, array());
        return is_array($rows) ? array_reverse(array_slice($rows, -10)) : array();
    }

    public static function for_user(int $user_id): array {
        $rows = get_option(self::OPTION, array());
        return array_values(array_filter(is_array($rows) ? $rows : array(), static fn (array $row): bool => (int) ($row['actorId'] ?? 0) === $user_id));
    }

    public static function erase_user(int $user_id): bool {
        return self::change(static fn (array $rows): array => array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['actorId'] ?? 0) !== $user_id)));
    }

    /** Compare-and-swap prevents one teacher's change overwriting another's. */
    private static function change(callable $transform): bool {
        global $wpdb;
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            wp_cache_delete(self::OPTION, 'options');
            $before = get_option(self::OPTION, false);
            $after = $transform(is_array($before) ? $before : array());
            if ($before === $after) { return true; }
            if ($before === false) {
                if (add_option(self::OPTION, $after, '', false)) { return true; }
                continue;
            }
            $saved = $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->options . ' SET option_value = %s WHERE option_name = %s AND option_value = %s', maybe_serialize($after), self::OPTION, maybe_serialize($before)));
            wp_cache_delete(self::OPTION, 'options');
            if ($saved === false) { return false; }
            if ($saved > 0) { return true; }
        }
        return false;
    }
}
