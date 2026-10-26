<?php

declare(strict_types=1);

if (! function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'https://example.test/wp-content/plugins/oj-platform/' . ltrim($path, '/');
    }
}

use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Composition\FoundationServiceIds;
use CodingPanda\OjPlatform\Kernel\Exception\KernelException;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Module\ModuleManifest;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Kernel\Registry\WebHandlerContractValidator;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\ActionHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteUrlGenerator;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteMatcher;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandlerCollection;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\SafeRedirect;
use CodingPanda\OjPlatform\Modules\AdminConsole\Application\MenuTree;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserDirectory;
use CodingPanda\OjPlatform\Modules\Jobs\Application\IdempotencyKeyFactory;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobStateMachine;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobStatus;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeProfileDefinition;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnector;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionSetup;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaSqlCompiler;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\TargetTableDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAiAvailability;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder as PublicPageResponder;
use CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Domain\SiteSettings;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionSourceViewProvider;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$assertions = 0;

function foundationAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function foundationSame(mixed $expected, mixed $actual, string $message): void
{
    foundationAssert(
        $expected === $actual,
        $message
        . PHP_EOL
        . 'Expected: ' . var_export($expected, true)
        . PHP_EOL
        . 'Actual: ' . var_export($actual, true)
    );
}

final class FoundationHookRegistrar implements HookRegistrar
{
    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $acceptedArguments = 0
    ): void {
    }
}

final class FoundationErrorReporter implements ErrorReporter
{
    public function report(Throwable $error): void
    {
    }
}

$environment = PluginEnvironment::fromPluginFile(
    $pluginRoot . '/oj-platform.php',
    '2.0.0-dev.102',
    '6.6',
    '8.1'
);
$container = (new CompositionRoot(
    $environment,
    new FoundationHookRegistrar(),
    new FoundationErrorReporter()
))->build();
$kernel = $container->get(Kernel::class);
foundationAssert($kernel instanceof Kernel, 'Kernel 서비스가 없습니다.');
$publicContracts = [
    RouteHandlerCollection::class,
    RouteUrlProvider::class,
    SafeRedirect::class,
    ConsoleResponder::class,
    ActionAccessGuard::class,
    ActionResponse::class,
    CurrentAccountProvider::class,
    UserDirectory::class,
    JobDispatcher::class,
    JudgeConnector::class,
    JudgeResults::class,
    Authorization::class,
    PermissionSetup::class,
    PublicPageResponder::class,
    FeatureFlags::class,
    SettingsManager::class,
    PerformanceModePolicy::class,
    SubmissionProjectionProvider::class,
    SubmissionSourceViewProvider::class,
];
foreach ($publicContracts as $contract) {
    foundationAssert(
        $container->get($contract) instanceof $contract,
        '공개 Contract가 Composition Root에 연결되지 않았습니다: ' . $contract
    );
}
$report = $kernel->boot();

foundationSame(
    [
        'permission',
        'settings',
        'platform_db',
        'judge_db',
        'jobs',
        'access_router',
        'auth',
        'public_ui',
        'problems',
        'submissions',
        'classes',
        'contests',
        'groups',
        'ai_feedback',
        'learning_dashboard',
        'ranking',
        'blockly',
        'board',
        'messaging',
        'admin_console',
        'migration',
    ],
    $report->moduleIds(),
    '공통 기반 모듈 순서가 올바르지 않습니다.'
);

$manifest = $container->get(ModuleManifest::class);
foundationAssert($manifest instanceof ModuleManifest, '목표 모듈 manifest가 없습니다.');
foundationSame(21, $manifest->count(), '목표 모듈 수가 올바르지 않습니다.');
$moduleTypeCounts = [
    ModuleType::Required->value => 0,
    ModuleType::Optional->value => 0,
    ModuleType::Locked->value => 0,
];
foreach ($manifest->all() as $moduleItem) {
    ++$moduleTypeCounts[$moduleItem->type()->value];
}
foundationSame(
    ['required' => 9, 'optional' => 11, 'locked' => 1],
    $moduleTypeCounts,
    '목표 모듈 유형 분포가 올바르지 않습니다.'
);
foundationSame(
    ModuleType::Locked,
    $manifest->get('migration')->type(),
    '잠금 모듈은 임시 Migration이어야 합니다.'
);

$registryCounts = [
    FoundationServiceIds::CAPABILITIES => 62,
    FoundationServiceIds::ROLES => 17,
    FoundationServiceIds::PERMISSION_PRESETS => 11,
    FoundationServiceIds::FEATURES => 31,
    FoundationServiceIds::SETTINGS => 33,
    FoundationServiceIds::TARGET_TABLES => 50,
    FoundationServiceIds::TABLE_SCHEMAS => 50,
    FoundationServiceIds::JUDGE_PROFILES => 1,
    FoundationServiceIds::JOBS => 13,
    FoundationServiceIds::ROUTES => 170,
    FoundationServiceIds::ACTIONS => 144,
    FoundationServiceIds::MENUS => 65, // Includes archived contests and feedback review.
];

foreach ($registryCounts as $serviceId => $expectedCount) {
    $registry = $container->get($serviceId);
    foundationAssert($registry instanceof DefinitionRegistry, 'Registry 서비스 타입이 올바르지 않습니다.');
    foundationSame($expectedCount, $registry->count(), $serviceId . ' 계약 수가 올바르지 않습니다.');
    foundationAssert($registry->isFrozen(), $serviceId . ' Registry가 동결되지 않았습니다.');
}

$settingsService = $container->get(SettingsService::class);
foundationAssert($settingsService instanceof SettingsService, '설정 서비스가 없습니다.');
$plainSecretRejected = false;
try {
    $settingsService->save('judge_database', ['password' => 'plain-text'], 0);
} catch (RuntimeException) {
    $plainSecretRejected = true;
}
foundationAssert($plainSecretRejected, '민감 설정의 평문 저장을 차단해야 합니다.');
$site = SiteSettings::fromArray([
    'service_name' => '학교 OJ',
    'short_description' => '프로그래밍 수업',
    'support_line' => '평일 09:00~17:00',
    'copyright_notice' => '© 2026 학교명',
    'administrator_email' => 'admin@example.test',
    'footer_links' => [
        ['label' => '학교 홈페이지', 'url' => 'https://school.example.test'],
        ['label' => '도움말', 'url' => '/help'],
        ['label' => '도움말 중복', 'url' => '/help'],
    ],
]);
foundationAssert($site->footerLinkVisible('/terms'), '기존 저장값은 하단 링크를 기본 표시해야 합니다.');
$hiddenSite = SiteSettings::fromArray(['footer_visibility' => ['/terms' => false, '/open-source-licenses' => false]]);
foundationAssert(! SiteSettings::fromArray($hiddenSite->toArray())->footerLinkVisible('/terms'), '표시 여부 저장·복원이 보존되어야 합니다.');
foundationAssert($hiddenSite->footerLinkVisible('/privacy'), '부분 설정은 다른 기본 링크를 숨기면 안 됩니다.');
try {
    SiteSettings::fromArray(['footer_visibility' => ['/unknown' => false]]);
    throw new RuntimeException('Unknown footer link accepted.');
} catch (InvalidArgumentException) {
    foundationAssert(true, '알 수 없는 하단 링크는 거부해야 합니다.');
}
foundationSame(
    [
        ['label' => '학교 홈페이지', 'url' => 'https://school.example.test'],
        ['label' => '도움말', 'url' => '/help'],
    ],
    $site->footerLinks(),
    '사이트 푸터 링크는 입력 순서를 유지하고 같은 주소를 한 번만 저장해야 합니다.'
);
foundationSame('admin@example.test', $site->administratorEmail(), '관리자 이메일 설정이 보존되어야 합니다.');
$unsafeFooterRejected = false;
try {
    SiteSettings::fromArray([
        'footer_links' => [['label' => '위험한 링크', 'url' => 'javascript:alert(1)']],
    ]);
} catch (InvalidArgumentException) {
    $unsafeFooterRejected = true;
}
foundationAssert($unsafeFooterRejected, '푸터에 실행 가능한 주소 스킴을 저장하면 안 됩니다.');
$invalidFooterEmailRejected = false;
try {
    SiteSettings::fromArray(['administrator_email' => 'not-an-email']);
} catch (InvalidArgumentException) {
    $invalidFooterEmailRejected = true;
}
foundationAssert($invalidFooterEmailRejected, '잘못된 관리자 이메일을 저장하면 안 됩니다.');
$reference = new SecretReference('secret:v2:123e4567-e89b-42d3-a456-426614174000');
foundationSame(
    'secret:v2:123e4567-e89b-42d3-a456-426614174000',
    $reference->value(),
    '비밀값 참조 형식이 올바르지 않습니다.'
);

/** @var DefinitionRegistry<CapabilityDefinition> $capabilities */
$capabilities = $container->get(FoundationServiceIds::CAPABILITIES);
$scopeCounts = [];
foreach ($capabilities->all() as $capability) {
    $scope = $capability->scope()->value;
    $scopeCounts[$scope] = ($scopeCounts[$scope] ?? 0) + 1;
}
ksort($scopeCounts);
foundationSame(
    [
        'context_only' => 1,
        'creation' => 1,
        'global' => 51,
        'global_or_context' => 9,
    ],
    $scopeCounts,
    '권한 범위 분포가 동결 계약과 다릅니다.'
);

/** @var DefinitionRegistry<TargetTableDefinition> $targetTables */
$targetTables = $container->get(FoundationServiceIds::TARGET_TABLES);
foreach ($targetTables->all() as $table) {
    foundationAssert(! str_starts_with($table->key(), 'oj_'), '플랫폼 테이블에 oj 접두어가 있습니다.');
    foundationAssert($table->engine() === 'InnoDB', '플랫폼 테이블 엔진이 InnoDB가 아닙니다.');
    foundationAssert($table->charset() === 'utf8mb4', '플랫폼 테이블 문자셋이 utf8mb4가 아닙니다.');
}

/** @var DefinitionRegistry<SchemaTableDefinition> $schemas */
$schemas = $container->get(FoundationServiceIds::TABLE_SCHEMAS);
$schemaNames = array_keys($schemas->all());
sort($schemaNames);
foundationSame(
    [
        'addon_ai_requests',
        'agreement_document_types',
        'agreement_documents',
        'ai_usage_logs',
        'background_job_events',
        'background_job_items',
        'background_job_steps',
        'background_jobs',
        'class_enrollments',
        'class_group_links',
        'class_instructors',
        'class_problem_links',
        'classes',
        'contest_ip_logs',
        'contest_managers',
        'contest_participants',
        'contest_problem_settings',
        'contest_settings',
        'deleted_contests',
        'deleted_users',
        'group_managers',
        'group_memberships',
        'guardian_verifications',
        'learner_level_profiles',
        'learning_groups',
        'message_reports',
        'messages',
        'personal_problem_book_items',
        'problem_book_categories',
        'problem_book_category_items',
        'problem_collaborators',
        'problem_metadata',
        'problem_ownerships',
        'problem_topic_links',
        'quiz_attempt_item_choices',
        'quiz_attempt_items',
        'quiz_attempt_summaries',
        'quiz_choices',
        'quiz_question_bank',
        'quiz_question_bank_choices',
        'quiz_questions',
        'quiz_revisions',
        'submission_ai_feedback',
        'submission_contexts',
        'submission_events',
        'submission_language_settings_log',
        'user_agreement_log',
        'user_login_log',
        'user_preferences',
        'user_profiles',
    ],
    $schemaNames,
    '현재 스키마는 AI 사용량 원장을 포함해 49개여야 합니다.'
);

$compiler = $container->get(SchemaSqlCompiler::class);
foundationAssert($compiler instanceof SchemaSqlCompiler, '스키마 SQL 컴파일러가 없습니다.');
$jobsSchema = $schemas->find('background_jobs');
foundationAssert($jobsSchema instanceof SchemaTableDefinition, 'background_jobs 스키마가 없습니다.');
$createSql = $compiler->compileCreate(
    $jobsSchema,
    '0123456789abcdef0123456789abcdef'
);
foundationAssert(str_starts_with($createSql, 'CREATE TABLE `background_jobs`'), 'CREATE TABLE 문이 올바르지 않습니다.');
foundationAssert(! str_contains($createSql, 'IF NOT EXISTS'), '충돌을 숨기는 IF NOT EXISTS를 사용하면 안 됩니다.');
foundationAssert(str_contains($createSql, 'oj-platform|installation='), '테이블 소유 표식이 없습니다.');

/** @var DefinitionRegistry<JudgeProfileDefinition> $judgeProfiles */
$judgeProfiles = $container->get(FoundationServiceIds::JUDGE_PROFILES);
$judgeProfile = $judgeProfiles->find('default');
foundationAssert($judgeProfile instanceof JudgeProfileDefinition, '기본 Judge 프로필이 없습니다.');
foundationSame('jol', $judgeProfile->databaseName(), 'Judge DB 이름은 jol이어야 합니다.');
foundationSame(8, count($judgeProfile->requiredTables()), 'Judge 필수 테이블 수가 올바르지 않습니다.');
foundationSame(
    ['source_code_user'],
    $judgeProfile->requiredCompatibilityTables(),
    '제출 호환 필수 테이블 계약이 올바르지 않습니다.'
);
foundationAssert(in_array('sim', $judgeProfile->ignoredTables(), true), 'sim 미사용 계약이 없습니다.');
foundationAssert(in_array('solution2', $judgeProfile->ignoredTables(), true), 'solution2 미사용 계약이 없습니다.');

$sharedCredentials = new JudgeCredentials('judge', 'secret');
$singleAccount = new JudgeConnectionSettings(
    '127.0.0.1',
    3306,
    'jol',
    $sharedCredentials,
    new JudgeCredentials('judge', 'secret'),
    false,
    null
);
foundationAssert($singleAccount->usesSingleAccount(), 'Judge 단일 계정 구성을 허용해야 합니다.');
$splitAccount = new JudgeConnectionSettings(
    '127.0.0.1',
    3306,
    'jol',
    new JudgeCredentials('judge_read', 'read-secret'),
    new JudgeCredentials('judge_write', 'write-secret'),
    false,
    null
);
foundationAssert(! $splitAccount->usesSingleAccount(), 'Judge 분리 계정도 구분할 수 있어야 합니다.');

foundationSame(7, count(JobStatus::cases()), 'Jobs 상태는 정확히 7개여야 합니다.');
$stateMachine = new JobStateMachine();
foundationAssert(
    $stateMachine->canTransition(JobStatus::Queued, JobStatus::Running),
    '대기 작업을 실행할 수 있어야 합니다.'
);
foundationAssert(
    ! $stateMachine->canTransition(JobStatus::Completed, JobStatus::Running),
    '완료 작업을 같은 실행으로 다시 시작하면 안 됩니다.'
);

/** @var DefinitionRegistry<JobDefinition> $jobs */
$jobs = $container->get(FoundationServiceIds::JOBS);
$job = $jobs->find('problem_metadata_generate');
foundationAssert($job instanceof JobDefinition, '문제 메타데이터 작업 계약이 없습니다.');
$idempotency = $container->get(IdempotencyKeyFactory::class);
foundationAssert($idempotency instanceof IdempotencyKeyFactory, '멱등키 생성기가 없습니다.');
$firstKey = $idempotency->create($job, 'selected', ['2000', '1000'], '1', ['difficulty' => 5, 'topic' => '배열']);
$secondKey = $idempotency->create($job, 'selected', ['1000', '2000'], '1', ['topic' => '배열', 'difficulty' => 5]);
foundationSame($firstKey, $secondKey, '대상·입력 순서와 무관하게 같은 멱등키를 만들어야 합니다.');

/** @var DefinitionRegistry<RouteDefinition> $routes */
$routes = $container->get(FoundationServiceIds::ROUTES);
$routeAreaCounts = [];
$anonymousRoutes = [];
foreach ($routes->all() as $route) {
    $area = $route->area()->value;
    $routeAreaCounts[$area] = ($routeAreaCounts[$area] ?? 0) + 1;
    if ($route->authentication() === AuthenticationRequirement::Anonymous) {
        $anonymousRoutes[] = $route->key();
    }
}
ksort($routeAreaCounts);
sort($anonymousRoutes);
foundationSame(
    ['console' => 109, 'public' => 59, 'response' => 2],
    $routeAreaCounts,
    'Route 영역 분포가 동결 계약과 다릅니다.'
);
foundationSame(
    [
        'auth.guardian_consent',
        'auth.login',
        'auth.register',
        'legal.copyright',
        'legal.email_collection_refusal',
        'legal.guardian_policy',
        'legal.open_source',
        'legal.privacy',
        'legal.terms',
    ],
    $anonymousRoutes,
    '비로그인 접근 Route 9개가 동결 계약과 다릅니다.'
);

/** @var DefinitionRegistry<ActionDefinition> $actions */
$actions = $container->get(FoundationServiceIds::ACTIONS);
$anonymousActionCount = count(array_filter(
    $actions->all(),
    static fn (ActionDefinition $action): bool =>
        $action->authentication() === AuthenticationRequirement::Anonymous
));
foundationSame(6, $anonymousActionCount, '비로그인 Action 수가 올바르지 않습니다.');

$routeHandlers = $container->get(RouteHandlerRegistry::class);
foundationAssert($routeHandlers instanceof RouteHandlerRegistry, 'Route Handler Registry가 없습니다.');
$problemAiAvailability = $container->get(ProblemAiAvailability::class);
foundationAssert(
    $problemAiAvailability instanceof ProblemAiAvailability
        && ! $problemAiAvailability->isAvailable('problem_authoring_generate'),
    'AI 기능 또는 작업 배정이 준비되지 않았으면 문제 AI가 비활성화되어야 합니다.'
);
$problemRouteHandlers = array_values(array_filter(
    $routeHandlers->keys(),
    static fn (string $key): bool =>
        str_starts_with($key, 'problems.')
        || str_starts_with($key, 'quizzes.')
        || str_starts_with($key, 'problem_books.')
        || str_starts_with($key, 'console.problems.')
        || str_starts_with($key, 'console.quizzes.')
        || str_starts_with($key, 'console.problem_books.')
));
foundationSame(
    [
        'console.problem_books.index',
        'console.problems.change_id',
        'console.problems.delete',
        'console.problems.edit',
        'console.problems.index',
        'console.problems.new',
        'console.problems.test_data',
        'console.problems.test_data_settings',
        'console.problems.topics',
        'console.problems.xml',
        'console.quizzes.edit',
        'console.quizzes.index',
        'console.quizzes.new',
        'console.quizzes.statistics',
        'problem_books.index',
        'problems.index',
        'problems.ranking',
        'problems.show',
        'problems.statistics',
        'quizzes.attempt',
        'quizzes.index',
        'quizzes.result',
        'quizzes.show',
        'quizzes.statistics',
    ],
    $problemRouteHandlers,
    'Problems 모듈 Route Handler 24개가 구현 계약과 다릅니다.'
);

$actionHandlers = $container->get(ActionHandlerRegistry::class);
foundationAssert($actionHandlers instanceof ActionHandlerRegistry, 'Action Handler Registry가 없습니다.');
$problemActionHandlers = array_values(array_filter(
    array_keys($actionHandlers->all()),
    static fn (string $key): bool =>
        str_starts_with($key, 'oj_problem_')
        || str_starts_with($key, 'oj_quiz_')
        || $key === 'oj_save_problem_test_data_settings'
));
foundationSame(
    [
        'oj_problem_ai_generate',
        'oj_problem_book_delete_category',
        'oj_problem_book_personal_add',
        'oj_problem_book_personal_remove',
        'oj_problem_book_save_category',
        'oj_problem_bulk_apply_metadata_draft',
        'oj_problem_bulk_visibility',
        'oj_problem_change_id',
        'oj_problem_delete',
        'oj_problem_generate_metadata',
        'oj_problem_generate_metadata_filtered',
        'oj_problem_save',
        'oj_problem_test_data_action',
        'oj_problem_toggle_visibility',
        'oj_problem_topic_create',
        'oj_problem_upload_image',
        'oj_problem_xml_export',
        'oj_problem_xml_import',
        'oj_quiz_bank_delete',
        'oj_quiz_bank_save',
        'oj_quiz_create',
        'oj_quiz_export_to_bank',
        'oj_quiz_import_bank_question',
        'oj_quiz_save',
        'oj_quiz_start_attempt',
        'oj_quiz_submit_attempt',
        'oj_save_problem_test_data_settings',
    ],
    $problemActionHandlers,
    'Problems 모듈 Action Handler 25개가 구현 계약과 다릅니다.'
);

$urlGenerator = $container->get(RouteUrlGenerator::class);
foundationAssert($urlGenerator instanceof RouteUrlGenerator, 'Route URL 생성기가 없습니다.');
foundationSame(
    '/problems/1000',
    $urlGenerator->path('problems.show', ['problem_id' => 1000]),
    '문제 상세 경로 생성이 올바르지 않습니다.'
);
$routeMatcher = $container->get(RouteMatcher::class);
foundationAssert($routeMatcher instanceof RouteMatcher, 'Route matcher가 없습니다.');
$routeMatch = $routeMatcher->match('GET', '/problems/1000?tab=discussion');
foundationAssert($routeMatch !== null, '문제 상세 경로를 찾지 못했습니다.');
foundationSame('problems.show', $routeMatch->route()->key(), '정적·동적 Route 우선순위가 올바르지 않습니다.');
foundationSame(['problem_id' => '1000'], $routeMatch->parameters(), 'Route 매개변수 추출이 올바르지 않습니다.');

$menuTree = $container->get(MenuTree::class);
foundationAssert($menuTree instanceof MenuTree, '관리 메뉴 트리가 없습니다.');
foundationSame(
    ['dashboard', 'classes', 'problems', 'submissions', 'learning_support', 'community', 'users', 'operations'],
    array_map(static fn ($root): string => $root->key(), $menuTree->roots()),
    'D-010: 관리자 1차 메뉴는 8개이며 교사 업무 순서, 관리자 영역(사용자·운영) 순이어야 합니다.'
);

$missingRoutes = new DefinitionRegistry(
    'missing-route-contract',
    RouteDefinition::class
);
$missingRoutes->register(RouteDefinition::fromArray([
    'name' => 'auth.missing',
    'owner_module' => 'auth',
    'feature_key' => 'auth',
    'path' => '/missing',
    'area' => 'public',
    'methods' => ['GET'],
    'authentication' => 'authenticated',
    'response_type' => 'html',
]));
$missingRouteRejected = false;
try {
    (new WebHandlerContractValidator(
        $missingRoutes,
        new DefinitionRegistry('empty-actions', ActionDefinition::class),
        new RouteHandlerRegistry(),
        new ActionHandlerRegistry(),
        ['auth']
    ))->validate();
} catch (KernelException) {
    $missingRouteRejected = true;
}
foundationAssert(
    $missingRouteRejected,
    '완료 모듈에 정의만 있는 Route를 부팅 전에 거부해야 합니다.'
);

$missingActions = new DefinitionRegistry(
    'missing-action-contract',
    ActionDefinition::class
);
$missingActions->register(ActionDefinition::fromArray([
    'key' => 'oj_missing_contract',
    'owner_module' => 'auth',
    'transport' => 'admin_post',
    'authentication' => 'authenticated',
]));
$missingActionRejected = false;
try {
    (new WebHandlerContractValidator(
        new DefinitionRegistry('empty-routes', RouteDefinition::class),
        $missingActions,
        new RouteHandlerRegistry(),
        new ActionHandlerRegistry(),
        ['auth']
    ))->validate();
} catch (KernelException) {
    $missingActionRejected = true;
}
foundationAssert(
    $missingActionRejected,
    '완료 모듈에 정의만 있는 Action을 부팅 전에 거부해야 합니다.'
);

$pagination = new PaginationView();
foundationSame(
    '',
    $pagination->links(1, 1, '/problems'),
    '페이지가 1개뿐이면 아무것도 렌더링하지 않아야 합니다.'
);

$fewPagesLinks = $pagination->links(3, 5, '/problems');
for ($page = 1; $page <= 5; $page++) {
    foundationAssert(
        str_contains($fewPagesLinks, '>' . $page . '</a>'),
        '페이지 수가 적으면 모든 페이지 번호가 표시되어야 합니다: ' . $page
    );
}
foundationAssert(
    str_contains($fewPagesLinks, 'is-active') && str_contains($fewPagesLinks, 'aria-current="page"'),
    '현재 페이지는 is-active와 aria-current로 표시되어야 합니다.'
);
foundationAssert(
    ! str_contains($fewPagesLinks, '…'),
    '페이지 수가 적으면 생략(…) 표시가 없어야 합니다.'
);

$manyPagesLinks = $pagination->links(10, 30, '/problems');
foundationAssert(
    str_contains($manyPagesLinks, '…'),
    '페이지 수가 많으면 현재 페이지 주변만 보여주고 생략(…) 표시가 있어야 합니다.'
);
foundationAssert(
    str_contains($manyPagesLinks, '>1</a>') && str_contains($manyPagesLinks, '>30</a>'),
    '페이지 수가 많아도 처음·마지막 페이지 번호는 항상 보여야 합니다.'
);
foundationAssert(
    ! str_contains($manyPagesLinks, '>20</a>'),
    '현재 페이지에서 먼 페이지 번호는 생략되어야 합니다.'
);

$queryLinks = $pagination->links(2, 3, '/problems', ['q' => '문제']);
foundationAssert(
    str_contains($queryLinks, rawurlencode('문제')) || str_contains($queryLinks, urlencode('문제')),
    '기존 검색어 등 쿼리 파라미터는 페이지 링크에도 유지되어야 합니다.'
);

fwrite(STDOUT, sprintf("R-10 운영 기반 단위 검사 통과: assertion %d개\n", $assertions));
