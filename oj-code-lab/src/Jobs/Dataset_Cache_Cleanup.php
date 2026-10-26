<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Jobs;

use OjCodeLab\Database\Installer;

/**
 * Hourly WP-Cron sweep, mirroring focus-tracker's Jobs\Retention_Cleanup:
 * deletes oj_code_lab_dataset_cache rows past their TTL. Dataset_Cache::get()
 * already treats a stale row as a miss, but nothing else ever deleted one —
 * without this job, importing many distinct Kaggle files or URLs over the
 * life of a site would grow this table forever even though each entry
 * individually "expires". The bundled sample datasets and any teacher
 * upload live in oj_code_lab_uploads instead, which this job never touches.
 */
final class Dataset_Cache_Cleanup {
    private const HOOK = 'oj_code_lab_cache_cleanup';
    private const TTL_SECONDS = 24 * HOUR_IN_SECONDS;

    public static function register(): void {
        add_action('init', array(__CLASS__, 'maybe_schedule'));
        add_action(self::HOOK, array(__CLASS__, 'run'));
    }

    public static function maybe_schedule(): void {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook('oj_code_lab_summary_backfill');
    }

    public static function run(): void {
        global $wpdb;

        $table = Installer::dataset_cache_table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::TTL_SECONDS);

        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at <= %s", $cutoff));
    }
}
