<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionProbe;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationCatalog;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyMigrationGateway;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationScan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationStage;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWorkResult;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SystemOperationPolicy;
use DomainException;
use JsonException;

final class WordPressLegacyMigrationGateway implements LegacyMigrationGateway
{
    private const JOB_TABLES = [
        'background_jobs',
        'background_job_items',
        'background_job_events',
        'background_job_steps',
    ];

    public function __construct(
        private readonly WordPressMigrationDatabase $database,
        private readonly LegacySourceLocator $sources,
        private readonly MigrationSchemaManager $schemas,
        private readonly MigrationDataCopier $copier,
        private readonly SystemOperationPolicy $operations,
        private readonly JudgeConnectionSettingsProvider $judgeSettings,
        private readonly JudgeConnectionProbe $judgeProbe
    ) {
    }

    public function scan(MigrationCatalog $catalog): MigrationScan
    {
        $tables = [];
        $blockers = [];
        $warnings = [];
        $fingerprint = [
            'database' => hash('sha256', $this->database->name()),
            'sources' => [],
            'targets' => [],
        ];

        foreach ($catalog->all() as $table) {
            $sourceTables = $this->sources->existing($table);
            $sourceRows = 0;
            $sourceState = null;
            if (count($sourceTables) > 1) {
                $blockers[] = $table->key() . ': 이전 원본 후보가 둘 이상입니다.';
            } elseif ($sourceTables !== []) {
                $sourceState = $this->database->inspect($sourceTables[0]);
                $sourceRows = $sourceState['rows'];
            } elseif ($table->hasLegacySource()) {
                $warnings[] = $table->key() . ': 이전 원본이 없어 빈 대상으로 처리합니다.';
            }

            $targetState = $this->database->inspect($table->targetTable());
            $targetOwned = $targetState['exists']
                && $this->schemas->isCurrentTarget($table);
            if ($targetState['exists']
                && ! $targetOwned
                && ! ($table->hasSameNameCollision() && $sourceTables !== [])) {
                $blockers[] = $table->targetTable()
                    . ': 현재 설치가 소유하지 않은 동명 목표 테이블입니다.';
            }
            $tables[$table->key()] = [
                'source_present' => $sourceTables !== [],
                'source_rows' => $sourceRows,
                'source_candidate_count' => count($sourceTables),
                'target_present' => $targetState['exists'],
                'target_rows' => $targetState['exists'] ? $targetState['rows'] : 0,
                'target_owned' => $targetOwned,
                'same_name_collision' => $table->hasSameNameCollision(),
            ];
            $sourceKey = $table->legacyLogicalName() ?? 'none:' . $table->key();
            if ($sourceState !== null) {
                $fingerprint['sources'][$sourceKey] = [
                    'engine' => $sourceState['engine'],
                    'collation' => $sourceState['collation'],
                    'columns' => $sourceState['column_fingerprint'],
                ];
            } elseif (! isset($fingerprint['sources'][$sourceKey])) {
                $fingerprint['sources'][$sourceKey] = ['missing' => true];
            }
            $fingerprint['targets'][$table->targetTable()] =
                $this->schemas->schema($table)->fingerprint();
        }

        foreach (self::JOB_TABLES as $jobTable) {
            $state = $this->database->inspect($jobTable);
            if (! $state['exists']) {
                $blockers[] = $jobTable . ': 이전 실행에 필요한 Jobs 테이블이 없습니다.';
            }
        }
        $legacyMap = $this->database->inspect($this->database->prefix() . 'oj_legacy_id_map');
        if ($legacyMap['exists']) {
            $warnings[] = 'legacy_id_map은 영구 이전하지 않으며 정리 후보로만 보고합니다.';
        }

        ksort($tables, SORT_STRING);
        ksort($fingerprint['sources'], SORT_STRING);
        ksort($fingerprint['targets'], SORT_STRING);
        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));
        sort($blockers, SORT_STRING);
        sort($warnings, SORT_STRING);

        return new MigrationScan(
            $tables,
            $blockers,
            $warnings,
            $this->activeLegacyPlugins(),
            hash('sha256', $this->json($fingerprint))
        );
    }

    public function execute(
        MigrationTable $table,
        MigrationPlan $plan
    ): MigrationWorkResult {
        if ($table->wave() !== $plan->wave()) {
            throw new DomainException('실행 계획과 테이블 파동이 다릅니다.');
        }

        return match ($plan->stage()) {
            MigrationStage::TargetSchema => $this->prepareTarget($table, $plan),
            MigrationStage::PreCopy => $this->copy($table, $plan, false),
            MigrationStage::FinalCopy => $this->copy($table, $plan, true),
            MigrationStage::Validation => $this->copier->validate($table, $plan),
            MigrationStage::Cutover => $this->cutover($table, $plan),
            MigrationStage::Resume => $this->resume($table, $plan),
            MigrationStage::Observation => $this->observe($table, $plan),
            MigrationStage::JudgeCompatibility => $this->judgeCompatibility(),
            MigrationStage::Cleanup => $this->cleanupReport($table, $plan),
            default => throw new DomainException('이 단계는 데이터 쓰기 Jobs로 실행할 수 없습니다.'),
        };
    }

    public function deactivateLegacyPlugins(array $pluginFiles): MigrationWorkResult
    {
        $allowed = [];
        foreach (array_values(array_unique($pluginFiles)) as $pluginFile) {
            if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\.php$/', $pluginFile) !== 1
                || $pluginFile === $this->currentPlugin()) {
                throw new DomainException('비활성화 대상 플러그인 파일이 올바르지 않습니다.');
            }
            $allowed[] = $pluginFile;
        }
        if ($allowed !== []) {
            if (! function_exists('deactivate_plugins')
                && defined('ABSPATH')
                && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if (! function_exists('deactivate_plugins')) {
                throw new DomainException('WordPress 플러그인 비활성화 API를 사용할 수 없습니다.');
            }
            deactivate_plugins($allowed, true, false);
        }
        $remaining = array_values(array_intersect($allowed, $this->activePlugins()));
        $needsAttention = $remaining !== [];

        return new MigrationWorkResult(
            $needsAttention
                ? 'migration.legacy_plugins_still_active'
                : 'migration.legacy_plugins_deactivated',
            $needsAttention
                ? '일부 기존 플러그인이 아직 활성 상태입니다.'
                : '승인된 기존 플러그인만 비활성화했습니다.',
            ['requested_count' => count($allowed)],
            ['remaining_count' => count($remaining)],
            $needsAttention
        );
    }

    private function prepareTarget(
        MigrationTable $table,
        MigrationPlan $plan
    ): MigrationWorkResult {
        $target = $this->schemas->prepare(
            $table,
            $plan,
            $this->sources->one($table)
        );

        return new MigrationWorkResult(
            'migration.target_table_ready',
            '목표 테이블을 준비했습니다.',
            ['target_key' => hash('sha256', $target)],
            ['target_rows' => $this->database->count($target)]
        );
    }

    private function copy(
        MigrationTable $table,
        MigrationPlan $plan,
        bool $final
    ): MigrationWorkResult {
        $this->schemas->assertReady($table, $plan, $this->sources->one($table));
        if ($final) {
            $owner = $this->schemas->schema($table)->owner()->value();
            if (! $this->operations->isModuleWriteFrozen($owner)) {
                return new MigrationWorkResult(
                    'migration.module_not_frozen',
                    '최종 복사 전에 대상 모듈의 쓰기를 동결해 주세요.',
                    [],
                    ['module_frozen' => false],
                    true
                );
            }
            $activeJobs = $this->database->activeNonMigrationJobs();
            if ($activeJobs > 0) {
                return new MigrationWorkResult(
                    'migration.active_jobs_remain',
                    '실행 중인 일반 Jobs가 있어 최종 복사를 시작할 수 없습니다.',
                    [],
                    ['active_job_count' => $activeJobs],
                    true
                );
            }
        }

        return $this->copier->copy($table, $plan);
    }

    private function cutover(
        MigrationTable $table,
        MigrationPlan $plan
    ): MigrationWorkResult {
        $source = $this->sources->one($table);
        $this->schemas->cutover($table, $plan, $source);

        return new MigrationWorkResult(
            'migration.table_cutover_complete',
            '테이블의 활성 스키마 전환을 완료했습니다.',
            ['schema_fingerprint' => $this->schemas->schema($table)->fingerprint()],
            ['cutover_complete' => true]
        );
    }

    private function resume(
        MigrationTable $table,
        MigrationPlan $plan
    ): MigrationWorkResult {
        $source = $this->sources->one($table);
        $target = $this->schemas->assertReady($table, $plan, $source);

        return new MigrationWorkResult(
            'migration.table_resume_check_passed',
            '전환 뒤 목표 테이블 확인을 통과했습니다.',
            ['target_rows' => $this->database->count($target)],
            ['active_non_migration_jobs' => $this->database->activeNonMigrationJobs()]
        );
    }

    private function observe(
        MigrationTable $table,
        MigrationPlan $plan
    ): MigrationWorkResult {
        return $this->copier->validate($table, $plan);
    }

    private function judgeCompatibility(): MigrationWorkResult
    {
        $settings = $this->judgeSettings->current();
        if ($settings === null) {
            return new MigrationWorkResult(
                'migration.judge_settings_missing',
                'Judge DB 연결 설정이 없습니다.',
                [],
                ['judge_ready' => false],
                true
            );
        }
        $report = $this->judgeProbe->inspect($settings);

        return new MigrationWorkResult(
            $report->isReady()
                ? 'migration.judge_table_check_passed'
                : 'migration.judge_table_check_failed',
            $report->isReady()
                ? 'Judge 필수 채점 테이블과 연결 검증을 통과했습니다.'
                : 'Judge 연결 또는 필수 채점 테이블 검증에 실패했습니다.',
            ['optional_table_count' => count($report->presentOptionalTables())],
            [
                'judge_ready' => $report->isReady(),
                'missing_required_count' => count($report->missingRequiredTables()),
                'ignored_table_count' => count($report->presentIgnoredTables()),
                'error_count' => count($report->errors()),
            ],
            ! $report->isReady()
        );
    }

    private function cleanupReport(
        MigrationTable $table,
        MigrationPlan $plan
    ): MigrationWorkResult {
        $sources = $this->sources->existing($table);

        return new MigrationWorkResult(
            'migration.cleanup_candidate_reported',
            '삭제하지 않고 기존 구조 정리 후보만 보고했습니다.',
            [
                'candidate_count' => count($sources),
                'candidate_set_hash' => hash('sha256', implode('|', $sources)),
                'plan_hash' => $plan->hash(),
            ],
            ['deleted_count' => 0]
        );
    }

    /** @return list<string> */
    private function activeLegacyPlugins(): array
    {
        $current = $this->currentPlugin();
        $plugins = array_filter(
            $this->activePlugins(),
            static fn (string $plugin): bool => $plugin !== $current
                && preg_match(
                    '/^(?:oj-(?:blockly|groups|ai|judge|contest|class|community)|online-judge)[^\\/]*\\//',
                    $plugin
                ) === 1
        );

        return array_values($plugins);
    }

    /** @return list<string> */
    private function activePlugins(): array
    {
        $plugins = function_exists('get_option') ? get_option('active_plugins', []) : [];

        return is_array($plugins)
            ? array_values(array_filter(array_map('strval', $plugins)))
            : [];
    }

    private function currentPlugin(): string
    {
        if (defined('OJ_PLATFORM_FILE') && function_exists('plugin_basename')) {
            return (string) plugin_basename(OJ_PLATFORM_FILE);
        }

        return 'oj-platform/oj-platform.php';
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new DomainException('이전 기준 보고서 해시를 만들지 못했습니다.', 0, $error);
        }
    }
}
