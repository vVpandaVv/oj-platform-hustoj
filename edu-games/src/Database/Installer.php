<?php
declare(strict_types=1);

namespace EduGames\Database;

final class Installer {
    public static function maybe_install(): void {
        if (get_option('edu_games_db_version') !== EDU_GAMES_VERSION || ! self::scores_table_exists()) {
            self::install();
        }
    }

    public static function install(): void {
        self::migrate_legacy_hustoj_tables();
        self::migrate_legacy_hustoj_options();
        self::create_scores_table();
        self::create_sessions_table();
        self::create_teams_tables();
        $ai_ready = self::create_ai_model_lab_attempts_table();
        global $wpdb;
        $index = $wpdb->get_row("SHOW INDEX FROM " . self::scores_table() . " WHERE Key_name = 'best_attempt'", ARRAY_A);
        $tables_ready = self::scores_table_exists() && self::table_exists(self::sessions_table())
            && self::table_exists(self::teams_table()) && self::table_exists(self::team_members_table());
        if ($ai_ready && $tables_ready && is_array($index)) {
            update_option('edu_games_db_version', EDU_GAMES_VERSION);
        }
    }

    /**
     * One-time migration for sites that already ran the plugin under its
     * old "hustoj-edu-games" name: renames every "hustoj_edu_games_*" table
     * to its new "edu_games_*" name (RENAME TABLE preserves all rows,
     * indexes, and auto_increment state — nothing is copied or dropped),
     * and carries the legacy db-version option over so maybe_install()
     * doesn't mistake a renamed-but-current install for one that needs a
     * fresh install(). Safe to run on every install() call: once the old
     * tables are gone, every check below is a no-op.
     */
    private static function migrate_legacy_hustoj_tables(): void {
        global $wpdb;

        $renames = array(
            $wpdb->prefix . 'hustoj_edu_games_scores' => self::scores_table(),
            $wpdb->prefix . 'hustoj_edu_games_sessions' => self::sessions_table(),
            $wpdb->prefix . 'hustoj_edu_games_teams' => self::teams_table(),
            $wpdb->prefix . 'hustoj_edu_games_team_members' => self::team_members_table(),
            $wpdb->prefix . 'hustoj_edu_games_ai_model_lab_attempts' => self::ai_model_lab_attempts_table(),
        );

        foreach ($renames as $old_table => $new_table) {
            if (! self::table_exists($old_table)) {
                continue;
            }

            if (self::table_exists($new_table)) {
                // Both names exist — don't guess which one is authoritative;
                // leave both in place rather than risk dropping real data.
                error_log(sprintf(
                    '[Edu Games] Skipped migrating %s -> %s: destination table already exists.',
                    $old_table,
                    $new_table
                ));
                continue;
            }

            $wpdb->query(sprintf('RENAME TABLE `%s` TO `%s`', $old_table, $new_table));
        }
    }

    /**
     * Carries every wp_options row this plugin owns from its old
     * "hustoj_edu_games_*" key to the new "edu_games_*" key — the schema
     * version marker, the admin's per-game closed/hidden list, and the
     * legacy auto-generated ranking page's tracked post id + one-time
     * cleanup flags. Safe to call on every install(): once an old key is
     * gone, its row in $renames is a no-op.
     */
    private static function migrate_legacy_hustoj_options(): void {
        $renames = array(
            'hustoj_edu_games_db_version' => 'edu_games_db_version',
            'hustoj_edu_games_closed_games' => 'edu_games_closed_games',
            'hustoj_edu_games_ranking_page_id' => 'edu_games_ranking_page_id',
            'hustoj_edu_games_ranking_page_cleanup_done' => 'edu_games_ranking_page_cleanup_done',
            'hustoj_edu_games_ranking_page_removed' => 'edu_games_ranking_page_removed',
        );

        foreach ($renames as $old_key => $new_key) {
            $legacy_value = get_option($old_key, false);
            if (false === $legacy_value) {
                continue;
            }

            if (false === get_option($new_key, false)) {
                update_option($new_key, $legacy_value);
            }

            delete_option($old_key);
        }
    }

    public static function scores_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'edu_games_scores';
    }

    public static function sessions_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'edu_games_sessions';
    }

    public static function teams_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'edu_games_teams';
    }

    public static function team_members_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'edu_games_team_members';
    }

    public static function ai_model_lab_attempts_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'edu_games_ai_model_lab_attempts';
    }

    private static function create_scores_table(): void {
        global $wpdb;

        $table = self::scores_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            game_slug varchar(80) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            player_name varchar(191) NOT NULL DEFAULT '',
            score int(10) unsigned NOT NULL DEFAULT 0,
            rounds_played smallint(5) unsigned NOT NULL DEFAULT 0,
            correct_count smallint(5) unsigned NOT NULL DEFAULT 0,
            duration_seconds int(10) unsigned NOT NULL DEFAULT 0,
            accuracy decimal(5,2) NOT NULL DEFAULT 0.00,
            meta longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY game_score (game_slug, score, created_at),
            KEY user_game (user_id, game_slug),
            KEY best_attempt (game_slug, user_id, score, accuracy, duration_seconds, created_at, id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_sessions_table(): void {
        global $wpdb;

        $table = self::sessions_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            game_slug varchar(80) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            started_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            consumed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY user_game_open (user_id, game_slug, consumed_at, expires_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_teams_tables(): void {
        global $wpdb;

        $teams_table = self::teams_table();
        $members_table = self::team_members_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$teams_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            invite_code varchar(32) NOT NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY invite_code (invite_code)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $sql = "CREATE TABLE {$members_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            joined_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_team (user_id),
            KEY team_id (team_id)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    private static function create_ai_model_lab_attempts_table(): bool {
        global $wpdb;

        $table = self::ai_model_lab_attempts_table();

        // Preserve the incompatible prototype schema before creating the current table.
        if (self::table_exists($table)) {
            $old_column = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'dial_selections_json'");
            if (! empty($old_column)) {
                $backup = substr($table, 0, 40) . '_legacy_' . bin2hex(random_bytes(6));
                if (false === $wpdb->query("RENAME TABLE `{$table}` TO `{$backup}`")) return false;
                update_option('edu_games_legacy_ai_backup', $backup, false);
                error_log('[Edu Games] Preserved legacy AI records in ' . $backup);
            }
        }

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            activity_key varchar(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'phase1_pending',
            model_key varchar(40) NULL,
            submitted_labels_json text NULL,
            correct_count int(10) unsigned NULL,
            total_count int(10) unsigned NULL,
            phase1_score decimal(6,2) NULL,
            phase1_attempts int(10) unsigned NOT NULL DEFAULT 0,
            phase2_accuracy decimal(6,4) NULL,
            phase2_score decimal(6,2) NULL,
            phase2_attempts int(10) unsigned NOT NULL DEFAULT 0,
            total_score decimal(6,2) NULL,
            started_at datetime NOT NULL,
            phase1_submitted_at datetime NULL,
            phase2_submitted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_activity (user_id, activity_key),
            KEY activity_key (activity_key)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        return !empty($wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'submitted_labels_json'"));
    }

    private static function scores_table_exists(): bool {
        return self::table_exists(self::scores_table());
    }

    private static function table_exists(string $table): bool {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
