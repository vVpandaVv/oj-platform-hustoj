<?php
declare(strict_types=1);

namespace OjCodeArena\Database;

final class Installer {
    public const DB_VERSION = '2';
    public const OPTION = 'oj_code_arena_db_version';

    public static function maybe_install(): void {
        if (self::DB_VERSION !== get_option(self::OPTION)) {
            self::install();
            // Updating by replacing the zip does not run the activation hook.
            \OjCodeArena\Support\Defaults::apply();
        }
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $sessions = Tables::sessions();
        $problems = Tables::problems();
        $rounds = Tables::rounds();
        $players = Tables::players();

        dbDelta("CREATE TABLE {$sessions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(120) NOT NULL DEFAULT '',
            owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            context_type varchar(16) NOT NULL DEFAULT '',
            context_id varchar(64) NOT NULL DEFAULT '',
            context_label varchar(120) NOT NULL DEFAULT '',
            settings longtext NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'open',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_status (owner_id, status),
            KEY context (context_type, context_id, status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$problems} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            kind varchar(8) NOT NULL DEFAULT 'normal',
            title varchar(120) NOT NULL DEFAULT '',
            code text NOT NULL,
            input text NOT NULL,
            answers text NOT NULL,
            tags varchar(255) NOT NULL DEFAULT '[]',
            hash char(40) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY hash (hash),
            KEY kind_enabled (kind, enabled)
        ) {$charset};");

        dbDelta("CREATE TABLE {$rounds} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL DEFAULT 0,
            mode varchar(8) NOT NULL DEFAULT 'class',
            round_no int(10) unsigned NOT NULL DEFAULT 0,
            title varchar(120) NOT NULL DEFAULT '',
            reason varchar(16) NOT NULL DEFAULT '',
            started_at datetime NOT NULL,
            ended_at datetime NOT NULL,
            result longtext NOT NULL,
            dedupe char(40) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dedupe (dedupe),
            KEY session_ended (session_id, ended_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$players} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            round_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            team_no tinyint(3) unsigned NOT NULL DEFAULT 0,
            team_rank tinyint(3) unsigned NOT NULL DEFAULT 0,
            team_pct tinyint(3) unsigned NOT NULL DEFAULT 0,
            cleared tinyint(1) NOT NULL DEFAULT 0,
            solved smallint(5) unsigned NOT NULL DEFAULT 0,
            bosses smallint(5) unsigned NOT NULL DEFAULT 0,
            score smallint(5) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY user_round (user_id, round_id),
            KEY round_id (round_id)
        ) {$charset};");

        // Built-in mode (no game server): live arena state, the event log the
        // browsers poll, and who polled when. See Engine\Relay.
        $live = $wpdb->prefix . 'oj_code_arena_live';
        $events = $wpdb->prefix . 'oj_code_arena_events';
        $presence = $wpdb->prefix . 'oj_code_arena_presence';
        dbDelta("CREATE TABLE {$live} (
            arena varchar(32) NOT NULL,
            mode varchar(8) NOT NULL DEFAULT 'class',
            state longtext NOT NULL,
            problems longtext NOT NULL,
            summary text NOT NULL,
            next_tick bigint(20) NOT NULL DEFAULT 0,
            closed tinyint(1) NOT NULL DEFAULT 0,
            host_uid bigint(20) unsigned NOT NULL DEFAULT 0,
            created bigint(20) NOT NULL DEFAULT 0,
            updated bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (arena),
            KEY mode_closed (mode, closed),
            KEY host_created (host_uid, created)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            arena varchar(32) NOT NULL,
            scope varchar(24) NOT NULL,
            payload mediumtext NOT NULL,
            at bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY arena_id (arena, id),
            KEY at (at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$presence} (
            arena varchar(32) NOT NULL,
            uid bigint(20) unsigned NOT NULL,
            role varchar(8) NOT NULL,
            seen bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (arena, uid, role),
            KEY uid_seen (uid, seen)
        ) {$charset};");

        update_option(self::OPTION, self::DB_VERSION, false);
        self::seed_problems();
    }

    // Loads the bundled default bank once, when the bank is empty.
    public static function seed_problems(): int {
        $repo = new Problem_Repository();
        if ($repo->count() > 0) {
            return 0;
        }
        return self::load_default_bank($repo);
    }

    public static function load_default_bank(Problem_Repository $repo): int {
        $file = OJ_CODE_ARENA_PATH . 'data/default-bank.json';
        if (! is_readable($file)) {
            return 0;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            return 0;
        }
        $list = array();
        foreach ((array) ($data['normal'] ?? array()) as $p) {
            $list[] = array('kind' => 'normal', 'code' => (string) ($p['code'] ?? ''), 'tags' => (array) ($p['tags'] ?? array()));
        }
        foreach ((array) ($data['boss'] ?? array()) as $p) {
            $list[] = array(
                'kind' => 'boss',
                'title' => (string) ($p['title'] ?? ''),
                'code' => (string) ($p['code'] ?? ''),
                'input' => (string) ($p['input'] ?? ''),
                'answers' => (array) ($p['answers'] ?? array()),
                'tags' => (array) ($p['tags'] ?? array()),
            );
        }
        $added = 0;
        foreach ($list as $item) {
            try {
                $added += $repo->insert($item, 0) ? 1 : 0;
            } catch (\InvalidArgumentException $e) {
                continue; // a bad bundled line never blocks activation
            }
        }
        return $added;
    }
}
