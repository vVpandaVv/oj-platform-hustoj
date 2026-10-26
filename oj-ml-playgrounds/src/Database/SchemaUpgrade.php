<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Database;

use OJML\Playgrounds\Domain\Problem;
use RuntimeException;
use Throwable;

/** Additive 0.2.x → 1.0 schema migration. No automatic front-end DDL. */
final class SchemaUpgrade
{
    public const RUNTIME_OWNERSHIP_OPTION = 'oj_ml_runtime_ownership';
    private const COLUMNS = [
        'assignments' => 'id,title,description,task_type,context_type,context_id,context_revision,roster_revision,status,policy_json,rubric_json,opens_at,due_at,max_attempts,revision,created_by,created_at,updated_at,published_at',
        'assignment_targets' => 'id,assignment_id,target_type,target_key,created_at',
        'assignment_learners' => 'id,assignment_id,user_id,source,enrolled_at,removed_at,max_attempts_override,due_at_override,override_revision',
        'attempts' => 'id,uuid,assignment_id,user_id,attempt_no,status,idempotency_key,artifact_id,policy_revision,client_app_version,format_version,progress_json,last_progress_at,failure_code,failure_json,late,inspection_retries,next_inspection_at,received_at,created_at,updated_at',
        'artifacts' => 'id,uuid,attempt_id,storage_key,original_name,sha256,bytes,mime_type,created_at,deleted_at',
        'evaluations' => 'id,attempt_id,status,evaluator,evaluator_version,evaluator_digest,findings_json,facts_json,created_at,completed_at',
        'reviews' => 'id,attempt_id,evaluation_id,reviewer_id,version,status,score,max_score,feedback,details_json,rubric_revision,created_at,released_at',
        'events' => 'id,assignment_id,attempt_id,actor_user_id,event_type,payload_json,correlation_id,created_at',
    ];

    public static function assertSingleSite(): void
    {
        if (function_exists('is_multisite') && is_multisite()) {
            throw new Problem('OJ_ML_MULTISITE_UNSUPPORTED', 'ML 실습은 전용 DB의 단일 WordPress 사이트만 지원합니다.', 503);
        }
    }

    public static function run(callable $apply): void
    {
        global $wpdb;
        self::assertSingleSite();
        delete_option(self::RUNTIME_OWNERSHIP_OPTION);
        $lock = 'oj_ml_schema_' . substr(hash('sha256', (string) $wpdb->dbname), 0, 32);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            throw new RuntimeException('ML 스키마 업데이트가 이미 실행 중이거나 DB 잠금을 사용할 수 없습니다.');
        }
        try {
            $version = (string) get_option(Installer::DB_VERSION_OPTION, '');
            if (!in_array($version, ['', '1', '2', '3', Installer::DB_VERSION], true)) {
                throw new RuntimeException('지원하지 않는 ML 스키마입니다. 코드만 다운그레이드하지 마세요.');
            }
            self::assertOwnership();
            foreach (array_keys(self::COLUMNS) as $name) {
                $engine = self::engine($wpdb, 'oj_ml_' . $name);
                if ($engine !== null && strcasecmp((string) $engine, 'InnoDB') !== 0) {
                    throw new RuntimeException('기존 ML 테이블을 InnoDB로 변환한 뒤 다시 시도하세요: ' . $name);
                }
            }
            // 1/2/3 → 4 is additive. If interrupted, the same step safely resumes.
            $apply();
            self::verify($wpdb);
            $owner = $wpdb->get_var("SELECT id FROM oj_ml_events WHERE event_type = 'installation.bound' LIMIT 1");
            self::assertDatabase($wpdb);
            if ($owner === null && $wpdb->insert('oj_ml_events', [
                'event_type' => 'installation.bound',
                'payload_json' => wp_json_encode(['prefix' => $wpdb->prefix]),
                'correlation_id' => wp_generate_uuid4(),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]) === false) {
                throw new RuntimeException('ML 설치 소유권을 기록하지 못했습니다.');
            }
            update_option(Installer::DB_VERSION_OPTION, Installer::DB_VERSION, false);
            if ((string) get_option(Installer::DB_VERSION_OPTION, '') !== Installer::DB_VERSION) {
                throw new RuntimeException('ML 스키마 버전을 기록하지 못했습니다.');
            }
            delete_option('oj_ml_playgrounds_migration_error');
        } catch (Throwable $error) {
            update_option('oj_ml_playgrounds_migration_error', 'migration_failed', false);
            throw $error;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function assertDatabase(object $wpdb): void
    {
        if ((string) ($wpdb->last_error ?? '') !== '') {
            throw new Problem('OJ_ML_DATABASE_FAILED', '데이터베이스 작업에 실패했습니다. 운영 상태를 확인하세요.', 503);
        }
    }

    public static function assertOwnership(): void
    {
        self::verifyOwnership(false);
    }

    /** Runtime checks are bounded; migration and destructive operations always verify live binding. */
    public static function assertRuntimeOwnership(): void
    {
        global $wpdb;
        self::assertSingleSite();
        $identity = hash('sha256', serialize([
            (string) ($wpdb->dbname ?? ''), (string) ($wpdb->dbhost ?? ''), (string) $wpdb->prefix,
            defined('ABSPATH') ? ABSPATH : '', get_option('siteurl', ''), Installer::DB_VERSION,
            defined('OJ_ML_PLAYGROUNDS_VERSION') ? OJ_ML_PLAYGROUNDS_VERSION : '',
        ]));
        $cached = get_option(self::RUNTIME_OWNERSHIP_OPTION, []);
        $cached = is_array($cached) ? $cached : [];
        $age = time() - (int) ($cached['checked_at'] ?? 0);
        if (is_array($cached) && ($cached['identity'] ?? '') === $identity && $age >= 0) {
            if (($cached['valid'] ?? false) === true && $age < 300) { return; }
            if (($cached['valid'] ?? null) === false && $age < 30) {
                throw new Problem('OJ_ML_DATABASE_BINDING_UNAVAILABLE', 'ML 저장소 소유권을 확인하지 못했습니다. 잠시 후 다시 확인하세요.', 503);
            }
        }
        try {
            self::verifyOwnership(true);
            update_option(self::RUNTIME_OWNERSHIP_OPTION, ['identity' => $identity, 'valid' => true, 'checked_at' => time()], true);
        } catch (Throwable $error) {
            // Fail closed during retry backoff; a cached error never opens the routes.
            update_option(self::RUNTIME_OWNERSHIP_OPTION, ['identity' => $identity, 'valid' => false, 'checked_at' => time()], true);
            throw $error;
        }
    }

    private static function verifyOwnership(bool $requireBinding): void
    {
        global $wpdb;
        self::assertSingleSite();
        if (self::engine($wpdb, 'oj_ml_events') === null) {
            if ($requireBinding) { throw new Problem('OJ_ML_DATABASE_BINDING_UNAVAILABLE', 'ML 저장소 소유권을 확인하지 못했습니다.', 503); }
            return;
        }
        $owner = $wpdb->get_var("SELECT payload_json FROM oj_ml_events WHERE event_type = 'installation.bound' ORDER BY id LIMIT 1");
        self::assertDatabase($wpdb);
        if ($requireBinding && $owner === null) { throw new Problem('OJ_ML_DATABASE_BINDING_UNAVAILABLE', 'ML 저장소 소유권을 확인하지 못했습니다.', 503); }
        if ($owner !== null && (json_decode((string) $owner, true)['prefix'] ?? null) !== $wpdb->prefix) {
            throw new Problem('OJ_ML_DATABASE_OWNER_MISMATCH', '이 DB는 다른 WordPress 설치의 ML 저장소입니다. 전용 DB를 사용하세요.', 503);
        }
    }

    public static function verify(object $wpdb): void
    {
        foreach (self::COLUMNS as $name => $required) {
            $table = 'oj_ml_' . $name;
            if (strcasecmp((string) self::engine($wpdb, $table), 'InnoDB') !== 0) {
                throw new RuntimeException('ML 테이블이 없거나 InnoDB가 아닙니다: ' . $name);
            }
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
            self::assertDatabase($wpdb);
            if (array_diff(explode(',', $required), $columns) !== []) {
                throw new RuntimeException('ML 스키마 열 검증에 실패했습니다: ' . $name);
            }
            $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
            self::assertDatabase($wpdb);
            $actual = [];
            foreach ($indexes as $row) {
                $actual[$row['Key_name']]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
                $actual[$row['Key_name']]['unique'] = (int) $row['Non_unique'] === 0;
            }
            $expected = ['PRIMARY' => ['id', true]];
            $expected += match ($name) {
                'assignment_targets' => ['assignment_target' => ['assignment_id,target_type,target_key', true]],
                'assignment_learners' => ['assignment_learner' => ['assignment_id,user_id', true]],
                'attempts' => ['uuid' => ['uuid', true], 'assignment_attempt' => ['assignment_id,user_id,attempt_no', true], 'assignment_idempotency' => ['assignment_id,user_id,idempotency_key', true], 'inspection_queue' => ['status,next_inspection_at', false]],
                'artifacts' => ['uuid' => ['uuid', true], 'attempt_id' => ['attempt_id', true], 'storage_key' => ['storage_key', true]],
                'reviews' => ['attempt_version' => ['attempt_id,version', true]],
                default => [],
            };
            foreach ($expected as $key => [$fields, $unique]) {
                $value = $actual[$key] ?? ['columns' => [], 'unique' => false];
                ksort($value['columns']);
                if (array_values($value['columns']) !== explode(',', $fields) || $value['unique'] !== $unique) {
                    throw new RuntimeException('ML 인덱스 검증에 실패했습니다: ' . $name . '/' . $key);
                }
            }
        }
    }

    private static function engine(object $wpdb, string $table): ?string
    {
        $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
        self::assertDatabase($wpdb);
        return $engine === null ? null : (string) $engine;
    }
}
