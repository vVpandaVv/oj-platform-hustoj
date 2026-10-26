<?php
declare(strict_types=1);

namespace OjCodeArena\Jobs;

use OjCodeArena\Database\Round_Repository;
use OjCodeArena\Engine\Wpdb_Store;
use OjCodeArena\Support\Settings;

/**
 * Hourly housekeeping: old built-in events/presence/closed arenas, and
 * round records older than the retention setting (0 = keep forever).
 */
final class Cleanup {
    public const HOOK = 'oj_code_arena_cleanup';

    public static function register(): void {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('init', array(__CLASS__, 'maybe_schedule'));
    }

    public static function maybe_schedule(): void {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void {
        $next = wp_next_scheduled(self::HOOK);
        if ($next) {
            wp_unschedule_event($next, self::HOOK);
        }
    }

    public static function run(): void {
        global $wpdb;
        (new Wpdb_Store($wpdb))->prune((int) floor(microtime(true) * 1000));
        $days = Settings::keep_days();
        if ($days > 0) {
            (new Round_Repository())->delete_older_than(time() - $days * DAY_IN_SECONDS);
        }
    }
}
