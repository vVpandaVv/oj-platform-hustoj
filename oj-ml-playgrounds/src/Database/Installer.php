<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Database;

final class Installer
{
    public const DB_VERSION = '4';
    public const DB_VERSION_OPTION = 'oj_ml_playgrounds_db_version';

    public static function install(): void
    {
        SchemaUpgrade::run([self::class, 'applySchema']);
    }

    /** Idempotent DDL; called only while SchemaUpgrade owns the migration lock. */
    public static function applySchema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = new Tables($wpdb);
        $collate = $wpdb->get_charset_collate();
        $sql = [];
        $sql[] = "CREATE TABLE {$tables->assignments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(200) NOT NULL,
            description longtext NOT NULL,
            task_type varchar(32) NOT NULL,
            context_type varchar(32) NOT NULL,
            context_id varchar(160) NOT NULL,
            context_revision varchar(160) NOT NULL,
            roster_revision varchar(160) NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'draft',
            policy_json longtext NOT NULL,
            rubric_json longtext NOT NULL,
            opens_at datetime NULL,
            due_at datetime NULL,
            max_attempts smallint(5) unsigned NOT NULL DEFAULT 1,
            revision bigint(20) unsigned NOT NULL DEFAULT 1,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            published_at datetime NULL,
            PRIMARY KEY  (id),
            KEY status_dates (status,opens_at,due_at),
            KEY context_lookup (context_type,context_id),
            KEY created_by (created_by)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$tables->assignmentTargets} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            assignment_id bigint(20) unsigned NOT NULL,
            target_type varchar(64) NOT NULL,
            target_key varchar(191) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY assignment_target (assignment_id,target_type,target_key),
            KEY target_lookup (target_type,target_key)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$tables->assignmentLearners} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            assignment_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            source varchar(32) NOT NULL DEFAULT 'context_snapshot',
            enrolled_at datetime NOT NULL,
            removed_at datetime NULL,
            max_attempts_override smallint(5) unsigned NULL,
            due_at_override datetime NULL,
            override_revision bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY assignment_learner (assignment_id,user_id),
            KEY user_assignment (user_id,assignment_id),
            KEY active_assignment (assignment_id,removed_at)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$tables->attempts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            assignment_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            attempt_no smallint(5) unsigned NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'in_progress',
            idempotency_key char(64) NULL,
            artifact_id bigint(20) unsigned NULL,
            policy_revision bigint(20) unsigned NOT NULL,
            client_app_version varchar(64) NULL,
            format_version int(10) unsigned NULL,
            progress_json longtext NULL,
            last_progress_at datetime NULL,
            failure_code varchar(64) NULL,
            failure_json longtext NULL,
            late tinyint(1) unsigned NOT NULL DEFAULT 0,
            inspection_retries smallint(5) unsigned NOT NULL DEFAULT 0,
            next_inspection_at datetime NULL,
            received_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            UNIQUE KEY assignment_attempt (assignment_id,user_id,attempt_no),
            UNIQUE KEY assignment_idempotency (assignment_id,user_id,idempotency_key),
            KEY active_attempt (assignment_id,user_id,status),
            KEY artifact_id (artifact_id),
            KEY received_at (received_at),
            KEY inspection_queue (status,next_inspection_at)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$tables->artifacts} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            attempt_id bigint(20) unsigned NOT NULL,
            storage_key varchar(255) NOT NULL,
            original_name varchar(255) NOT NULL,
            sha256 char(64) NOT NULL,
            bytes bigint(20) unsigned NOT NULL,
            mime_type varchar(100) NOT NULL,
            created_at datetime NOT NULL,
            deleted_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid (uuid),
            UNIQUE KEY attempt_id (attempt_id),
            UNIQUE KEY storage_key (storage_key),
            KEY sha256 (sha256),
            KEY retention (created_at,deleted_at)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$tables->evaluations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attempt_id bigint(20) unsigned NOT NULL,
            status varchar(32) NOT NULL,
            evaluator varchar(100) NOT NULL,
            evaluator_version varchar(100) NOT NULL,
            evaluator_digest varchar(191) NULL,
            findings_json longtext NOT NULL,
            facts_json longtext NOT NULL,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY attempt_created (attempt_id,created_at),
            KEY status (status)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$tables->reviews} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attempt_id bigint(20) unsigned NOT NULL,
            evaluation_id bigint(20) unsigned NULL,
            reviewer_id bigint(20) unsigned NOT NULL,
            version bigint(20) unsigned NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'draft',
            score decimal(10,2) NULL,
            max_score decimal(10,2) NULL,
            feedback longtext NOT NULL,
            details_json longtext NULL,
            rubric_revision bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            released_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY attempt_version (attempt_id,version),
            KEY attempt_status (attempt_id,status),
            KEY reviewer_id (reviewer_id)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$tables->events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            assignment_id bigint(20) unsigned NULL,
            attempt_id bigint(20) unsigned NULL,
            actor_user_id bigint(20) unsigned NULL,
            event_type varchar(64) NOT NULL,
            payload_json longtext NOT NULL,
            correlation_id varchar(100) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY assignment_created (assignment_id,created_at),
            KEY attempt_created (attempt_id,created_at),
            KEY correlation_id (correlation_id)
        ) {$collate};";

        foreach ($sql as $statement) {
            dbDelta(str_replace(") {$collate};", ") ENGINE=InnoDB {$collate};", $statement));
            SchemaUpgrade::assertDatabase($wpdb);
        }
    }

    public static function maybeUpgrade(): void
    {
        if ((string) get_option(self::DB_VERSION_OPTION, '') !== self::DB_VERSION) {
            throw new \OJML\Playgrounds\Domain\Problem('OJ_ML_MIGRATION_REQUIRED', 'ML 데이터베이스 업데이트가 필요합니다. 교사 메뉴 › ML 실습 › 설정에서 DB 업데이트를 실행하세요.', 503);
        }
        SchemaUpgrade::assertRuntimeOwnership();
    }
}
