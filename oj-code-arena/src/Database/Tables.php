<?php
declare(strict_types=1);

namespace OjCodeArena\Database;

final class Tables {
    public static function sessions(): string {
        global $wpdb;
        return $wpdb->prefix . 'oj_code_arena_sessions';
    }

    public static function problems(): string {
        global $wpdb;
        return $wpdb->prefix . 'oj_code_arena_problems';
    }

    public static function rounds(): string {
        global $wpdb;
        return $wpdb->prefix . 'oj_code_arena_rounds';
    }

    public static function players(): string {
        global $wpdb;
        return $wpdb->prefix . 'oj_code_arena_round_players';
    }

    public static function now(): string {
        return gmdate('Y-m-d H:i:s'); // = current_time('mysql', true), usable under SHORTINIT
    }
}
