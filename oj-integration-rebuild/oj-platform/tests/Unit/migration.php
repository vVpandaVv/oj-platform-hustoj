<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\RecoverableJobStepJournal;
use CodingPanda\OjPlatform\Modules\Migration\Application\AuthSubmissionLegacyTransformer;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationCommandService;
use CodingPanda\OjPlatform\Modules\Migration\Application\LegacyRowTransformer;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationCatalog;
use CodingPanda\OjPlatform\Modules\Migration\Application\LegacyTransformValues;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationPlanService;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationStateService;
use CodingPanda\OjPlatform\Modules\Migration\Application\PlatformMigrationJobHandler;
use CodingPanda\OjPlatform\Modules\Migration\Application\ProblemsLegacyTransformer;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyMigrationGateway;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationPlan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationScan;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationStage;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationState;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWave;
use CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWorkResult;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\LegacySourceLocator;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\MigrationDataCopier;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\MigrationSchemaManager;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\WordPressMigrationDatabase;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicRecord;
use CodingPanda\OjPlatform\Modules\Settings\Contract\ModuleWriteFreezeManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SystemSettings;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$migrationAssertions = 0;

function migrationAssert(bool $condition, string $message): void
{
    global $migrationAssertions;
    ++$migrationAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function migrationSame(mixed $expected, mixed $actual, string $message): void
{
    migrationAssert(
        $expected === $actual,
        $message . PHP_EOL . var_export([$expected, $actual], true)
    );
}

final class MigrationWpDbStub
{
    public string $dbname = 'jol';
    public string $prefix = 'wp_';
    public array $columns = [];
    public array $existing = [];
    public int $getVarResult = 0;
    public string $lastGetVarQuery = '';
    public int $countReads = 0;
    private string $lastTable = '';

    public function prepare(string $query, mixed ...$args): string
    {
        $last = $args === [] ? null : $args[array_key_last($args)];
        if (is_string($last)) {
            $this->lastTable = $last;
        }

        return $query;
    }

    public function query(string $query): int
    {
        unset($query);

        return 0;
    }

    public function get_row(string $query, string $output): ?array
    {
        unset($query, $output);
        if (! in_array($this->lastTable, $this->existing, true)) {
            return null;
        }

        return [
            'ENGINE' => 'InnoDB',
            'TABLE_COLLATION' => 'utf8mb4_unicode_ci',
            'TABLE_COMMENT' => 'legacy',
            'TABLE_ROWS' => 0,
        ];
    }

    public function get_results(string $query, string $output): array
    {
        unset($output);
        $table = '';
        foreach (array_keys($this->columns) as $candidate) {
            if (str_contains($query, $candidate)) {
                $table = $candidate;
                break;
            }
        }

        return array_map(
            static fn (string $column): array => ['Field' => $column],
            $this->columns[$table] ?? ['id']
        );
    }

    public function get_var(string $query): int
    {
        if (str_contains($query, 'COUNT(*)')) { ++$this->countReads; }
        $this->lastGetVarQuery = $query;

        return $this->getVarResult;
    }

    public function get_col(string $query): array
    {
        unset($query);

        return [];
    }

    public function esc_like(string $value): string
    {
        return $value;
    }
}

final class MigrationSettingsManagerStub implements SettingsManager
{
    public function __construct(
        public int $revision,
        public mixed $value
    ) {
    }

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        unset($key, $default);

        return new SettingsSnapshot($this->revision, $this->value);
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        unset($key);
        if ($expectedRevision !== $this->revision) {
            throw new RuntimeException('revision conflict');
        }
        $this->value = $value;
        ++$this->revision;

        return new SettingsSnapshot($this->revision, $this->value);
    }
}

final class MigrationGatewayStub implements LegacyMigrationGateway
{
    public int $deactivationCalls = 0;
    /** @var list<string> */
    public array $executeCalls = [];
    public ?string $failOnceTableKey = null;
    private bool $executionFailureConsumed = false;

    public function __construct(public MigrationWorkResult $deactivationResult)
    {
    }

    public function scan(MigrationCatalog $catalog): MigrationScan
    {
        unset($catalog);
        throw new LogicException('not used');
    }

    public function execute(
        MigrationTable $table,
        MigrationPlan $plan
    ): MigrationWorkResult {
        unset($plan);
        $this->executeCalls[] = $table->key();
        if (
            $this->failOnceTableKey === $table->key()
            && ! $this->executionFailureConsumed
        ) {
            $this->executionFailureConsumed = true;
            throw new DomainException('강제한 이전 테이블 실패');
        }

        return new MigrationWorkResult(
            'migration.table_ready',
            '테이블 작업 완료',
            ['table_key' => $table->key()],
            ['valid' => true]
        );
    }

    public function deactivateLegacyPlugins(array $pluginFiles): MigrationWorkResult
    {
        unset($pluginFiles);
        ++$this->deactivationCalls;

        return $this->deactivationResult;
    }
}

final class MigrationRecoverableSteps implements
    JobStepJournal,
    RecoverableJobStepJournal
{
    /**
     * @var array<string, array{status:string,input_hash:string,checkpoint:array<string,mixed>}>
     */
    public array $records = [];
    /** @var array<string, int> */
    public array $starts = [];

    public function start(
        ClaimedJobItem $claim,
        string $stepKey,
        int $stepOrder,
        string $inputHash,
        array $checkpoint = []
    ): void {
        unset($claim, $stepOrder);
        $this->records[$stepKey] = [
            'status' => 'running',
            'input_hash' => $inputHash,
            'checkpoint' => $checkpoint,
        ];
        $this->starts[$stepKey] = ($this->starts[$stepKey] ?? 0) + 1;
    }

    public function complete(
        ClaimedJobItem $claim,
        string $stepKey,
        array $validationResult = []
    ): void {
        unset($claim, $validationResult);
        $this->records[$stepKey]['status'] = 'completed';
    }

    public function fail(
        ClaimedJobItem $claim,
        string $stepKey,
        array $validationResult = []
    ): void {
        unset($claim, $validationResult);
        $this->records[$stepKey]['status'] = 'failed';
    }

    public function compensate(
        ClaimedJobItem $claim,
        string $stepKey,
        bool $succeeded,
        array $compensationResult = []
    ): void {
        unset($claim, $compensationResult);
        $this->records[$stepKey]['status'] = $succeeded
            ? 'compensated'
            : 'compensation_failed';
    }

    public function completedCheckpoint(
        ClaimedJobItem $claim,
        string $stepKey,
        string $inputHash
    ): ?array {
        unset($claim);
        $record = $this->records[$stepKey] ?? null;
        if (
            ! is_array($record)
            || $record['status'] !== 'completed'
            || ! hash_equals($inputHash, $record['input_hash'])
        ) {
            return null;
        }

        return $record['checkpoint'];
    }

    public function completeWithCheckpoint(
        ClaimedJobItem $claim,
        string $stepKey,
        array $checkpoint,
        array $validationResult = []
    ): void {
        unset($claim, $validationResult);
        $this->records[$stepKey]['status'] = 'completed';
        $this->records[$stepKey]['checkpoint'] = $checkpoint;
    }
}

final class MigrationAuthorizationStub implements Authorization
{
    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        unset($context);

        return $userId === 7 && $capability === 'oj_manage_migration';
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        return $userId === 7
            && in_array('oj_manage_migration', $capabilities, true);
    }

    public function isSuperAdmin(int $userId): bool
    {
        return $userId === 7;
    }
}

final class MigrationErrorReporterStub implements ErrorReporter
{
    /** @var list<Throwable> */
    public array $errors = [];

    public function report(Throwable $error): void
    {
        $this->errors[] = $error;
    }
}

final class MigrationJobDispatcherStub implements JobDispatcher
{
    public bool $fail = false;

    public function enqueue(
        string $jobType,
        string $executionMode,
        int $actorUserId,
        array $targets,
        array $inputSummary,
        array $targetSummary,
        string $accessPolicyKey,
        string $accessPolicyVersion = '1',
        string $inputVersion = '1'
    ): JobEnqueueResult {
        unset(
            $jobType,
            $executionMode,
            $actorUserId,
            $targets,
            $inputSummary,
            $targetSummary,
            $accessPolicyKey,
            $accessPolicyVersion,
            $inputVersion
        );
        if ($this->fail) {
            throw new RuntimeException('dispatch failed');
        }

        return new JobEnqueueResult(1, true);
    }
}

final class MigrationFreezeManagerStub implements ModuleWriteFreezeManager
{
    public function acquireMigrationFreeze(string $moduleKey, string $freezeKey): void
    {
        unset($moduleKey, $freezeKey);
    }

    public function releaseMigrationFreeze(string $moduleKey, string $freezeKey): void
    {
        unset($moduleKey, $freezeKey);
    }
}

$fingerprint = str_repeat('b', 64);
$catalogTargets = [];
foreach ((new MigrationCatalog())->all() as $catalogTable) {
    $catalogTargets[$catalogTable->targetTable()] = $catalogTable->wave()->value;
}
$platformTargets = [];
$platformTableDefinitions = require $pluginRoot . '/config/platform-tables.php';
foreach ($platformTableDefinitions as $definition) {
    $platformTargets[(string) $definition['name']] = (string) $definition['owner_module'];
}
ksort($catalogTargets, SORT_STRING);
ksort($platformTargets, SORT_STRING);
migrationSame(
    $platformTargets,
    $catalogTargets,
    '이전 카탈로그의 대상 테이블과 소유 모듈은 플랫폼 42개 테이블 정의와 같아야 합니다.'
);

$evidence = [
    'backup_ref' => 'backup://r11',
    'backup_hash' => str_repeat('c', 64),
    'restore_ref' => 'restore://isolated-r11',
    'restore_hash' => str_repeat('d', 64),
    'restore_duration_seconds' => 125,
    'verified_at' => '2026-08-01T09:00:00+09:00',
];
$authTable = new MigrationTable(
    'auth_users',
    MigrationWave::Auth,
    'users',
    'user_profiles',
    'id',
    'user_profiles'
);
$plan = new MigrationPlan(
    MigrationWave::Auth,
    MigrationStage::TargetSchema,
    $fingerprint,
    [$authTable],
    $evidence,
    []
);
$reorderedPlan = new MigrationPlan(
    MigrationWave::Auth,
    MigrationStage::TargetSchema,
    $fingerprint,
    [$authTable],
    array_reverse($evidence, true),
    []
);
$nextStagePlan = new MigrationPlan(
    MigrationWave::Auth,
    MigrationStage::PreCopy,
    $fingerprint,
    [$authTable],
    $evidence,
    []
);
migrationSame($plan->hash(), $reorderedPlan->hash(), '계획 해시는 입력 순서와 무관해야 합니다.');
migrationAssert($plan->hash() !== $nextStagePlan->hash(), '단계가 바뀌면 계획 해시가 달라야 합니다.');
migrationSame($plan->freezeKey(), $nextStagePlan->freezeKey(), '동일 파동의 동결 키는 안정적이어야 합니다.');

$now = new DateTimeImmutable('2026-08-01T09:00:00+09:00');
$prepared = MigrationState::initial()->withPreparation($evidence, [], $now);
migrationAssert($prepared->hasRecoveryEvidence(), '완전한 복구 증거를 인정해야 합니다.');
$incompleteEvidence = $evidence;
unset($incompleteEvidence['restore_hash']);
migrationAssert(
    ! MigrationState::initial()->withPreparation($incompleteEvidence, [], $now)->hasRecoveryEvidence(),
    '복구 증거가 빠지면 준비 완료로 보아서는 안 됩니다.'
);
$approved = $prepared->withPlan($plan, $now)->withApproval($plan, 7, $now);
migrationAssert($approved->isApprovedFor($plan), '현재 계획 해시만 승인해야 합니다.');
migrationAssert(! $approved->isApprovedFor($nextStagePlan), '변경된 계획에 승인을 재사용하면 안 됩니다.');

$observation = MigrationState::initial();
$passed = new MigrationWorkResult('migration.observation_passed', '관찰 통과');
for ($day = 1; $day <= 7; ++$day) {
    $observation = $observation->withWorkResult(
        MigrationStage::Observation,
        $passed,
        new DateTimeImmutable(sprintf('2026-08-%02dT09:00:00+09:00', $day))
    );
}
migrationSame(7, $observation->observationDayCount(), '서로 다른 7일을 기록해야 합니다.');
migrationSame('ready_for_next', $observation->status(), '7일 뒤 다음 파동으로 이동할 수 있어야 합니다.');
migrationSame(['auth'], $observation->completedWaves(), '완료 파동을 한 번 기록해야 합니다.');
$nextWave = $observation->beginNextWave($now);
migrationSame(MigrationWave::Problems, $nextWave->wave(), 'Auth 다음은 문제 파동이어야 합니다.');
migrationSame(MigrationStage::TargetSchema, $nextWave->stage(), '다음 파동은 목표 스키마부터 시작해야 합니다.');

$settings = SystemSettings::fromArray(['frozen_modules' => ['problems']]);
$frozen = $settings->withMigrationFreeze('classes', $plan->freezeKey());
migrationSame(['classes', 'problems'], $frozen->effectiveFrozenModules(), '수동 동결과 이전 동결을 함께 유지해야 합니다.');
$wrongOwnerRejected = false;
try {
    $frozen->withoutMigrationFreeze('classes', str_repeat('e', 64));
} catch (InvalidArgumentException) {
    $wrongOwnerRejected = true;
}
migrationAssert($wrongOwnerRejected, '다른 소유 키로 이전 동결을 해제하면 안 됩니다.');
$released = $frozen->withoutMigrationFreeze('classes', $plan->freezeKey());
migrationSame(['problems'], $released->effectiveFrozenModules(), '이전 동결 해제 뒤 수동 동결은 남아야 합니다.');

$wp = new MigrationWpDbStub();
$wp->existing = ['wp_oj_users', 'oj_users'];
$wp->columns = [
    'wp_oj_users' => ['id'],
    'oj_users' => ['id', 'password', 'pass', 'email', 'user_email', 'user_pass', 'nickname'],
];
$migrationDb = new WordPressMigrationDatabase($wp);
$locator = new LegacySourceLocator($migrationDb);
$wp->existing[] = 'wp_oj_perf_probe';
$wp->columns['wp_oj_perf_probe'] = ['id'];
for ($request = 0; $request < 36; ++$request) {
    migrationAssert($migrationDb->isLegacySource('wp_oj_perf_probe', 'id', 'perf_probe'), '존재 확인은 원본 테이블을 인식해야 합니다.');
}
migrationSame(0, $wp->countReads, '36회 존재 확인에 전체 행 수 계산이 발생하면 안 됩니다.');
migrationAssert(!$migrationDb->isLegacySource('wp_oj_perf_probe', 'missing_key', 'perf_probe'), '잘못된 키를 가진 원본은 인식하면 안 됩니다.');
migrationSame(
    ['wp_oj_users', 'oj_users'],
    $locator->existing($authTable),
    '접두사형과 비접두사형 이전 원본을 모두 검출해야 합니다.'
);
migrationSame(null, $locator->one($authTable), '원본이 둘이면 자동 선택하면 안 됩니다.');

$unrecognizedWp = new MigrationWpDbStub();
$unrecognizedWp->existing = ['problem_metadata'];
$unrecognizedWp->columns = ['problem_metadata' => ['id', 'title']];
$unrecognizedLocator = new LegacySourceLocator(
    new WordPressMigrationDatabase($unrecognizedWp)
);
migrationSame(
    [],
    $unrecognizedLocator->existing(new MigrationTable(
        'problems_problem_metadata',
        MigrationWave::Problems,
        'problem_metadata',
        'problem_metadata',
        'problem_id',
        'problem_metadata',
        true
    )),
    '이름만 같고 필수 정렬키가 없는 테이블을 이전 원본으로 인정하면 안 됩니다.'
);

$schemaManager = (new ReflectionClass(MigrationSchemaManager::class))->newInstanceWithoutConstructor();
$copier = new MigrationDataCopier(
    $migrationDb,
    $locator,
    $schemaManager,
    new LegacyRowTransformer([])
);
$sourceColumns = (new ReflectionClass($copier))
    ->getMethod('sourceColumns')
    ->invoke($copier, $authTable, 'oj_users');
migrationSame(
    ['id', 'nickname'],
    $sourceColumns,
    '이전 사용자 원본에서 비밀번호와 이메일 열을 읽지 않아야 합니다.'
);

$userResolver = new class implements \CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyUserResolver {
    public function resolve(
        ?int $wordpressUserId,
        ?string $loginId,
        bool $allowMissing = false
    ): ?int {
        unset($loginId, $allowMissing);

        return $wordpressUserId ?? 11;
    }
};
$clock = new class implements Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-01T09:00:00+09:00');
    }
};
$authTransformer = new AuthSubmissionLegacyTransformer(
    new LegacyTransformValues($userResolver, $clock)
);
$preferenceTable = new MigrationTable(
    'auth_preferences',
    MigrationWave::Auth,
    'user_preferences',
    'user_preferences',
    'id',
    'user_preferences'
);
$preferencePlan = new MigrationPlan(
    MigrationWave::Auth,
    MigrationStage::PreCopy,
    $fingerprint,
    [$preferenceTable],
    $evidence,
    []
);
$privatePreferences = $authTransformer->transform(
    $preferenceTable,
    [
        'wp_user_id' => 17,
        'nickname_public' => 0,
        'email_public' => 0,
        'school_public' => 0,
        'editor_theme' => 'ace/theme/dracula',
        'default_language' => 0,
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ],
    $preferencePlan
);
migrationSame(
    'hidden',
    $privatePreferences[0]['profile_visibility'],
    '이전 공개 항목이 없으면 프로필 공개 범위를 넓히지 않아야 합니다.'
);
migrationSame('members', $privatePreferences[0]['ranking_visibility'], '랭킹 공개 기본값을 내부 공개로 변환해야 합니다.');
migrationSame('members', $privatePreferences[0]['activity_visibility'], '학습 활동 공개 기본값을 내부 공개로 변환해야 합니다.');
migrationSame('dracula', $privatePreferences[0]['editor_theme'], '이전 어두운 Ace 테마 식별자를 보존해야 합니다.');
migrationSame(0, $privatePreferences[0]['default_language'], '이전 C 언어 기본값 0을 누락하지 않아야 합니다.');
migrationSame('code', $privatePreferences[0]['default_editor'], '이전 계정의 기본 편집기는 코드 편집기여야 합니다.');
migrationSame(16, $privatePreferences[0]['editor_font_size'], '이전 계정의 편집기 글자 크기는 16px이어야 합니다.');

$publicPreferences = $authTransformer->transform(
    $preferenceTable,
    [
        'wp_user_id' => 18,
        'nickname_public' => 1,
        'editor_theme' => 'github',
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ],
    $preferencePlan
);
migrationSame(
    'members',
    $publicPreferences[0]['profile_visibility'],
    '이전 공개 항목이 있으면 로그인 사용자 공개로 변환해야 합니다.'
);
migrationSame('github', $publicPreferences[0]['editor_theme'], '이전 밝은 Ace 테마 식별자를 보존해야 합니다.');

$profileRows = $authTransformer->transform(
    $authTable,
    [
        'wp_user_id' => 19,
        'school' => '알고리즘반',
        'birth_date' => '2012-05-18',
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ],
    $plan
);
migrationAssert(
    array_key_exists('bio', $profileRows[0]) && $profileRows[0]['bio'] === null,
    '이전 프로필에 없던 소개는 명시적으로 빈 값으로 변환해야 합니다.'
);
$topics = new class implements ProblemTopicDictionary {
    public function all(): array
    {
        return [];
    }

    public function find(string $key): ?ProblemTopicRecord
    {
        unset($key);

        return null;
    }

    public function assertSelectableDetails(array $detailKeys): void
    {
        unset($detailKeys);
    }

    public function version(): string
    {
        return 'topics-v1';
    }

    public function createCategory(
        string $key,
        string $label,
        array $aliases,
        int $actorUserId
    ): void {
        unset($key, $label, $aliases, $actorUserId);
    }

    public function createDetail(
        string $categoryKey,
        string $key,
        string $label,
        array $aliases,
        int $actorUserId
    ): void {
        unset($categoryKey, $key, $label, $aliases, $actorUserId);
    }
};
$problemTable = new MigrationTable(
    'problem_metadata',
    MigrationWave::Problems,
    'problem_metadata',
    'problem_metadata',
    'problem_id',
    'problem_metadata',
    true
);
$problemPlan = new MigrationPlan(
    MigrationWave::Problems,
    MigrationStage::PreCopy,
    $fingerprint,
    [$problemTable],
    $evidence,
    []
);
$transformer = new ProblemsLegacyTransformer(
    new LegacyTransformValues($userResolver, $clock),
    $topics
);
$metadata = [
    'problem_id' => 1000,
    'metadata_mode' => 'auto',
    'updated_by_wp_user_id' => 11,
    'created_at' => '2026-08-01 00:00:00',
    'updated_at' => '2026-08-01 00:00:00',
];
$high = $transformer->transform(
    $problemTable,
    $metadata + ['difficulty_score' => 99],
    $problemPlan
);
$low = $transformer->transform(
    $problemTable,
    $metadata + ['difficulty_score' => 0],
    $problemPlan
);
migrationSame(5, $high[0]['difficulty_score'], '이전 난이도는 최대 5로 보정해야 합니다.');
migrationSame(1, $low[0]['difficulty_score'], '이전 난이도는 최소 1로 보정해야 합니다.');

$schemaManagerDecision = (new ReflectionClass(MigrationSchemaManager::class))
    ->newInstanceWithoutConstructor();
migrationSame(
    'problem_metadata',
    $schemaManagerDecision->physicalTarget(
        $problemTable,
        $problemPlan,
        'wp_oj_problem_metadata'
    ),
    '접두사가 붙은 원본은 동명 충돌로 처리하지 않아야 합니다.'
);
migrationAssert(
    str_starts_with(
        $schemaManagerDecision->physicalTarget(
            $problemTable,
            $problemPlan,
            'problem_metadata'
        ),
        'migration_problem_metadata_'
    ),
    '실제 목표 이름을 점유한 원본만 임시 목표 테이블을 사용해야 합니다.'
);

$verifyWp = new MigrationWpDbStub();
$verifyWp->getVarResult = 1;
$verifyDatabase = new WordPressMigrationDatabase($verifyWp);
$verifySchema = SchemaTableDefinition::fromArray([
    'name' => 'migration_verify',
    'owner_module' => 'migration',
    'schema_version' => '1',
    'columns' => [
        ['name' => 'id', 'type' => 'BIGINT UNSIGNED'],
        ['name' => 'label', 'type' => 'VARCHAR(20)'],
    ],
    'indexes' => [
        ['columns' => ['id'], 'unique' => true, 'primary' => true],
    ],
]);
migrationAssert(
    $verifyDatabase->contains(
        'migration_verify',
        $verifySchema,
        ['id' => 7, 'label' => '검증값']
    ),
    '모든 이전 속성이 일치하면 대상 행을 찾은 것으로 보아야 합니다.'
);
migrationAssert(
    str_contains($verifyWp->lastGetVarQuery, '`label`<=>%s'),
    '최종 검증 SQL은 기본키뿐 아니라 이전한 속성값도 비교해야 합니다.'
);

$blockedState = MigrationState::fromArray([
    'status' => 'planned',
    'wave' => 'auth',
    'stage' => MigrationStage::RecoveryRehearsal->value,
    'baseline' => [
        'database_fingerprint' => $fingerprint,
        'blocker_count' => 1,
    ],
    'recovery_evidence' => $evidence,
]);
$blockerRejected = false;
try {
    (new MigrationPlanService(new MigrationCatalog()))->create($blockedState);
} catch (DomainException) {
    $blockerRejected = true;
}
migrationAssert($blockerRejected, '사전 검사 차단 항목이 남으면 실행 계획을 만들면 안 됩니다.');

$utcClock = new class implements Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-01T00:30:00+00:00');
    }
};
$siteClock = new class implements Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-01T09:30:00+09:00');
    }
};
$preparationSettings = new MigrationSettingsManagerStub(
    0,
    MigrationState::initial()->toArray()
);
$preparationCommands = new MigrationCommandService(
    new MigrationStateService($preparationSettings),
    new MigrationPlanService(new MigrationCatalog()),
    new MigrationCatalog(),
    new MigrationGatewayStub(new MigrationWorkResult('unused', 'unused')),
    new MigrationJobDispatcherStub(),
    new MigrationFreezeManagerStub(),
    $utcClock,
    $siteClock
);
$prepared = $preparationCommands->savePreparation([
    'backup_ref' => 'backup://timezone-boundary',
    'backup_hash' => str_repeat('1', 64),
    'restore_ref' => 'restore://timezone-boundary',
    'restore_hash' => str_repeat('2', 64),
    'restore_duration_seconds' => 30,
    'verified_at' => '2026-08-01T09:00',
    'owner_assignments' => '{}',
], 0);
migrationSame(
    '2026-08-01T00:00:00+00:00',
    $prepared->recoveryEvidence()['verified_at'] ?? null,
    '복구 검증 시각은 WordPress 지역시 입력을 UTC로 정규화해 저장해야 합니다.'
);

$cleanupValue = [
    'status' => 'planned',
    'wave' => 'ai_feedback',
    'stage' => MigrationStage::Cleanup->value,
    'completed_waves' => [
        'auth', 'problems', 'submissions', 'classes', 'contests', 'groups', 'ai_feedback',
    ],
    'legacy_plugins' => ['oj-ai/oj-ai.php'],
    'last_result_code' => 'migration.cleanup_report_ready',
];
$migrationSettings = new MigrationSettingsManagerStub(5, $cleanupValue);
$migrationGateway = new MigrationGatewayStub(new MigrationWorkResult(
    'migration.legacy_plugins_deactivated',
    '비활성화 완료'
));
$migrationCommands = new MigrationCommandService(
    new MigrationStateService($migrationSettings),
    new MigrationPlanService(new MigrationCatalog()),
    new MigrationCatalog(),
    $migrationGateway,
    new MigrationJobDispatcherStub(),
    new MigrationFreezeManagerStub(),
    $clock
);
$staleRevisionRejected = false;
try {
    $migrationCommands->deactivateLegacyPlugins(7, '기존 플러그인 정리', 4);
} catch (DomainException) {
    $staleRevisionRejected = true;
}
migrationAssert($staleRevisionRejected, '오래된 화면 revision은 정리 실행 전에 거부해야 합니다.');
migrationSame(0, $migrationGateway->deactivationCalls, 'revision 충돌 시 WordPress 비활성화 API를 호출하면 안 됩니다.');
$migrationCommands->deactivateLegacyPlugins(7, '기존 플러그인 정리', 5);
migrationSame(1, $migrationGateway->deactivationCalls, '현재 revision에서는 비활성화를 한 번 실행해야 합니다.');
migrationSame(
    'completed',
    MigrationState::fromArray((array) $migrationSettings->value)->status(),
    '비활성화 성공 뒤 이전 상태를 완료로 닫아야 합니다.'
);

$retrySettings = new MigrationSettingsManagerStub(10, $cleanupValue);
$retryGateway = new MigrationGatewayStub(new MigrationWorkResult(
    'migration.legacy_plugins_still_active',
    '일부 플러그인이 활성 상태입니다.',
    [],
    ['remaining_count' => 1],
    true
));
$retryCommands = new MigrationCommandService(
    new MigrationStateService($retrySettings),
    new MigrationPlanService(new MigrationCatalog()),
    new MigrationCatalog(),
    $retryGateway,
    new MigrationJobDispatcherStub(),
    new MigrationFreezeManagerStub(),
    $clock
);
$retryCommands->deactivateLegacyPlugins(7, '기존 플러그인 정리', 10);
migrationSame(
    'needs_attention',
    MigrationState::fromArray((array) $retrySettings->value)->status(),
    '비활성화가 남으면 주의 상태와 재시도 경로를 유지해야 합니다.'
);
$retryGateway->deactivationResult = new MigrationWorkResult(
    'migration.legacy_plugins_deactivated',
    '비활성화 완료'
);
$retryCommands->deactivateLegacyPlugins(7, '기존 플러그인 정리', 12);
migrationSame(2, $retryGateway->deactivationCalls, '주의 상태의 정리 작업을 명시 승인으로 재시도해야 합니다.');
migrationSame(
    'completed',
    MigrationState::fromArray((array) $retrySettings->value)->status(),
    '재시도 성공 뒤 이전을 완료해야 합니다.'
);

$dispatchCatalog = new MigrationCatalog();
$dispatchPlans = new MigrationPlanService($dispatchCatalog);
$dispatchState = MigrationState::fromArray([
    'status' => 'planned',
    'wave' => 'auth',
    'stage' => MigrationStage::RecoveryRehearsal->value,
    'baseline' => [
        'database_fingerprint' => $fingerprint,
        'blocker_count' => 0,
    ],
    'recovery_evidence' => $evidence,
]);
$dispatchPlan = $dispatchPlans->create($dispatchState);
$dispatchState = $dispatchState->withPlan($dispatchPlan, $now);
$dispatchSettings = new MigrationSettingsManagerStub(30, $dispatchState->toArray());
$failingDispatcher = new MigrationJobDispatcherStub();
$failingDispatcher->fail = true;
$dispatchCommands = new MigrationCommandService(
    new MigrationStateService($dispatchSettings),
    $dispatchPlans,
    $dispatchCatalog,
    new MigrationGatewayStub(new MigrationWorkResult('unused', 'unused')),
    $failingDispatcher,
    new MigrationFreezeManagerStub(),
    $clock
);
$dispatchFailed = false;
try {
    $dispatchCommands->enqueue(7, $dispatchPlan->hash(), '이전 실행', 30);
} catch (RuntimeException) {
    $dispatchFailed = true;
}
migrationAssert($dispatchFailed, 'Jobs 등록 실패를 호출자에게 전달해야 합니다.');
$dispatchFailureState = MigrationState::fromArray((array) $dispatchSettings->value);
migrationSame('needs_attention', $dispatchFailureState->status(), 'Jobs 등록 실패 뒤 실행 잠금을 해제해야 합니다.');
migrationSame(
    'migration.dispatch_failed',
    $dispatchFailureState->lastResultCode(),
    'Jobs 등록 실패 원인을 안전한 상태 코드로 남겨야 합니다.'
);

$jobCatalog = new MigrationCatalog();
$jobTables = $jobCatalog->forWave(MigrationWave::Auth);
$jobPlan = new MigrationPlan(
    MigrationWave::Auth,
    MigrationStage::TargetSchema,
    $fingerprint,
    $jobTables,
    $evidence,
    []
);
$jobState = MigrationState::initial()
    ->withPreparation($evidence, [], $now)
    ->withPlan($jobPlan, $now)
    ->withApproval($jobPlan, 7, $now);
$jobSettings = new MigrationSettingsManagerStub(50, $jobState->toArray());
$jobStates = new MigrationStateService($jobSettings);
$jobGateway = new MigrationGatewayStub(
    new MigrationWorkResult('unused', 'unused')
);
$jobGateway->failOnceTableKey = $jobTables[1]->key();
$jobCommands = new MigrationCommandService(
    $jobStates,
    new MigrationPlanService($jobCatalog),
    $jobCatalog,
    $jobGateway,
    new MigrationJobDispatcherStub(),
    new MigrationFreezeManagerStub(),
    $clock
);
$jobSteps = new MigrationRecoverableSteps();
$jobErrors = new MigrationErrorReporterStub();
$jobHandler = new PlatformMigrationJobHandler(
    $jobCatalog,
    $jobGateway,
    $jobCommands,
    $jobStates,
    $jobSteps,
    new MigrationAuthorizationStub(),
    $jobErrors
);
$jobClaim = new ClaimedJobItem(
    91,
    92,
    'platform_migration',
    'migration_wave',
    'auth:' . MigrationStage::TargetSchema->value,
    $jobPlan->hash(),
    null,
    str_repeat('1', 64),
    new FormDraftReference(
        'draft:v1:123e4567-e89b-42d3-a456-426614174000'
    ),
    1,
    1,
    7,
    str_repeat('f', 64)
);
$jobItem = new JobWorkItem(
    $jobClaim,
    ['plan' => $jobPlan->canonical()]
);
$firstJobResult = $jobHandler->handle($jobItem);
migrationSame(
    'needs_attention',
    $firstJobResult->outcome(),
    '이전 테이블 예외는 확인 필요 결과로 종료해야 합니다.'
);
migrationSame(
    'migration.execution_failed',
    $firstJobResult->resultCode(),
    '테이블 예외를 승인 상태 변경으로 오분류하면 안 됩니다.'
);
migrationSame(
    'needs_attention',
    MigrationState::fromArray((array) $jobSettings->value)->status(),
    '테이블 예외 뒤 이전 상태를 재시도 가능한 확인 필요 상태로 기록해야 합니다.'
);
$firstStatuses = array_count_values(array_column(
    array_values($jobSteps->records),
    'status'
));
migrationSame(1, $firstStatuses['completed'] ?? 0, '실패 전 완료 테이블 체크포인트를 보존해야 합니다.');
migrationSame(1, $firstStatuses['failed'] ?? 0, '예외가 발생한 테이블 단계를 실패로 기록해야 합니다.');
migrationSame(1, count($jobErrors->errors), '원래 테이블 예외를 시스템 기록에 한 번 전달해야 합니다.');

$secondJobResult = $jobHandler->handle($jobItem);
migrationSame('succeeded', $secondJobResult->outcome(), '같은 Jobs 항목 재시도가 이전을 완료해야 합니다.');
migrationSame(
    1,
    (int) ($secondJobResult->safeResult()['resumed_table_count'] ?? 0),
    '재시도에서 완료 체크포인트 한 개를 재사용해야 합니다.'
);
$executionCounts = array_count_values($jobGateway->executeCalls);
migrationSame(
    1,
    $executionCounts[$jobTables[0]->key()] ?? 0,
    '재시도에서 이미 완료된 첫 테이블을 다시 실행하면 안 됩니다.'
);
migrationSame(
    2,
    $executionCounts[$jobTables[1]->key()] ?? 0,
    '재시도에서는 실패했던 테이블만 두 번째로 실행해야 합니다.'
);
migrationSame(
    count($jobTables),
    count(array_filter(
        $jobSteps->records,
        static fn (array $record): bool =>
            $record['status'] === 'completed'
    )),
    '재시도 성공 뒤 파동의 모든 테이블 단계가 완료되어야 합니다.'
);

fwrite(
    STDOUT,
    sprintf("R-11 데이터 이전 단위 검사 통과: assertion %d개\n", $migrationAssertions)
);
