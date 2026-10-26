<?php
declare(strict_types=1);

namespace TeachingTools\Jobs;

use TeachingTools\Addon\Whiteboard\Whiteboard_Image_Repository;
use TeachingTools\Database\Installer;

/**
 * Daily WP-Cron sweep that deletes tool sessions (and their participant
 * rows) well past their expiry, so the tables don't grow unbounded across
 * a school year. Each session carries the expiry selected when it was
 * created; this sweep applies the administrator's retention window after
 * that expiry and removes rows in bounded batches.
 */
final class Session_Cleanup {
    private const HOOK = 'teaching_tools_cleanup_sessions';
    private const DEFAULT_RETENTION_DAYS = 30;
    private const BATCH_SIZE = 250;

    public static function register(): void {
        add_action('init', array(__CLASS__, 'maybe_schedule'));
        add_action(self::HOOK, array(__CLASS__, 'run'));
    }

    public static function maybe_schedule(): void {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function unschedule(): void {
        $timestamp = wp_next_scheduled(self::HOOK);
        if (false !== $timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public static function run(): void {
        global $wpdb;

        $retention_days = max(1, min(365, (int) get_option('teaching_tools_retention_days', self::DEFAULT_RETENTION_DAYS)));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));
        $sessions_table = Installer::sessions_table();
        $participants_table = Installer::participants_table();
        $hosts_table = Installer::session_hosts_table();

        $expired = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, tool_type FROM {$sessions_table} WHERE expires_at < %s ORDER BY id ASC LIMIT %d",
                $cutoff,
                self::BATCH_SIZE
            )
        );

        if (empty($expired)) {
            return;
        }

        foreach ($expired as $row) {
            if ('whiteboard' === (string) $row->tool_type) {
                Whiteboard_Image_Repository::delete_for_session((int) $row->id);
            }
        }

        $expired_ids = array_map(static function ($row) {
            return (int) $row->id;
        }, $expired);

        $placeholders = implode(',', array_fill(0, count($expired_ids), '%d'));
        $wpdb->query(
            $wpdb->prepare("DELETE FROM {$participants_table} WHERE session_id IN ({$placeholders})", $expired_ids)
        );
        $wpdb->query(
            $wpdb->prepare("DELETE FROM {$hosts_table} WHERE session_id IN ({$placeholders})", $expired_ids)
        );
        $wpdb->query(
            $wpdb->prepare("DELETE FROM {$sessions_table} WHERE id IN ({$placeholders})", $expired_ids)
        );
    }
}
