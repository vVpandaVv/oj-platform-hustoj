<?php
declare(strict_types=1);
namespace OjPrimm\Storage;

final class Installer
{
    public static function maybeInstall(): void
    {
        if (get_option('oj_primm_schema_version', '') === '3') { return; }
        $revision = '3:' . (defined('OJ_PRIMM_VERSION') ? OJ_PRIMM_VERSION : 'development');
        $retry = get_option('oj_primm_schema_retry', []);
        if (is_array($retry) && ($retry['revision'] ?? '') === $revision && (int) ($retry['after'] ?? 0) > time()) { return; }
        global $wpdb;
        if (! is_object($wpdb) || ! method_exists($wpdb, 'get_var')) { return; }
        $lock = 'oj_primm_schema_' . substr(hash('sha256', (string) ($wpdb->dbname ?? '') . ':' . $wpdb->prefix), 0, 32);
        try {
            if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') { return; }
            try {
                // Connection-owned lock is also released if the request terminates unexpectedly.
                // Another worker may have completed or failed after this request cached its options.
                $stored = self::migrationState();
                if ($stored === null || $stored['version'] === '3') { return; }
                $retry = $stored['retry'];
                if (($retry['revision'] ?? '') === $revision && (int) ($retry['after'] ?? 0) > time()) { return; }
                $retry = ['revision' => $revision, 'after' => time() + 60];
                update_option('oj_primm_schema_retry', $retry, false);
                $stored = self::migrationState();
                // A failed option write must not allow unthrottled DDL attempts.
                if ($stored === null || ($stored['retry']['revision'] ?? '') !== $revision
                    || (int) ($stored['retry']['after'] ?? 0) < $retry['after']) { return; }
                if (self::applySchema()) { delete_option('oj_primm_schema_retry'); }
            } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
        } catch (\Throwable) {
            // A failed migration must not turn every unrelated page or poll into another DDL attempt.
            update_option('oj_primm_schema_retry', ['revision' => $revision, 'after' => time() + 60], false);
        }
    }

    public static function install(): bool
    {
        global $wpdb;
        if (! is_object($wpdb)) { return false; }
        $lock = 'oj_primm_schema_' . substr(hash('sha256', (string) ($wpdb->dbname ?? '') . ':' . $wpdb->prefix), 0, 32);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') { return false; }
        try { return self::applySchema(); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    private static function applySchema(): bool
    {
        global $wpdb;
        if (! is_object($wpdb)) { return false; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $activities = $wpdb->prefix . 'oj_primm_activities';
        $attempts = $wpdb->prefix . 'oj_primm_attempts';
        $events = $wpdb->prefix . 'oj_primm_events';
        $generations = $wpdb->prefix . 'oj_primm_generations';
        $control = $wpdb->prefix . 'oj_primm_generation_control';
        $practices = $wpdb->prefix . 'oj_primm_practices';
        dbDelta("CREATE TABLE {$activities} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            creator_user_id bigint unsigned NOT NULL,
            context_type varchar(10) NOT NULL,
            context_id varchar(160) NOT NULL,
            title varchar(191) NOT NULL,
            family_id bigint unsigned NOT NULL DEFAULT 0,
            parent_activity_id bigint unsigned DEFAULT NULL,
            content_version bigint unsigned NOT NULL DEFAULT 1,
            revision bigint unsigned NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'active',
            request_key_hash char(64) DEFAULT NULL,
            data_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY context_lookup (context_type,context_id),
            KEY creator_lookup (creator_user_id),
            KEY family_lookup (family_id,id),
            UNIQUE KEY creator_request (creator_user_id,request_key_hash)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$attempts} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            activity_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            version bigint unsigned NOT NULL,
            state_json longtext NOT NULL,
            last_request_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY learner_activity (activity_id,user_id),
            KEY user_lookup (user_id),
            KEY retention_lookup (updated_at)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$events} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            attempt_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            stage varchar(20) NOT NULL,
            event_type varchar(30) NOT NULL,
            payload_json longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY attempt_lookup (attempt_id,id),
            KEY user_lookup (user_id),
            KEY retention_lookup (created_at)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$generations} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            creator_user_id bigint unsigned NOT NULL,
            fingerprint char(64) NOT NULL,
            status varchar(16) NOT NULL,
            activity_id bigint unsigned DEFAULT NULL,
            error_message varchar(500) NOT NULL,
            lease_token char(48) NOT NULL,
            lease_expires_at datetime DEFAULT NULL,
            next_attempt_at datetime DEFAULT NULL,
            data_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY creator_fingerprint (creator_user_id,fingerprint),
            KEY queue_lookup (status,lease_expires_at),
            KEY retention_lookup (updated_at)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$control} (
            scope_key varchar(96) NOT NULL,
            data_json longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (scope_key)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$practices} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            activity_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            fingerprint char(64) NOT NULL,
            status varchar(20) NOT NULL,
            lease_token char(48) NOT NULL,
            lease_expires_at datetime DEFAULT NULL,
            data_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY learner_fingerprint (user_id,fingerprint),
            KEY activity_learner (activity_id,user_id,id),
            KEY retention_lookup (updated_at)
        ) ENGINE=InnoDB {$charset};");
        foreach ([$activities, $attempts, $events, $generations, $control, $practices] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { return false; }
            $engine = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
            if (! is_array($engine) || strcasecmp((string) ($engine['Engine'] ?? ''), 'InnoDB') !== 0) { return false; }
        }
        $columns = [
            $activities => ['id', 'creator_user_id', 'context_type', 'context_id', 'title', 'family_id', 'parent_activity_id', 'content_version', 'revision', 'status', 'request_key_hash', 'data_json', 'created_at', 'updated_at'],
            $attempts => ['id', 'activity_id', 'user_id', 'version', 'state_json', 'last_request_hash', 'created_at', 'updated_at'],
            $events => ['id', 'attempt_id', 'user_id', 'stage', 'event_type', 'payload_json', 'created_at'],
            $generations => ['id', 'creator_user_id', 'fingerprint', 'status', 'activity_id', 'error_message', 'lease_token', 'lease_expires_at', 'next_attempt_at', 'data_json', 'created_at', 'updated_at'],
            $control => ['scope_key', 'data_json', 'updated_at'],
            $practices => ['id', 'activity_id', 'user_id', 'fingerprint', 'status', 'lease_token', 'lease_expires_at', 'data_json', 'created_at', 'updated_at'],
        ];
        foreach ($columns as $table => $required) {
            $actual = $wpdb->get_results('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`', ARRAY_A);
            if (! is_array($actual) || array_diff($required, array_column($actual, 'Field')) !== []) { return false; }
        }
        $keys = [
            $activities => ['PRIMARY' => ['id'], 'creator_request' => ['creator_user_id', 'request_key_hash']],
            $attempts => ['PRIMARY' => ['id'], 'learner_activity' => ['activity_id', 'user_id']],
            $events => ['PRIMARY' => ['id']],
            $generations => ['PRIMARY' => ['id'], 'creator_fingerprint' => ['creator_user_id', 'fingerprint']],
            $control => ['PRIMARY' => ['scope_key']],
            $practices => ['PRIMARY' => ['id'], 'learner_fingerprint' => ['user_id', 'fingerprint']],
        ];
        foreach ($keys as $table => $required) {
            $actual = $wpdb->get_results('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '`', ARRAY_A);
            if (! is_array($actual)) { return false; }
            foreach ($required as $name => $fields) {
                $parts = array_values(array_filter($actual, static fn (array $part): bool => ($part['Key_name'] ?? '') === $name && (int) ($part['Non_unique'] ?? 1) === 0));
                usort($parts, static fn (array $left, array $right): int => (int) ($left['Seq_in_index'] ?? 0) <=> (int) ($right['Seq_in_index'] ?? 0));
                if (array_column($parts, 'Column_name') !== $fields) { return false; }
            }
        }
        update_option('oj_primm_schema_version', '3', false);
        return (self::migrationState()['version'] ?? '') === '3';
    }

    /** Read committed migration markers without trusting WordPress option caches. */
    private static function migrationState(): ?array
    {
        global $wpdb;
        if (! is_object($wpdb) || ! isset($wpdb->options) || ! method_exists($wpdb, 'get_results')) { return null; }
        $table = '`' . str_replace('`', '``', (string) $wpdb->options) . '`';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT option_name, option_value FROM ' . $table . ' WHERE option_name IN (%s,%s)',
            'oj_primm_schema_version', 'oj_primm_schema_retry'
        ), ARRAY_A);
        if (! is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') { return null; }
        $state = ['version' => '', 'retry' => []];
        foreach ($rows as $row) {
            if (($row['option_name'] ?? '') === 'oj_primm_schema_version') {
                $state['version'] = (string) ($row['option_value'] ?? '');
            } elseif (($row['option_name'] ?? '') === 'oj_primm_schema_retry') {
                $retry = @unserialize((string) ($row['option_value'] ?? ''), ['allowed_classes' => false]);
                $state['retry'] = is_array($retry) ? $retry : [];
            }
        }
        return $state;
    }
}
