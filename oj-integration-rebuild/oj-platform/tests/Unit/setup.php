<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Kernel\Setup\InstallationIdFactory;
use CodingPanda\OjPlatform\Kernel\Setup\PlatformConnectionSetupReadinessProbe;
use CodingPanda\OjPlatform\Kernel\Setup\SetupCoordinator;
use CodingPanda\OjPlatform\Kernel\Setup\SetupLock;
use CodingPanda\OjPlatform\Kernel\Setup\SetupMode;
use CodingPanda\OjPlatform\Kernel\Setup\SetupReadinessProbe;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyInstallationPresence;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyInstallationReport;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionInstaller;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionPresetDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleSyncReport;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleSynchronizer;
use CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress\WordPressRoleSynchronizer;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\InstallationPlanner;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaInstaller;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaOwnershipMarker;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaSqlCompiler;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\PlatformConnection;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\PlatformTableState;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\TargetTableDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Infrastructure\WordPress\WordPressPlatformConnection;
use CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingStore;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$assertions = 0;

function setupAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function setupSame(mixed $expected, mixed $actual, string $message): void
{
    setupAssert(
        $expected === $actual,
        $message
        . PHP_EOL
        . 'Expected: ' . var_export($expected, true)
        . PHP_EOL
        . 'Actual: ' . var_export($actual, true)
    );
}

final class MemorySettingStore implements SettingStore
{
    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    public function read(string $optionName, mixed $default = null): mixed
    {
        return $this->values[$optionName] ?? $default;
    }

    public function write(string $optionName, mixed $value): bool
    {
        $this->values[$optionName] = $value;

        return true;
    }
}

final class MemoryPlatformConnection implements PlatformConnection
{
    /**
     * @var array<string, PlatformTableState>
     */
    public array $tables = [];

    /**
     * @var list<string>
     */
    public array $statements = [];

    /** @var list<string> */
    public array $synchronizations = [];

    public ?int $failOnExecuteCall = null;
    public bool $failNextSynchronize = false;
    public bool $available = true;
    public string $serverVersion = '8.0.36';
    public int $inspectCalls = 0;

    private int $executeCalls = 0;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function databaseName(): string
    {
        return 'wordpress';
    }

    public function serverVersion(): string
    {
        return $this->serverVersion;
    }

    public function inspectTables(array $tableNames): array
    {
        ++$this->inspectCalls;
        return array_intersect_key(
            $this->tables,
            array_fill_keys($tableNames, true)
        );
    }

    public function execute(string $statement): void
    {
        ++$this->executeCalls;
        if ($this->failOnExecuteCall === $this->executeCalls) {
            throw new RuntimeException('강제한 DDL 실행 실패');
        }

        if (
            preg_match(
                "/^ALTER TABLE .([a-z][a-z0-9_]*). COMMENT='([^']+)'$/",
                $statement,
                $alterMatch
            ) === 1
        ) {
            $name = $alterMatch[1];
            $current = $this->tables[$name] ?? null;
            if (! $current instanceof PlatformTableState) {
                throw new RuntimeException('검사 연결에 갱신할 테이블이 없습니다.');
            }
            $this->statements[] = $statement;
            $this->tables[$name] = new PlatformTableState(
                $name,
                $alterMatch[2],
                $current->engine(),
                $current->collation()
            );

            return;
        }

        if (
            preg_match('/^CREATE TABLE .([a-z][a-z0-9_]*)./', $statement, $tableMatch) !== 1
            || preg_match("/ COMMENT='([^']+)'$/", $statement, $commentMatch) !== 1
        ) {
            throw new RuntimeException('검사 연결이 스키마 명령을 해석하지 못했습니다.');
        }

        $name = $tableMatch[1];
        preg_match_all(
            '/REFERENCES \x60([a-z][a-z0-9_]*)\x60/',
            $statement,
            $referenceMatches
        );
        foreach ($referenceMatches[1] ?? [] as $referencedTable) {
            if ($referencedTable !== $name && ! isset($this->tables[$referencedTable])) {
                throw new RuntimeException(sprintf(
                    '검사 연결에서 참조 대상 테이블이 먼저 생성되지 않았습니다: %s',
                    $referencedTable
                ));
            }
        }
        if (isset($this->tables[$name])) {
            throw new RuntimeException('검사 연결에 동명 테이블이 이미 있습니다.');
        }

        $this->statements[] = $statement;
        $this->tables[$name] = new PlatformTableState(
            $name,
            $commentMatch[1],
            'InnoDB',
            'utf8mb4_unicode_ci'
        );
    }

    public function synchronize(string $createStatement): void
    {
        if ($this->failNextSynchronize) {
            $this->failNextSynchronize = false;
            throw new RuntimeException('강제한 스키마 동기화 실패');
        }
        if (
            preg_match(
                '/^CREATE TABLE .([a-z][a-z0-9_]*)./',
                $createStatement,
                $tableMatch
            ) !== 1
            || ! isset($this->tables[$tableMatch[1]])
        ) {
            throw new RuntimeException('검사 연결이 동기화 대상을 찾지 못했습니다.');
        }

        $this->synchronizations[] = $createStatement;
    }
}

final class MemoryRoleSynchronizer implements RoleSynchronizer
{
    /**
     * @var list<string>
     */
    public array $synchronized = [];

    /** @var list<string> */
    public array $administratorCapabilities = [];

    public function synchronize(array $roles, array $administratorCapabilities): RoleSyncReport
    {
        $this->administratorCapabilities = $administratorCapabilities;
        $this->synchronized = array_map(
            static fn (RoleDefinition $role): string => $role->key(),
            $roles
        );

        return new RoleSyncReport($this->synchronized, []);
    }
}

final class MemorySetupLock implements SetupLock
{
    public bool $held = false;

    public function acquire(string $token, int $ttlSeconds): bool
    {
        if ($this->held) {
            return false;
        }

        $this->held = true;

        return true;
    }

    public function release(string $token): void
    {
        $this->held = false;
    }
}

final class MemoryLegacyInstallationPresence implements LegacyInstallationPresence
{
    public int $inspectCalls = 0;

    public function __construct(
        public LegacyInstallationReport $report = new LegacyInstallationReport([], [])
    ) {
    }

    public function inspect(): LegacyInstallationReport
    {
        ++$this->inspectCalls;

        return $this->report;
    }
}

final class MemorySetupReadinessProbe implements SetupReadinessProbe
{
    /**
     * @param list<string> $currentBlockers
     */
    public function __construct(public array $currentBlockers = [])
    {
    }

    public function blockers(): array
    {
        return $this->currentBlockers;
    }
}

$loader = new PhpConfigLoader($pluginRoot . '/config');
$targets = new DefinitionRegistry('setup-targets', TargetTableDefinition::class);
$schemas = new DefinitionRegistry('setup-schemas', SchemaTableDefinition::class);
$jobs = new DefinitionRegistry('setup-jobs', JobDefinition::class);
$settings = new DefinitionRegistry('setup-settings', SettingDefinition::class);
$features = new DefinitionRegistry('setup-features', FeatureDefinition::class);
$capabilities = new DefinitionRegistry('setup-capabilities', CapabilityDefinition::class);
$roles = new DefinitionRegistry('setup-roles', RoleDefinition::class);
$presets = new DefinitionRegistry('setup-presets', PermissionPresetDefinition::class);

(new \CodingPanda\OjPlatform\Modules\PlatformDb\Module(
    $targets,
    $loader->load('platform-tables')
))->register();
(new \CodingPanda\OjPlatform\Modules\Jobs\Module(
    $jobs,
    $schemas,
    $loader->load('jobs'),
    $loader->load('jobs-schema')
))->register();
(new \CodingPanda\OjPlatform\Modules\Auth\Module(
    $schemas,
    $loader->load('auth-schema')
))->register();
$profileSchema = $schemas->find('user_profiles');
$preferenceSchema = $schemas->find('user_preferences');
$guardianSchema = $schemas->find('guardian_verifications');
setupAssert($guardianSchema instanceof SchemaTableDefinition && $guardianSchema->schemaVersion() === '2', '법정대리인 동의 증빙을 포함하는 스키마 버전 2를 등록해야 합니다.');
$guardianColumns = array_map(static fn ($column): string => $column->name(), $guardianSchema->columns());
foreach (['document_id', 'document_version', 'document_sha256', 'guardian_relationship', 'verification_method'] as $column) {
    setupAssert(in_array($column, $guardianColumns, true), '법정대리인 동의 증빙 열을 설치해야 합니다: ' . $column);
}
setupAssert(
    $profileSchema instanceof SchemaTableDefinition
        && $profileSchema->schemaVersion() === '2',
    '사용자 프로필 스키마 버전 2를 등록해야 합니다.'
);
setupAssert(
    $preferenceSchema instanceof SchemaTableDefinition
        && $preferenceSchema->schemaVersion() === '4',
    '문제 화면 제출 패널 설정을 포함한 사용자 환경설정 스키마 버전 4를 등록해야 합니다.'
);
$profileColumns = array_map(
    static fn ($column): string => $column->name(),
    $profileSchema instanceof SchemaTableDefinition ? $profileSchema->columns() : []
);
$preferenceColumns = array_map(
    static fn ($column): string => $column->name(),
    $preferenceSchema instanceof SchemaTableDefinition ? $preferenceSchema->columns() : []
);
setupAssert(in_array('bio', $profileColumns, true), '신규 설치에서 사용자 소개 열을 만들어야 합니다.');
foreach (['default_editor', 'editor_font_size', 'inline_submission_panel'] as $column) {
    setupAssert(
        in_array($column, $preferenceColumns, true),
        '신규 설치에서 사용자 편집 환경 열을 만들어야 합니다: ' . $column
    );
}
(new \CodingPanda\OjPlatform\Modules\Settings\Module(
    $features,
    $settings,
    $loader->load('features'),
    $loader->load('settings')
))->register();
(new \CodingPanda\OjPlatform\Modules\Permission\Module(
    $capabilities,
    $roles,
    $presets,
    $loader->load('capabilities')
))->register();

$settingStore = new MemorySettingStore();
$settingsService = new SettingsService($settings, $settingStore);
$connection = new MemoryPlatformConnection();
$platformReadiness = new PlatformConnectionSetupReadinessProbe($connection);
setupSame([], $platformReadiness->blockers(), '지원 DB 버전은 설치 준비 검사를 통과해야 합니다.');
$connection->serverVersion = '5.5.62';
setupAssert(
    str_contains(implode(' ', $platformReadiness->blockers()), 'MySQL 5.6.5 이상'),
    '구형 MySQL은 설치 전에 차단해야 합니다.'
);
$connection->serverVersion = '5.5.5-10.11.8-MariaDB-0ubuntu0.24.04.1';
setupSame([], $platformReadiness->blockers(), 'MariaDB 호환 접두사가 있어도 실제 버전을 판독해야 합니다.');
$connection->serverVersion = '10.0.38-MariaDB';
setupAssert(
    str_contains(implode(' ', $platformReadiness->blockers()), 'MariaDB 10.1.0 이상'),
    '구형 MariaDB는 설치 전에 차단해야 합니다.'
);
$connection->serverVersion = '';
setupAssert(
    str_contains(implode(' ', $platformReadiness->blockers()), '버전을 확인하지 못했습니다'),
    'DB 서버 버전을 판독하지 못하면 설치를 차단해야 합니다.'
);
$connection->available = false;
setupAssert(
    str_contains(implode(' ', $platformReadiness->blockers()), '연결을 확인'),
    '플랫폼 DB 연결이 없으면 설치를 차단해야 합니다.'
);
$connection->available = true;
$connection->serverVersion = '8.0.36';
$wordpressVersionConnection = new WordPressPlatformConnection(new class {
    public function get_results(string $sql, mixed $mode = null): array
    {
        unset($sql, $mode);

        return [];
    }

    public function query(string $sql): int
    {
        unset($sql);

        return 0;
    }

    public function db_server_info(): string
    {
        return '5.5.5-10.11.8-MariaDB-0ubuntu0.24.04.1';
    }
});
setupSame(
    '5.5.5-10.11.8-MariaDB-0ubuntu0.24.04.1',
    $wordpressVersionConnection->serverVersion(),
    'WordPress 연결은 MariaDB 공급자 정보가 남은 원본 서버 버전을 반환해야 합니다.'
);
$marker = new SchemaOwnershipMarker();
$planner = new InstallationPlanner($targets, $schemas, $connection, $marker);
$installer = new SchemaInstaller($connection, new SchemaSqlCompiler($marker));
$roleSynchronizer = new MemoryRoleSynchronizer();
$lock = new MemorySetupLock();
$legacyPresence = new MemoryLegacyInstallationPresence();
$readiness = new MemorySetupReadinessProbe(['PHP OpenSSL 확장이 필요합니다.']);
$coordinator = new SetupCoordinator(
    $targets,
    $schemas,
    $connection,
    $planner,
    $installer,
    new PermissionInstaller($capabilities, $roles, $roleSynchronizer),
    $settingsService,
    new InstallationIdFactory(),
    $lock,
    $legacyPresence,
    $readiness
);

setupSame(SetupMode::FreshInstall, $coordinator->inspect()->mode(), '빈 환경은 신규 설치여야 합니다.');
$readinessBlocked = false;
try {
    $coordinator->run();
} catch (RuntimeException) {
    $readinessBlocked = true;
}
setupAssert($readinessBlocked, '필수 실행 환경이 없으면 설치 쓰기를 시작하면 안 됩니다.');
setupSame([], $connection->statements, '실행 환경 차단 상태에서 DDL을 실행하면 안 됩니다.');
$readiness->currentBlockers = [];
$result = $coordinator->run();
setupSame(12, count($result->createdTables()), '첫 두 파동에서 Jobs·Auth 테이블 12개를 만들어야 합니다.');
$createdOrder = $result->createdTables();
$jobsParentPosition = array_search('background_jobs', $createdOrder, true);
foreach (['background_job_events', 'background_job_items', 'background_job_steps'] as $dependentTable) {
    $dependentPosition = array_search($dependentTable, $createdOrder, true);
    setupAssert(
        is_int($jobsParentPosition)
            && is_int($dependentPosition)
            && $jobsParentPosition < $dependentPosition,
        'Jobs 부모 테이블은 외래 키를 가진 하위 테이블보다 먼저 생성되어야 합니다.'
    );
}
setupSame(38, count($result->pendingTables()), 'AI 사용량과 요청 원장을 포함한 후속 스키마 38개는 대기해야 합니다.');
setupSame(12, count($connection->statements), '실제 실행한 DDL은 Jobs·Auth 12개여야 합니다.');
setupSame(17, count($roleSynchronizer->synchronized), 'WordPress 역할 17개를 동기화해야 합니다.');
$expectedAdministratorCapabilities = array_keys($capabilities->all());
sort($expectedAdministratorCapabilities);
setupSame(
    $expectedAdministratorCapabilities,
    $roleSynchronizer->administratorCapabilities,
    'administrator에는 등록된 모든 OJ 권한을 동기화해야 합니다.'
);
setupAssert(! $lock->held, '설치 실행 뒤 전역 잠금을 해제해야 합니다.');
setupSame(SetupMode::Resume, $result->snapshot()->mode(), '부분 설치는 재개 모드여야 합니다.');
setupSame('waiting_for_modules', $result->snapshot()->status(), '기능 스키마 대기 상태를 기록해야 합니다.');

$second = $coordinator->run();
setupSame([], $second->createdTables(), '같은 계획 재개에서 Jobs 테이블을 다시 만들면 안 됩니다.');
setupSame(12, count($connection->statements), '재개가 DDL을 중복 실행하면 안 됩니다.');

$completionBlocked = false;
try {
    $coordinator->markComplete();
} catch (RuntimeException) {
    $completionBlocked = true;
}
setupAssert($completionBlocked, '목표 테이블이 없으면 완료 표식을 기록하면 안 됩니다.');

foreach ([
    'problems-schema',
    'submissions-schema',
    'classes-schema',
    'contests-schema',
    'groups-schema',
    'ai-feedback-schema',
    'messaging-schema',
    'settings-schema',
] as $schemaConfig) {
    foreach ($loader->load($schemaConfig) as $definition) {
        $schemas->register(SchemaTableDefinition::fromArray($definition));
    }
}
$completed = $coordinator->run();
setupSame([], $completed->pendingTables(), '전체 스키마가 등록되면 대기 테이블이 없어야 합니다.');
setupSame(
    SetupMode::AlreadyInstalled,
    $completed->snapshot()->mode(),
    '마지막 설치 실행에서 즉시 완료 모드로 전환해야 합니다.'
);
setupSame('complete', $completed->snapshot()->status(), '설치 완료 표식을 같은 실행에서 기록해야 합니다.');

// markComplete() ran above, recording completed_at. A stray later re-run of
// the legacy branch (e.g. an admin clicking the only action offered while
// briefly and incorrectly parked in MigrationTarget mode) can overwrite
// status back to 'migration_ready' without touching completed_at, since
// saveState() merges. Once every target table is present and correct again,
// this must resolve back to AlreadyInstalled — not loop back into the
// legacy migration ceremony, which has no path forward for a site that was
// never actually a fresh legacy adoption.
$demotedState = $settingsService->read('install_state', []);
$demotedValue = $demotedState->value();
setupAssert(is_array($demotedValue) && isset($demotedValue['completed_at']), '완료 표식은 재설치 이후에도 남아 있어야 합니다.');
$settingsService->save(
    'install_state',
    array_merge($demotedValue, ['status' => 'migration_ready', 'legacy_candidate' => true]),
    $demotedState->revision()
);
$demoted = $coordinator->inspect();
setupSame(
    SetupMode::AlreadyInstalled,
    $demoted->mode(),
    '완료 이력이 있고 모든 대상 테이블이 정상이면 상태 문자열이 되돌아가도 정상 운영으로 돌아와야 합니다.'
);
setupAssert($demoted->allowsRuntime(), '완료 이력이 있는 사이트는 되돌아간 상태에서도 정상 런타임을 허용해야 합니다.');
setupAssert(! $demoted->isMigrationRuntime(), '완료 이력이 있는 사이트를 제한 런타임으로 분류하면 안 됩니다.');

$failureConnection = new MemoryPlatformConnection();
$failureConnection->failOnExecuteCall = 2;
$failureLock = new MemorySetupLock();
$failureCoordinator = new SetupCoordinator(
    $targets,
    $schemas,
    $failureConnection,
    new InstallationPlanner($targets, $schemas, $failureConnection, $marker),
    new SchemaInstaller($failureConnection, new SchemaSqlCompiler($marker)),
    new PermissionInstaller($capabilities, $roles, new MemoryRoleSynchronizer()),
    new SettingsService($settings, new MemorySettingStore()),
    new InstallationIdFactory(),
    $failureLock,
    new MemoryLegacyInstallationPresence(),
    new MemorySetupReadinessProbe()
);
$ddlFailureReported = false;
try {
    $failureCoordinator->run();
} catch (RuntimeException $exception) {
    $ddlFailureReported = str_contains($exception->getMessage(), '강제한 DDL 실행 실패')
        && str_contains($exception->getMessage(), '플랫폼 테이블');
}
setupAssert($ddlFailureReported, 'DDL 실패에 대상 테이블과 원인을 함께 알려야 합니다.');
setupSame(1, count($failureConnection->tables), 'DDL 실패 전 생성된 테이블만 남아야 합니다.');
setupAssert(! $failureLock->held, 'DDL 실패 뒤에도 설치 잠금을 해제해야 합니다.');
$failedSnapshot = $failureCoordinator->inspect();
setupAssert(
    str_contains((string) $failedSnapshot->lastErrorMessage(), '강제한 DDL 실행 실패'),
    '설치 화면에서 확인할 수 있도록 마지막 실패 원인을 보존해야 합니다.'
);
$failureConnection->failOnExecuteCall = null;
$resumedAfterFailure = $failureCoordinator->run();
setupSame([], $resumedAfterFailure->pendingTables(), 'DDL 실패 뒤 재실행으로 전체 설치를 완료해야 합니다.');
setupSame(
    SetupMode::AlreadyInstalled,
    $resumedAfterFailure->snapshot()->mode(),
    'DDL 실패 복구 실행이 완료 모드로 전환되어야 합니다.'
);

$upgradeInstallationId = 'fedcba9876543210fedcba9876543210';
$upgradeTargets = new DefinitionRegistry(
    'upgrade-targets',
    TargetTableDefinition::class
);
$upgradeTargets->register(TargetTableDefinition::fromArray([
    'name' => 'upgrade_probe',
    'owner_module' => 'auth',
    'wave' => 0,
    'engine' => 'InnoDB',
    'charset' => 'utf8mb4',
]));
$upgradeV1 = SchemaTableDefinition::fromArray([
    'name' => 'upgrade_probe',
    'owner_module' => 'auth',
    'schema_version' => '1',
    'columns' => [[
        'name' => 'id',
        'type' => 'BIGINT UNSIGNED',
        'nullable' => false,
        'auto_increment' => true,
    ]],
    'indexes' => [[
        'columns' => ['id'],
        'unique' => true,
        'primary' => true,
    ]],
]);
$upgradeV2 = SchemaTableDefinition::fromArray([
    'name' => 'upgrade_probe',
    'owner_module' => 'auth',
    'schema_version' => '2',
    'columns' => [
        [
            'name' => 'id',
            'type' => 'BIGINT UNSIGNED',
            'nullable' => false,
            'auto_increment' => true,
        ],
        [
            'name' => 'label',
            'type' => 'VARCHAR(100)',
            'nullable' => false,
            'default' => '',
        ],
    ],
    'indexes' => [[
        'columns' => ['id'],
        'unique' => true,
        'primary' => true,
    ]],
]);
$upgradeSchemas = new DefinitionRegistry(
    'upgrade-schemas',
    SchemaTableDefinition::class
);
$upgradeSchemas->register($upgradeV2);
$upgradeConnection = new MemoryPlatformConnection();
$upgradeConnection->tables['upgrade_probe'] = new PlatformTableState(
    'upgrade_probe',
    $marker->create($upgradeInstallationId, $upgradeV1),
    'InnoDB',
    'utf8mb4_unicode_ci'
);
$upgradeStore = new MemorySettingStore();
$upgradeSettings = new SettingsService($settings, $upgradeStore);
$installationSnapshot = $upgradeSettings->read('installation_id');
$upgradeSettings->save(
    'installation_id',
    $upgradeInstallationId,
    $installationSnapshot->revision()
);
$stateSnapshot = $upgradeSettings->read('install_state', []);
$upgradeSettings->save(
    'install_state',
    ['status' => 'complete', 'roles_synchronized' => true],
    $stateSnapshot->revision()
);
$upgradeCoordinator = new SetupCoordinator(
    $upgradeTargets,
    $upgradeSchemas,
    $upgradeConnection,
    new InstallationPlanner(
        $upgradeTargets,
        $upgradeSchemas,
        $upgradeConnection,
        $marker
    ),
    new SchemaInstaller(
        $upgradeConnection,
        new SchemaSqlCompiler($marker)
    ),
    new PermissionInstaller($capabilities, $roles, new MemoryRoleSynchronizer()),
    $upgradeSettings,
    new InstallationIdFactory(),
    new MemorySetupLock(),
    new MemoryLegacyInstallationPresence(),
    new MemorySetupReadinessProbe()
);
$upgradeBefore = $upgradeCoordinator->inspect();
setupSame(
    SetupMode::Resume,
    $upgradeBefore->mode(),
    '구버전 소유 테이블은 충돌이 아니라 업그레이드 재개 모드여야 합니다.'
);
setupSame(
    ['upgrade_probe'],
    $upgradeBefore->pendingUpgrades(),
    '업그레이드가 필요한 소유 테이블을 별도로 표시해야 합니다.'
);
setupSame([], $upgradeBefore->collisions(), '구버전 소유 테이블을 외부 충돌로 분류하면 안 됩니다.');
$upgradeConnection->failNextSynchronize = true;
$upgradeFailed = false;
try {
    $upgradeCoordinator->run();
} catch (RuntimeException $error) {
    $upgradeFailed = str_contains($error->getMessage(), '강제한 스키마 동기화 실패')
        && str_contains($error->getMessage(), 'upgrade_probe')
        && str_contains($error->getMessage(), '업그레이드');
}
setupAssert($upgradeFailed, '스키마 동기화 실패를 호출자에게 알려야 합니다.');
setupSame(
    ['upgrade_probe'],
    $upgradeCoordinator->inspect()->pendingUpgrades(),
    '동기화 실패 뒤 동일 테이블 업그레이드를 다시 계획해야 합니다.'
);
$upgradeResult = $upgradeCoordinator->run();
setupSame(
    ['upgrade_probe'],
    $upgradeResult->updatedTables(),
    '재실행에서 구버전 소유 테이블을 업그레이드해야 합니다.'
);
setupSame([], $upgradeResult->createdTables(), '업그레이드 테이블을 신규 생성으로 기록하면 안 됩니다.');
setupSame(
    SetupMode::AlreadyInstalled,
    $upgradeResult->snapshot()->mode(),
    '업그레이드와 소유권 갱신 뒤 정상 운영 모드로 복귀해야 합니다.'
);
setupAssert(
    $marker->belongsTo(
        $upgradeConnection->tables['upgrade_probe']->comment(),
        $upgradeInstallationId,
        $upgradeV2
    ),
    '업그레이드 성공 뒤 최신 스키마 지문을 기록해야 합니다.'
);
setupSame(
    1,
    count($upgradeConnection->synchronizations),
    '실패 재실행을 제외하면 스키마 동기화를 한 번 완료해야 합니다.'
);

$collisionConnection = new MemoryPlatformConnection();
$collisionConnection->tables['background_jobs'] = new PlatformTableState(
    'background_jobs',
    'external-owner',
    'InnoDB',
    'utf8mb4_unicode_ci'
);
$collisionPlanner = new InstallationPlanner(
    $targets,
    $schemas,
    $collisionConnection,
    $marker
);
$collisionPlan = $collisionPlanner->plan('0123456789abcdef0123456789abcdef');
setupSame(['background_jobs'], $collisionPlan->collisions(), '외부 동명 테이블을 충돌로 분류해야 합니다.');
setupAssert(! $collisionPlan->canExecute(), '충돌 계획은 실행할 수 없어야 합니다.');

$makeMigrationCoordinator = static function (
    MemoryPlatformConnection $platformConnection,
    MemorySettingStore $store,
    MemoryLegacyInstallationPresence $presence
) use ($targets, $schemas, $settings, $capabilities, $roles, $marker, $loader): array {
    $service = new SettingsService($settings, $store);
    $synchronizer = new MemoryRoleSynchronizer();
    $setupLock = new MemorySetupLock();

    return [
        new SetupCoordinator(
            $targets,
            $schemas,
            $platformConnection,
            new InstallationPlanner($targets, $schemas, $platformConnection, $marker),
            new SchemaInstaller($platformConnection, new SchemaSqlCompiler($marker)),
            new PermissionInstaller($capabilities, $roles, $synchronizer),
            $service,
            new InstallationIdFactory(),
            $setupLock,
            $presence,
            new MemorySetupReadinessProbe(),
            $loader->load('additive-install-modules')
        ),
        $service,
        $synchronizer,
        $setupLock,
    ];
};

$legacyConnection = new MemoryPlatformConnection();
$legacyStore = new MemorySettingStore();
$legacyPresence = new MemoryLegacyInstallationPresence(
    new LegacyInstallationReport(['wp_oj_users'], [])
);
[
    $legacyCoordinator,
    $legacySettings,
    $legacyRoleSynchronizer,
    $legacyLock,
] = $makeMigrationCoordinator($legacyConnection, $legacyStore, $legacyPresence);
$legacyBefore = $legacyCoordinator->inspect();
setupSame(
    SetupMode::MigrationTarget,
    $legacyBefore->mode(),
    '기존 소스 테이블이 있으면 이전 대상으로 분류해야 합니다.'
);
setupSame(['wp_oj_users'], $legacyBefore->legacyTables(), '감지한 기존 테이블을 설치 화면에 전달해야 합니다.');
setupSame([], $legacyStore->values, '기존 설치 감지는 설정을 쓰지 않아야 합니다.');
setupSame([], $legacyConnection->statements, '기존 설치 감지는 DDL을 실행하지 않아야 합니다.');

$legacyResult = $legacyCoordinator->run();
setupSame(12, count($legacyResult->createdTables()), '이전 기반은 Jobs·Auth 테이블 12개만 만들어야 합니다.');
setupSame(38, count($legacyResult->pendingTables()), '이전 단계에서는 나머지 플랫폼 테이블 38개를 대기시켜야 합니다.');
setupSame('migration_ready', $legacyResult->snapshot()->status(), '이전 관리 화면 실행 준비 상태를 기록해야 합니다.');
setupAssert($legacyResult->snapshot()->allowsRuntime(), '이전 준비 상태는 제한 런타임을 허용해야 합니다.');
setupAssert($legacyResult->snapshot()->isMigrationRuntime(), '이전 준비 상태는 제한 런타임으로 구분해야 합니다.');
setupSame(17, count($legacyRoleSynchronizer->synchronized), '이전 기반 준비에서도 역할을 동기화해야 합니다.');
setupAssert(! $legacyLock->held, '이전 기반 준비 뒤 설치 잠금을 해제해야 합니다.');

$repeatMigrationBlocked = false;
try {
    $legacyCoordinator->run();
} catch (RuntimeException) {
    $repeatMigrationBlocked = true;
}
setupAssert($repeatMigrationBlocked, '이전 준비가 끝난 뒤 설치 실행을 반복하면 안 됩니다.');
setupSame(12, count($legacyConnection->statements), '이전 준비 실행이 DDL을 중복하면 안 됩니다.');

$migrationState = $legacySettings->read('migration', []);
$legacySettings->save('migration', ['status' => 'completed'], $migrationState->revision());
$prematureMigration = $legacyCoordinator->inspect();
setupSame(
    SetupMode::MigrationTarget,
    $prematureMigration->mode(),
    '이전 상태만 완료이고 목표 테이블이 빠졌으면 정상 운영으로 전환하면 안 됩니다.'
);
setupAssert(
    $prematureMigration->isMigrationRuntime(),
    '목표 테이블이 모두 준비될 때까지 제한 런타임을 유지해야 합니다.'
);
$legacyInstallationId = $legacySettings->read('installation_id')->value();
setupAssert(is_string($legacyInstallationId), '이전 설치 식별자가 있어야 합니다.');
$remainingPlan = (new InstallationPlanner(
    $targets,
    $schemas,
    $legacyConnection,
    $marker
))->plan($legacyInstallationId);
(new SchemaInstaller(
    $legacyConnection,
    new SchemaSqlCompiler($marker)
))->execute($remainingPlan);
$afterMigration = $legacyCoordinator->inspect();
setupSame(
    SetupMode::AlreadyInstalled,
    $afterMigration->mode(),
    '이전 완료 상태는 정상 운영 모드로 전환해야 합니다.'
);
setupAssert($afterMigration->allowsRuntime(), '이전 완료 후 정상 런타임을 허용해야 합니다.');
setupAssert(! $afterMigration->isMigrationRuntime(), '이전 완료 후 제한 런타임을 해제해야 합니다.');

unset($legacyConnection->tables['ai_usage_logs']);
setupSame(SetupMode::Resume, $legacyCoordinator->inspect()->mode(), '기존 이전 완료 사이트의 AI 사용량 추가는 설치 재개로 처리합니다.');
$usageInstall = $legacyCoordinator->run();
setupSame(['ai_usage_logs'], $usageInstall->createdTables(), 'AI 사용량 테이블만 추가하며 기존 데이터는 재이전하지 않습니다.');
setupSame(SetupMode::AlreadyInstalled, $usageInstall->snapshot()->mode(), 'AI 사용량 설치 후 정상 운영으로 복귀합니다.');
unset($legacyConnection->tables['addon_ai_requests']);
setupSame(SetupMode::Resume,$legacyCoordinator->inspect()->mode(),'AI 요청 원장은 기존 운영 사이트의 설치 재개 경로로 추가합니다.');
$ledgerInstall=$legacyCoordinator->run();
setupSame(['addon_ai_requests'],$ledgerInstall->createdTables(),'AI 원장 테이블만 추가하며 문제와 기존 제출을 다시 이전하지 않습니다.');
setupSame(SetupMode::AlreadyInstalled,$ledgerInstall->snapshot()->mode(),'AI 원장 설치 후 운영 상태로 복귀합니다.');

// Legacy source tables (solution/problem/users, ...) never disappear once
// adopted, so a routine schema upgrade on an already-fully-installed site
// (every target table present, just one column definition changed) must
// route to the plain Resume flow — not back into the full backup-evidence/
// observation MigrationTarget ceremony, which has no path forward for a
// change this small.
$staleClassesComment = 'oj-platform|installation=' . $legacyInstallationId
    . '|module=' . $schemas->find('classes')->owner()->value()
    . '|table=classes|version=stale|fingerprint=stale';
$legacyConnection->tables['classes'] = new PlatformTableState(
    'classes',
    $staleClassesComment,
    'InnoDB',
    'utf8mb4_unicode_ci'
);
$staleUpgrade = $legacyCoordinator->inspect();
setupSame(
    SetupMode::Resume,
    $staleUpgrade->mode(),
    '이미 설치가 끝난 사이트의 사소한 스키마 업그레이드는 이전 마법사로 되돌리면 안 됩니다.'
);
setupAssert(
    ! $staleUpgrade->allowsRuntime(),
    '보류 중인 업그레이드가 있으면 정상 런타임을 계속 막아야 합니다.'
);
setupAssert(
    ! $staleUpgrade->isMigrationRuntime(),
    '단순 업그레이드는 제한 런타임(이전 전용)으로 분류하면 안 됩니다.'
);
$legacyConnection->tables['classes'] = new PlatformTableState(
    'classes',
    $marker->create($legacyInstallationId, $schemas->find('classes')),
    'InnoDB',
    'utf8mb4_unicode_ci'
);
$afterStaleUpgradeFixed = $legacyCoordinator->inspect();
setupSame(
    SetupMode::AlreadyInstalled,
    $afterStaleUpgradeFixed->mode(),
    '스키마 업그레이드를 반영하면 다시 정상 운영 모드로 돌아와야 합니다.'
);

$legacyConnection->tables['classes'] = new PlatformTableState(
    'classes',
    'unknown-owner',
    'InnoDB',
    'utf8mb4_unicode_ci'
);
$tamperedMigration = $legacyCoordinator->inspect();
setupSame(SetupMode::NameCollision, $tamperedMigration->mode(), '완료 뒤 소유권 충돌도 정상 런타임을 차단해야 합니다.');
setupAssert(! $tamperedMigration->allowsRuntime(), '동명 충돌 상태에서 이전 준비 문자열만으로 런타임을 열면 안 됩니다.');
$legacyConnection->tables['classes'] = new PlatformTableState(
    'classes',
    $marker->create($legacyInstallationId, $schemas->find('classes')),
    'InnoDB',
    'utf8mb4_unicode_ci'
);

$recognizedConnection = new MemoryPlatformConnection();
$recognizedConnection->tables['problem_metadata'] = new PlatformTableState(
    'problem_metadata',
    'legacy-owner',
    'InnoDB',
    'utf8mb4_unicode_ci'
);
[
    $recognizedCoordinator,
] = $makeMigrationCoordinator(
    $recognizedConnection,
    new MemorySettingStore(),
    new MemoryLegacyInstallationPresence(
        new LegacyInstallationReport(['problem_metadata'], ['problem_metadata'])
    )
);
$recognizedSnapshot = $recognizedCoordinator->inspect();
setupSame(
    SetupMode::MigrationTarget,
    $recognizedSnapshot->mode(),
    '알려진 기존 목표 이름은 일반 충돌이 아니라 이전 대상으로 분류해야 합니다.'
);
setupSame([], $recognizedSnapshot->collisions(), '알려진 기존 목표 이름을 충돌 목록에서 제외해야 합니다.');
setupSame([], $recognizedConnection->statements, '알려진 기존 목표 검사도 DDL을 실행하지 않아야 합니다.');

$unknownConnection = new MemoryPlatformConnection();
$unknownConnection->tables['classes'] = new PlatformTableState(
    'classes',
    'unknown-owner',
    'InnoDB',
    'utf8mb4_unicode_ci'
);
[
    $unknownCoordinator,
] = $makeMigrationCoordinator(
    $unknownConnection,
    new MemorySettingStore(),
    new MemoryLegacyInstallationPresence()
);
$unknownSnapshot = $unknownCoordinator->inspect();
setupSame(SetupMode::NameCollision, $unknownSnapshot->mode(), '알 수 없는 동명 테이블은 설치를 차단해야 합니다.');
setupSame(['classes'], $unknownSnapshot->collisions(), '알 수 없는 동명 테이블 이름을 정확히 알려야 합니다.');

final class SetupWordPressRole
{
    /** @param array<string, bool> $capabilities */
    public function __construct(public array $capabilities)
    {
    }

    public function add_cap(string $capability, bool $grant = true): void
    {
        $this->capabilities[$capability] = $grant;
    }

    public function remove_cap(string $capability): void
    {
        unset($this->capabilities[$capability]);
    }
}

if (! function_exists('get_role')) {
    function get_role(string $role): ?SetupWordPressRole
    {
        $value = $GLOBALS['setup_wordpress_roles'][$role] ?? null;

        return $value instanceof SetupWordPressRole ? $value : null;
    }
}

if (! function_exists('add_role')) {
    /** @param array<string, bool> $capabilities */
    function add_role(
        string $role,
        string $displayName,
        array $capabilities = []
    ): ?SetupWordPressRole {
        unset($displayName);
        if (isset($GLOBALS['setup_wordpress_roles'][$role])) {
            return null;
        }

        $created = new SetupWordPressRole($capabilities);
        $GLOBALS['setup_wordpress_roles'][$role] = $created;

        return $created;
    }
}

$GLOBALS['setup_wordpress_roles'] = [
    'administrator' => new SetupWordPressRole([
        'manage_options' => true,
        'oj_removed_capability' => true,
    ]),
];
$wordpressRoleReport = (new PermissionInstaller(
    $capabilities,
    $roles,
    new WordPressRoleSynchronizer()
))->synchronizeRoles();
$administratorRole = get_role('administrator');
setupAssert($administratorRole instanceof SetupWordPressRole, 'administrator 역할을 유지해야 합니다.');
setupSame(17, count($wordpressRoleReport->createdRoles()), '신규 설치 역할 17개를 생성해야 합니다.');
setupAssert(
    in_array('administrator', $wordpressRoleReport->updatedRoles(), true),
    'administrator 권한 동기화를 보고해야 합니다.'
);
setupAssert(
    isset($administratorRole->capabilities['manage_options']),
    '기존 WordPress 관리자 권한을 제거하면 안 됩니다.'
);
setupAssert(
    ! isset($administratorRole->capabilities['oj_removed_capability']),
    'Registry에서 제거된 OJ 권한은 administrator에서 정리해야 합니다.'
);
foreach ($expectedAdministratorCapabilities as $capability) {
    setupAssert(
        ($administratorRole->capabilities[$capability] ?? false) === true,
        'administrator에 OJ 권한이 누락되었습니다: ' . $capability
    );
}

$guardianV2Data = array_values(array_filter($loader->load('auth-schema'), static fn (array $definition): bool => $definition['name'] === 'guardian_verifications'))[0];
$guardianV1Data = $guardianV2Data;
$guardianV1Data['schema_version'] = '1';
$guardianV1Data['columns'] = array_values(array_filter($guardianV1Data['columns'], static fn (array $column): bool => ! in_array($column['name'], ['document_id', 'document_version', 'document_sha256', 'guardian_relationship', 'verification_method'], true)));
$guardianUpgradeTargets = new DefinitionRegistry('guardian-upgrade-targets', TargetTableDefinition::class);
$guardianUpgradeTargets->register($targets->find('guardian_verifications'));
$guardianUpgradeSchemas = new DefinitionRegistry('guardian-upgrade-schemas', SchemaTableDefinition::class);
$guardianUpgradeSchemas->register($guardianSchema);
$guardianUpgradeConnection = new MemoryPlatformConnection();
$guardianUpgradeConnection->tables['guardian_verifications'] = new PlatformTableState('guardian_verifications', $marker->create($upgradeInstallationId, SchemaTableDefinition::fromArray($guardianV1Data)), 'InnoDB', 'utf8mb4_unicode_ci');
$guardianPlan = (new InstallationPlanner($guardianUpgradeTargets, $guardianUpgradeSchemas, $guardianUpgradeConnection, $marker))->plan($upgradeInstallationId);
setupSame(['guardian_verifications'], $guardianPlan->upgradeTables(), '실제 법정대리인 구버전 테이블을 업그레이드 대상으로 인식해야 합니다.');
setupSame([], $guardianPlan->collisions(), '기존 동의 테이블을 외부 충돌로 처리하면 안 됩니다.');
$guardianResult = (new SchemaInstaller($guardianUpgradeConnection, new SchemaSqlCompiler($marker)))->execute($guardianPlan);
setupAssert($guardianResult->isComplete(), '법정대리인 동의 증빙 열을 공통 업그레이드 절차로 설치해야 합니다.');

// A completed runtime skips metadata work, but setup actions, state and readiness changes do not.
$runtimeCache = new class implements \CodingPanda\OjPlatform\Kernel\Setup\SetupInspectionCache {
    public ?string $key = null;
    public ?\CodingPanda\OjPlatform\Kernel\Setup\SetupSnapshot $snapshot = null;
    public function read(string $fingerprint): ?\CodingPanda\OjPlatform\Kernel\Setup\SetupSnapshot { return $this->key === $fingerprint ? $this->snapshot : null; }
    public function write(string $fingerprint, \CodingPanda\OjPlatform\Kernel\Setup\SetupSnapshot $snapshot): void {
        if ($snapshot->mode() === SetupMode::AlreadyInstalled && $snapshot->isRuntimeReady()) { $this->key = $fingerprint; $this->snapshot = $snapshot; }
    }
    public function clear(): void { $this->key = null; $this->snapshot = null; }
};
$cacheSettings = new SettingsService($settings, new MemorySettingStore());
$cacheId = str_repeat('d', 32);
$cacheSettings->save('installation_id', $cacheId, 0);
$cacheSettings->save('install_state', ['status' => 'complete', 'roles_synchronized' => true], 0);
$cacheConnection = new MemoryPlatformConnection();
foreach ($schemas->all() as $schema) {
    $cacheConnection->tables[$schema->key()] = new PlatformTableState($schema->key(), $marker->create($cacheId, $schema), 'InnoDB', 'utf8mb4_unicode_ci');
}
$cacheLegacy = new MemoryLegacyInstallationPresence();
$cacheReadiness = new MemorySetupReadinessProbe();
$cachedCoordinator = new SetupCoordinator($targets, $schemas, $cacheConnection,
    new InstallationPlanner($targets, $schemas, $cacheConnection, $marker),
    new SchemaInstaller($cacheConnection, new SchemaSqlCompiler($marker)),
    new PermissionInstaller($capabilities, $roles, new MemoryRoleSynchronizer()), $cacheSettings,
    new InstallationIdFactory(), new MemorySetupLock(), $cacheLegacy, $cacheReadiness, [], $runtimeCache);
setupSame(SetupMode::AlreadyInstalled, $cachedCoordinator->inspectRuntime()->mode(), '최초 런타임은 실제 설치 상태를 검증해야 합니다.');
setupSame(1, $cacheLegacy->inspectCalls, '콜드 런타임은 레거시 검사를 한 번 실행해야 합니다.');
setupSame(1, $cacheConnection->inspectCalls, '콜드 런타임은 테이블 상태를 한 번 읽어야 합니다.');
$cachedCoordinator->inspectRuntime();
setupSame(1, $cacheLegacy->inspectCalls, '정상 캐시에서 레거시 메타데이터 쿼리를 반복하면 안 됩니다.');
setupSame(1, $cacheConnection->inspectCalls, '정상 캐시에서 전체 테이블 상태를 반복하면 안 됩니다.');
$cacheReadiness->currentBlockers = ['실행 환경 변경'];
setupAssert(! $cachedCoordinator->inspectRuntime()->isRuntimeReady(), '실행 환경 변경은 구조 캐시가 있어도 즉시 반영해야 합니다.');
$cacheReadiness->currentBlockers = [];
$cachedCoordinator->inspectRuntime();
$beforeManual = $cacheConnection->inspectCalls;
$cachedCoordinator->inspect();
setupSame($beforeManual + 1, $cacheConnection->inspectCalls, '관리·설치 검사는 캐시를 우회해야 합니다.');
$cachedCoordinator->inspectRuntime();
$cacheState = $cacheSettings->read('install_state');
$cacheSettings->save('install_state', ['status' => 'interrupted'], $cacheState->revision());
setupAssert($cachedCoordinator->inspectRuntime()->mode() !== SetupMode::AlreadyInstalled, '설치 상태 변경 후 이전 정상 캐시를 재사용하면 안 됩니다.');
setupSame(null, $runtimeCache->snapshot, '미완료 설치 결과는 캐시에 저장하면 안 됩니다.');

fwrite(STDOUT, sprintf("R-04/R-11 설치 단위 검사 통과: assertion %d개\n", $assertions));
