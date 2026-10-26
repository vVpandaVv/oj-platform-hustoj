<?php
declare(strict_types=1);

namespace TeachingTools\Database;

final class Installer {
    public const SCHEMA_VERSION = '2.1.0';

    public static function maybe_install(): void {
        // The installed version is the normal request's schema marker. Keep
        // missing-table recovery on administrator requests, not every poll.
        if (get_option('teaching_tools_db_version') !== self::SCHEMA_VERSION
            || (is_admin() && ! self::sessions_table_exists())) {
            self::install();
        }
    }

    public static function install(): void {
        self::create_sessions_table();
        self::create_participants_table();
        self::create_session_hosts_table();
        self::backfill_session_hosts();
        self::create_seating_layouts_table();
        self::create_lesson_templates_table();
        update_option('teaching_tools_db_version', self::SCHEMA_VERSION);
    }

    public static function sessions_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'teaching_tools_sessions';
    }

    public static function participants_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'teaching_tools_session_participants';
    }

    public static function seating_layouts_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'teaching_tools_seating_layouts';
    }

    public static function session_hosts_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'teaching_tools_session_hosts';
    }

    public static function lesson_templates_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'teaching_tools_lesson_templates';
    }

    private static function create_sessions_table(): void {
        global $wpdb;

        $table = self::sessions_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            join_code varchar(8) NOT NULL,
            tool_type varchar(40) NOT NULL,
            mode varchar(10) NOT NULL DEFAULT 'solo',
            owner_user_id bigint(20) unsigned NOT NULL,
            context_type varchar(20) NULL,
            context_id varchar(160) NULL,
            state longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY join_code (join_code),
            KEY owner_status (owner_user_id, status),
            KEY status_expires (status, expires_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_participants_table(): void {
        global $wpdb;

        $table = self::participants_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            joined_at datetime NOT NULL,
            data longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_user (session_id, user_id),
            KEY session_id (session_id),
            KEY user_sessions (user_id, session_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_session_hosts_table(): void {
        global $wpdb;

        $table = self::session_hosts_table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(20) NOT NULL DEFAULT 'observer',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_user (session_id, user_id),
            KEY user_sessions (user_id, session_id),
            KEY session_role (session_id, role)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_seating_layouts_table(): void {
        global $wpdb;

        $table = self::seating_layouts_table();
        $charset_collate = $wpdb->get_charset_collate();

        // rows_count/cols_count, not rows/cols — ROWS is a reserved word as
        // of MySQL 8.0.2 (window-function FETCH/ROWS syntax) and an
        // unquoted column named that way can break on some setups.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint(20) unsigned NOT NULL,
            name varchar(191) NOT NULL,
            rows_count smallint(5) unsigned NOT NULL,
            cols_count smallint(5) unsigned NOT NULL,
            seats longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_name (owner_user_id, name)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function backfill_session_hosts(): void {
        global $wpdb;

        $sessions = self::sessions_table();
        $hosts = self::session_hosts_table();
        $wpdb->query(
            "INSERT IGNORE INTO {$hosts} (session_id, user_id, role, created_at)"
                . " SELECT id, owner_user_id, 'owner', created_at FROM {$sessions}"
        );
    }

    private static function create_lesson_templates_table(): void {
        global $wpdb;

        $table = self::lesson_templates_table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint(20) unsigned NOT NULL,
            name varchar(191) NOT NULL,
            steps longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_updated (owner_user_id, updated_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function sessions_table_exists(): bool {
        global $wpdb;

        $table = self::sessions_table();

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
