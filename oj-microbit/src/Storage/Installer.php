<?php
declare(strict_types=1);
namespace OjMicrobit\Storage;

final class Installer
{
    public const SCHEMA_VERSION = '5';
    public static function maybeInstall(): void
    {
        if (get_option('oj_microbit_schema_version', '') !== self::SCHEMA_VERSION && defined('OJ_PLATFORM_VERSION')) { self::install(); }
    }
    public static function install(): bool
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'oj_microbit_';
        dbDelta("CREATE TABLE {$prefix}exercises (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            owner_id bigint unsigned NOT NULL,
            title varchar(240) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'draft',
            revision bigint unsigned NOT NULL DEFAULT 1,
            archived tinyint NOT NULL DEFAULT 0,
            audience_mode varchar(16) NOT NULL DEFAULT 'site',
            data_json longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_recent (owner_id,archived,id),
            KEY published_recent (status,archived,id),
            KEY active_recent (archived,id),
            KEY author_records (owner_id,id),
            KEY site_recent (audience_mode,status,archived,id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$prefix}exercise_classes (
            exercise_id bigint unsigned NOT NULL,
            class_id bigint unsigned NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'draft',
            archived tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (exercise_id,class_id),
            KEY class_recent (class_id,status,archived,exercise_id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$prefix}exercise_groups (
            exercise_id bigint unsigned NOT NULL,
            group_id bigint unsigned NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'draft',
            archived tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (exercise_id,group_id),
            KEY group_recent (group_id,status,archived,exercise_id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$prefix}projects (
            exercise_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            revision bigint unsigned NOT NULL DEFAULT 1,
            data_json longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (exercise_id,user_id),
            KEY personal_projects (user_id,exercise_id),
            KEY project_expiry (updated_at,exercise_id,user_id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$prefix}attempts (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            exercise_id bigint unsigned NOT NULL,
            exercise_revision bigint unsigned NOT NULL,
            assignment_id bigint unsigned NOT NULL DEFAULT 0,
            user_id bigint unsigned NOT NULL,
            project_json longtext NOT NULL,
            result_json longtext NOT NULL,
            result_status varchar(24) NOT NULL DEFAULT '',
            result_passed tinyint NULL,
            result_score decimal(6,2) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY exercise_records (exercise_id,user_id,id),
            KEY exercise_recent (exercise_id,id),
            KEY personal_records (user_id,id),
            KEY attempt_expiry (created_at,id),
            KEY summary_queue (result_status,id),
            KEY scope_recent (assignment_id,exercise_id,id),
            KEY scope_records (assignment_id,exercise_id,user_id,id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$prefix}assignments (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            class_id bigint unsigned NOT NULL,
            exercise_id bigint unsigned NOT NULL,
            exercise_revision bigint unsigned NOT NULL,
            title varchar(240) NOT NULL,
            owner_id bigint unsigned NOT NULL,
            revision bigint unsigned NOT NULL DEFAULT 1,
            archived tinyint NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY assignment_class_exercise (class_id,exercise_id),
            KEY assignment_class_recent (class_id,archived,id),
            KEY assignment_class_records (class_id,id),
            KEY assignment_owner_records (owner_id,id),
            KEY assignment_exercise_records (exercise_id,id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$prefix}lesson_projects (
            assignment_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            revision bigint unsigned NOT NULL DEFAULT 1,
            data_json longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (assignment_id,user_id),
            KEY lesson_personal_projects (user_id,assignment_id),
            KEY lesson_project_expiry (updated_at,assignment_id,user_id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$prefix}lesson_results (
            assignment_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            attempt_id bigint unsigned NOT NULL,
            exercise_revision bigint unsigned NOT NULL,
            result_status varchar(24) NOT NULL,
            result_passed tinyint NULL,
            result_score decimal(6,2) NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (assignment_id,user_id),
            KEY lesson_personal_results (user_id,assignment_id),
            KEY lesson_result_expiry (updated_at,assignment_id,user_id)
        ) ENGINE=InnoDB {$charset};");
        dbDelta("CREATE TABLE {$prefix}limits (
            scope_key varchar(100) NOT NULL,
            window_start bigint unsigned NOT NULL,
            hits int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (scope_key),
            KEY limit_expiry (window_start,scope_key)
        ) {$charset};");
        $required = [
            'exercises' => ['id', 'owner_id', 'title', 'status', 'revision', 'archived', 'audience_mode', 'data_json', 'created_at', 'updated_at'],
            'exercise_classes' => ['exercise_id', 'class_id', 'status', 'archived'],
            'exercise_groups' => ['exercise_id', 'group_id', 'status', 'archived'],
            'projects' => ['exercise_id', 'user_id', 'revision', 'data_json', 'updated_at'],
            'attempts' => ['id', 'exercise_id', 'exercise_revision', 'assignment_id', 'user_id', 'project_json', 'result_json', 'result_status', 'result_passed', 'result_score', 'created_at'],
            'assignments' => ['id', 'class_id', 'exercise_id', 'exercise_revision', 'title', 'owner_id', 'revision', 'archived', 'created_at', 'updated_at'],
            'lesson_projects' => ['assignment_id', 'user_id', 'revision', 'data_json', 'updated_at'],
            'lesson_results' => ['assignment_id', 'user_id', 'attempt_id', 'exercise_revision', 'result_status', 'result_passed', 'result_score', 'updated_at'],
            'limits' => ['scope_key', 'window_start', 'hits'],
        ];
        foreach ($required as $name => $columns) {
            $rows = $wpdb->get_results('SHOW COLUMNS FROM `' . str_replace('`', '``', $prefix . $name) . '`', ARRAY_A);
            if (!is_array($rows) || (string) $wpdb->last_error !== '' || array_diff($columns, array_column($rows, 'Field')) !== []) { return false; }
        }
        foreach (self::indexes() as $name => $indexes) {
            $rows = $wpdb->get_results('SHOW INDEX FROM `' . str_replace('`', '``', $prefix . $name) . '`', ARRAY_A);
            if (!is_array($rows) || (string) $wpdb->last_error !== '') { return false; }
            $actual = []; $uniqueness = [];
            foreach ($rows as $row) { $actual[$row['Key_name']][(int) $row['Seq_in_index']] = $row['Column_name']; $uniqueness[$row['Key_name']] = $row['Non_unique'] ?? null; }
            if ($name === 'assignments' && (!array_key_exists('assignment_class_exercise', $uniqueness) || (string) $uniqueness['assignment_class_exercise'] !== '0')) { return false; }
            foreach ($indexes as $key => $columns) {
                if (!isset($actual[$key])) { return false; }
                ksort($actual[$key]);
                if (array_values($actual[$key]) !== $columns) { return false; }
            }
        }
        // The audience links and exercise CAS must commit atomically. Do not
        // silently mark a non-transactional legacy table ready or rewrite it.
        foreach (['exercises', 'exercise_classes', 'exercise_groups', 'assignments', 'lesson_projects', 'lesson_results', 'attempts'] as $name) {
            $rows = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $prefix . $name), ARRAY_A);
            if (!is_array($rows) || count($rows) !== 1 || (string) $wpdb->last_error !== '' || strcasecmp((string) ($rows[0]['Engine'] ?? ''), 'InnoDB') !== 0) { return false; }
        }
        update_option('oj_microbit_schema_version', self::SCHEMA_VERSION, false);
        return get_option('oj_microbit_schema_version') === self::SCHEMA_VERSION;
    }
    public static function indexes(): array
    {
        return [
            'exercises' => ['PRIMARY' => ['id'], 'owner_recent' => ['owner_id', 'archived', 'id'], 'published_recent' => ['status', 'archived', 'id'], 'active_recent' => ['archived', 'id'], 'author_records' => ['owner_id', 'id'], 'site_recent' => ['audience_mode', 'status', 'archived', 'id']],
            'exercise_classes' => ['PRIMARY' => ['exercise_id', 'class_id'], 'class_recent' => ['class_id', 'status', 'archived', 'exercise_id']],
            'exercise_groups' => ['PRIMARY' => ['exercise_id', 'group_id'], 'group_recent' => ['group_id', 'status', 'archived', 'exercise_id']],
            'projects' => ['PRIMARY' => ['exercise_id', 'user_id'], 'personal_projects' => ['user_id', 'exercise_id'], 'project_expiry' => ['updated_at', 'exercise_id', 'user_id']],
            'attempts' => ['PRIMARY' => ['id'], 'exercise_records' => ['exercise_id', 'user_id', 'id'], 'exercise_recent' => ['exercise_id', 'id'], 'personal_records' => ['user_id', 'id'], 'attempt_expiry' => ['created_at', 'id'], 'summary_queue' => ['result_status', 'id'], 'scope_recent' => ['assignment_id', 'exercise_id', 'id'], 'scope_records' => ['assignment_id', 'exercise_id', 'user_id', 'id']],
            'assignments' => ['PRIMARY' => ['id'], 'assignment_class_exercise' => ['class_id', 'exercise_id'], 'assignment_class_recent' => ['class_id', 'archived', 'id'], 'assignment_class_records' => ['class_id', 'id'], 'assignment_owner_records' => ['owner_id', 'id'], 'assignment_exercise_records' => ['exercise_id', 'id']],
            'lesson_projects' => ['PRIMARY' => ['assignment_id', 'user_id'], 'lesson_personal_projects' => ['user_id', 'assignment_id'], 'lesson_project_expiry' => ['updated_at', 'assignment_id', 'user_id']],
            'lesson_results' => ['PRIMARY' => ['assignment_id', 'user_id'], 'lesson_personal_results' => ['user_id', 'assignment_id'], 'lesson_result_expiry' => ['updated_at', 'assignment_id', 'user_id']],
            'limits' => ['PRIMARY' => ['scope_key'], 'limit_expiry' => ['window_start', 'scope_key']],
        ];
    }
}
