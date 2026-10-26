<?php
declare(strict_types=1);

namespace FocusTracker\Database;

final class Installer {
    private const MAINTENANCE_LOCK = 'focus_tracker_schema_maintenance_lock';
    private const RETRY_OPTION = 'focus_tracker_schema_retry';
    private const RETRY_MS = 60000;

    public static function maybe_install(): void {
        $now = \FocusTracker\Support\Clock::now_ms();
        $health = get_option('focus_tracker_schema_health', array());
        $needs_install = get_option('focus_tracker_db_version') !== FOCUS_TRACKER_VERSION || empty($health['ready']);
        if (! $needs_install && (int) ($health['checkedAtMs'] ?? 0) >= $now - 600000) { return; }
        $retry = get_option(self::RETRY_OPTION, array());
        if (($retry['version'] ?? '') === FOCUS_TRACKER_VERSION && (int) ($retry['afterMs'] ?? 0) > $now) { return; }
        $lease = self::acquire_maintenance($now);
        if ($lease === null) { return; }
        try {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare('SELECT option_name, option_value FROM ' . $wpdb->options . ' WHERE option_name IN (%s, %s, %s)', 'focus_tracker_schema_health', 'focus_tracker_db_version', self::RETRY_OPTION), ARRAY_A);
            if (! is_array($rows) || '' !== (string) $wpdb->last_error) { throw new \RuntimeException('schema_state_read'); }
            $durable = array_column($rows, 'option_value', 'option_name');
            $health = maybe_unserialize($durable['focus_tracker_schema_health'] ?? '');
            $retry = maybe_unserialize($durable[self::RETRY_OPTION] ?? '');
            $needs_install = ($durable['focus_tracker_db_version'] ?? '') !== FOCUS_TRACKER_VERSION || empty($health['ready']);
            if ((! $needs_install && (int) ($health['checkedAtMs'] ?? 0) >= $now - 600000)
                || (($retry['version'] ?? '') === FOCUS_TRACKER_VERSION && (int) ($retry['afterMs'] ?? 0) > $now)) { return; }
            // Persist the retry boundary before DDL, including a worker that
            // exits unexpectedly. A failed installation remains unavailable.
            if (! update_option(self::RETRY_OPTION, array('version' => FOCUS_TRACKER_VERSION, 'afterMs' => $now + self::RETRY_MS), false)) { return; }
            if ($needs_install) { self::install(); }
            else { update_option('focus_tracker_schema_health', self::inspect_schema(), false); }
        } catch (\Throwable $error) {
            update_option('focus_tracker_schema_health', array('ready' => false, 'issues' => array('maintenance_failed'), 'checkedAtMs' => $now), false);
        } finally {
            self::release_maintenance($lease);
        }
    }

    private static function acquire_maintenance(int $now): ?string {
        global $wpdb;
        $value = wp_json_encode(array('token' => bin2hex(random_bytes(16)), 'expiresAtMs' => $now + 120000));
        if (add_option(self::MAINTENANCE_LOCK, $value, '', false)) { return $value; }
        // Read durable state; another worker may have refreshed the lease
        // after this request's WordPress option cache was populated.
        $old = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s', self::MAINTENANCE_LOCK));
        $decoded = is_string($old) ? json_decode($old, true) : null;
        if (! is_string($old) || (is_array($decoded) && (int) ($decoded['expiresAtMs'] ?? 0) > $now)) { return null; }
        $changed = $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->options . ' SET option_value = %s WHERE option_name = %s AND option_value = %s', $value, self::MAINTENANCE_LOCK, $old));
        if ($changed !== 1) { return null; }
        wp_cache_delete(self::MAINTENANCE_LOCK, 'options');
        return $value;
    }

    private static function release_maintenance(string $lease): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->options . ' WHERE option_name = %s AND option_value = %s', self::MAINTENANCE_LOCK, $lease));
        wp_cache_delete(self::MAINTENANCE_LOCK, 'options');
    }

    public static function ready(): bool {
        $health = get_option('focus_tracker_schema_health', array());
        return get_option('focus_tracker_db_version') === FOCUS_TRACKER_VERSION && ! empty($health['ready']);
    }

    public static function install(): bool {
        self::create_activities_table();
        self::create_participations_table();
        self::create_activity_links_table();
        self::create_events_table();
        self::create_scores_table();
        self::create_checks_tables();
        self::create_statistics_table();
        $health = self::inspect_schema();
        update_option('focus_tracker_schema_health', $health, false);
        if (! $health['ready']) {
            return false;
        }
        if (false === get_option('focus_tracker_statistics_coverage_since', false)) {
            // Mark only whole local days whose original raw-retention window
            // was available when this feature was installed. Never move this
            // boundary when an administrator later changes retention settings.
            $days = max(1, (int) \FocusTracker\Support\Settings::get('retention_days'));
            $boundary = (new \DateTimeImmutable('@' . (time() - $days * DAY_IN_SECONDS)))->setTimezone(wp_timezone())->setTime(0, 0)->modify('+1 day');
            update_option('focus_tracker_statistics_coverage_since', $boundary->getTimestamp() * 1000);
        }
        update_option('focus_tracker_db_version', FOCUS_TRACKER_VERSION);
        return true;
    }

    public static function activities_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'focus_tracker_activities';
    }

    public static function participations_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'focus_tracker_participations';
    }

    public static function events_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'focus_tracker_events';
    }

    public static function activity_links_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'focus_tracker_activity_links';
    }

    public static function scores_table(): string {
        global $wpdb;

        return $wpdb->prefix . 'focus_tracker_scores';
    }

    public static function checks_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'focus_tracker_checks';
    }

    public static function check_states_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'focus_tracker_check_states';
    }

    public static function statistics_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'focus_tracker_daily_statistics';
    }

    private static function create_activities_table(): void {
        global $wpdb;

        $table = self::activities_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            owner_user_id bigint(20) unsigned NOT NULL,
            context_type varchar(20) NOT NULL,
            context_id varchar(160) NOT NULL,
            problem_ids longtext NULL,
            starts_at datetime NOT NULL,
            ends_at datetime NOT NULL,
            weight_focus decimal(4,1) NOT NULL DEFAULT 8.0,
            weight_selfreg decimal(4,1) NOT NULL DEFAULT 6.0,
            weight_activity decimal(4,1) NOT NULL DEFAULT 6.0,
            status varchar(20) NOT NULL DEFAULT 'scheduled',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_status (owner_user_id, status),
            KEY context_window (context_type, context_id, starts_at, ends_at)
        ) ENGINE=InnoDB {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_participations_table(): void {
        global $wpdb;

        $table = self::participations_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            activity_id bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NOT NULL,
            problem_id bigint(20) unsigned NULL,
            session_uid varchar(64) NOT NULL,
            started_at datetime(3) NOT NULL,
            started_at_ms bigint(20) unsigned NOT NULL,
            last_heartbeat_at datetime(3) NOT NULL,
            last_heartbeat_at_ms bigint(20) unsigned NOT NULL,
            ended_at datetime(3) NULL,
            ended_at_ms bigint(20) unsigned NULL,
            end_reason varchar(20) NULL,
            raw_events_expired tinyint(1) NOT NULL DEFAULT 0,
            has_delivery_gaps tinyint(1) NOT NULL DEFAULT 0,
            event_revision bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_uid (session_uid),
            KEY activity_user (activity_id, user_id),
            KEY user_started (user_id, started_at)
        ) ENGINE=InnoDB {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_events_table(): void {
        global $wpdb;

        $table = self::events_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            participation_id bigint(20) unsigned NOT NULL,
            sequence_number bigint(20) unsigned NULL,
            event_type varchar(20) NOT NULL,
            occurred_at datetime(3) NOT NULL,
            occurred_at_ms bigint(20) unsigned NOT NULL,
            received_at datetime(3) NOT NULL,
            meta longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY participation_sequence (participation_id, sequence_number),
            KEY participation_type (participation_id, event_type),
            KEY occurred_at (occurred_at)
        ) ENGINE=InnoDB {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_scores_table(): void {
        global $wpdb;

        $table = self::scores_table();
        $charset_collate = $wpdb->get_charset_collate();

        // Keyed by (activity_id, user_id) rather than participation_id: a
        // learner's score merges every tab/page-load participation they
        // made during that activity (see Scoring\Activity_Score_Service),
        // so there is exactly one cached row per learner per activity.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            activity_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            total_active_seconds int(10) unsigned NOT NULL,
            total_hidden_seconds int(10) unsigned NOT NULL,
            focus_ratio decimal(5,2) NOT NULL,
            blur_count smallint(5) unsigned NOT NULL,
            avg_recovery_seconds decimal(8,2) NOT NULL,
            activity_window_ratio decimal(5,2) NOT NULL,
            score_focus decimal(4,1) NOT NULL,
            score_selfreg decimal(4,1) NOT NULL,
            score_activity decimal(4,1) NOT NULL,
            score_total decimal(4,1) NOT NULL,
            computed_at datetime NOT NULL,
            cache_signature varchar(64) NOT NULL DEFAULT '',
            finalized tinyint(1) NOT NULL DEFAULT 0,
            raw_events_expired tinyint(1) NOT NULL DEFAULT 0,
            abandoned_without_return tinyint(1) NOT NULL DEFAULT 0,
            has_observation_gaps tinyint(1) NOT NULL DEFAULT 0,
            has_delivery_gaps tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY activity_user (activity_id, user_id)
        ) ENGINE=InnoDB {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function create_activity_links_table(): void {
        global $wpdb;

        $table = self::activity_links_table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            participation_id bigint(20) unsigned NOT NULL,
            activity_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (participation_id, activity_id),
            KEY activity_participation (activity_id, participation_id)
        ) ENGINE=InnoDB {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function tables_exist(): bool {
        global $wpdb;

        foreach (array(self::activities_table(), self::participations_table(), self::activity_links_table(), self::events_table(), self::scores_table(), self::checks_table(), self::check_states_table(), self::statistics_table()) as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                return false;
            }
        }
        return true;
    }

    public static function inspect_schema(): array {
        global $wpdb;

        $required = array(
            self::activities_table() => array('id', 'starts_at', 'ends_at'),
            self::participations_table() => array('id', 'ended_at_ms', 'raw_events_expired', 'has_delivery_gaps', 'event_revision'),
            self::activity_links_table() => array('participation_id', 'activity_id'),
            self::events_table() => array('id', 'sequence_number', 'meta'),
            self::scores_table() => array('id', 'cache_signature', 'finalized', 'raw_events_expired', 'abandoned_without_return', 'has_observation_gaps', 'has_delivery_gaps'),
            self::checks_table() => array('id', 'user_id', 'problem_id', 'candidate_json', 'status', 'expires_at_ms', 'response_uid', 'activity_ids_json', 'window_start_ms', 'window_end_ms'),
            self::check_states_table() => array('user_id', 'issued_day', 'issued_today', 'next_allowed_at_ms'),
            self::statistics_table() => array('id', 'user_id', 'scope_key', 'local_day', 'time_zone', 'metrics_json', 'source_signature', 'archived'),
            $wpdb->options => array('option_name', 'option_value', 'autoload'),
        );
        $issues = array();
        $indexes = array(
            self::participations_table() => array('session_uid'),
            self::events_table() => array('participation_id', 'sequence_number'),
            self::activity_links_table() => array('participation_id', 'activity_id'),
            self::scores_table() => array('activity_id', 'user_id'),
            self::check_states_table() => array('user_id'),
            self::statistics_table() => array('user_id', 'scope_key', 'local_day', 'time_zone'),
            $wpdb->options => array('option_name'),
        );
        foreach ($required as $table => $columns) {
            $label = $table === $wpdb->options ? 'wordpress_options' : str_replace($wpdb->prefix . 'focus_tracker_', '', $table);
            try {
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) { $issues[] = $label . ':missing_table'; continue; }
                $actual = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
                if (! is_array($actual) || array_diff($columns, array_column($actual, 'Field'))) { $issues[] = $label . ':missing_columns'; }
                $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table), ARRAY_A);
                if (! is_array($status) || strcasecmp((string) ($status['Engine'] ?? ''), 'InnoDB') !== 0) { $issues[] = $label . ':transaction_engine'; }
                if (isset($indexes[$table])) {
                    $keys = array();
                    foreach ($wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A) as $index) {
                        if ((int) $index['Non_unique'] === 0) { $keys[$index['Key_name']][(int) $index['Seq_in_index']] = $index['Column_name']; }
                    }
                    $found = false;
                    foreach ($keys as $key) { ksort($key); if (array_values($key) === $indexes[$table]) { $found = true; break; } }
                    if (! $found) { $issues[] = $label . ':unique_index'; }
                }
            } catch (\Throwable $error) {
                $issues[] = $label . ':inspection_failed';
            }
        }
        return array('ready' => empty($issues), 'issues' => $issues, 'checkedAtMs' => \FocusTracker\Support\Clock::now_ms());
    }

    private static function create_checks_tables(): void {
        global $wpdb;
        $table = self::checks_table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            problem_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned NULL,
            context_type varchar(20) NOT NULL DEFAULT 'general',
            context_id varchar(160) NULL,
            problem_hash varchar(64) NOT NULL,
            source_kind varchar(20) NOT NULL,
            source_hash varchar(64) NOT NULL,
            candidate_json longtext NOT NULL,
            issued_at_ms bigint(20) unsigned NOT NULL,
            expires_at_ms bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            answer_id varchar(64) NULL,
            response_uid varchar(64) NULL,
            responded_at_ms bigint(20) unsigned NULL,
            activity_ids_json longtext NULL,
            window_start_ms bigint(20) unsigned NULL,
            window_end_ms bigint(20) unsigned NULL,
            PRIMARY KEY  (id),
            KEY user_issued (user_id, issued_at_ms),
            KEY expiry_status (status, expires_at_ms)
        ) ENGINE=InnoDB {$charset};");
        $table = self::check_states_table();
        dbDelta("CREATE TABLE {$table} (
            user_id bigint(20) unsigned NOT NULL,
            issued_day varchar(10) NOT NULL DEFAULT '',
            issued_today int(10) unsigned NOT NULL DEFAULT 0,
            next_allowed_at_ms bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (user_id)
        ) ENGINE=InnoDB {$charset};");
    }

    private static function create_statistics_table(): void {
        global $wpdb;
        $table = self::statistics_table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            scope_key varchar(64) NOT NULL,
            scope_type varchar(20) NOT NULL,
            scope_id varchar(160) NOT NULL DEFAULT '',
            local_day date NOT NULL,
            time_zone varchar(64) NOT NULL,
            metrics_json longtext NOT NULL,
            source_signature varchar(64) NOT NULL,
            archived tinyint(1) NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_scope_day (user_id, scope_key, local_day, time_zone),
            KEY user_day (user_id, local_day)
        ) ENGINE=InnoDB {$charset};");
    }
}
