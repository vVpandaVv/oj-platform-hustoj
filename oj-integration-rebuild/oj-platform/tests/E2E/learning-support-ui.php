<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Composition\FoundationServiceIds;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\ActionHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Navigation\PublicMenuResolver;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$learningSupportUiAssertions = 0;

function learningSupportUiAssert(bool $condition, string $message): void
{
    global $learningSupportUiAssertions;
    ++$learningSupportUiAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class LearningSupportUiHooks implements HookRegistrar
{
    public function addAction(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $acceptedArguments = 0
    ): void {
        unset($hook, $callback, $priority, $acceptedArguments);
    }
}

final class LearningSupportUiErrors implements ErrorReporter
{
    public function report(Throwable $error): void
    {
        unset($error);
    }
}

final class LearningSupportUiUrls implements RouteUrlProvider
{
    public function path(string $routeName, array $parameters = []): string
    {
        unset($parameters);

        return '/' . str_replace('.', '/', $routeName);
    }
}

final class LearningSupportUiSettings implements SettingsManager
{
    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        unset($key);
        return new SettingsSnapshot(1, $default);
    }

    public function save(
        string $key,
        mixed $value,
        int $expectedRevision
    ): SettingsSnapshot {
        unset($key);
        return new SettingsSnapshot($expectedRevision + 1, $value);
    }
}

final class LearningSupportUiFeatures implements FeatureFlags
{
    /**
     * @param list<string> $disabled
     */
    public function __construct(private readonly array $disabled)
    {
    }

    public function isEnabled(string $featureKey): bool
    {
        return ! in_array($featureKey, $this->disabled, true);
    }
}

final class LearningSupportUiAuthorization implements Authorization
{
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool
    {
        unset($userId, $capability, $context);

        return false;
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        unset($userId, $capabilities);

        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        unset($userId);

        return false;
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
    new LearningSupportUiHooks(),
    new LearningSupportUiErrors()
))->build();
$container->get(Kernel::class)->boot();

$routeHandlers = $container->get(RouteHandlerRegistry::class);
$expectedRoutes = [
    'learning_dashboard.index',
    'ranking.index',
    'console.submissions.ai_feedback',
    'console.submissions.ai_guides',
    'console.submissions.ai_guides.new',
    'console.submissions.ai_guides.edit',
    'console.submissions.ai_guides.preview',
    'console.submissions.ai_guides.revisions',
    'console.submissions.learner_levels',
];
foreach ($expectedRoutes as $routeKey) {
    learningSupportUiAssert(
        $routeHandlers->find($routeKey) !== null,
        'R-08 Route Handler가 없습니다: ' . $routeKey
    );
}

$actionHandlers = $container->get(ActionHandlerRegistry::class);
$expectedActions = [
    'oj_ai_feedback_generate',
    'oj_save_ai_feedback_settings',
    'oj_ai_persona_save',
    'oj_ai_persona_duplicate',
    'oj_ai_persona_delete',
    'oj_ai_persona_restore',
];
foreach ($expectedActions as $actionKey) {
    learningSupportUiAssert(
        $actionHandlers->find($actionKey) !== null,
        'R-08 Action Handler가 없습니다: ' . $actionKey
    );
}

/** @var DefinitionRegistry<RouteDefinition> $routes */
$routes = $container->get(FoundationServiceIds::ROUTES);
$expectedPaths = [
    'learning_dashboard.index' => '/dashboard',
    'ranking.index' => '/ranking',
    'console.submissions.ai_feedback' =>
        '/oj-console/submissions/ai-feedback',
    'console.submissions.ai_guides' =>
        '/oj-console/submissions/ai-guides',
    'console.submissions.ai_guides.new' =>
        '/oj-console/submissions/ai-guides/new',
    'console.submissions.ai_guides.edit' =>
        '/oj-console/submissions/ai-guides/{prompt_file}',
    'console.submissions.ai_guides.preview' =>
        '/oj-console/submissions/ai-guides/{prompt_file}/preview',
    'console.submissions.ai_guides.revisions' =>
        '/oj-console/submissions/ai-guides/{prompt_file}/revisions',
    'console.submissions.learner_levels' =>
        '/oj-console/submissions/learner-levels',
];
foreach ($expectedPaths as $routeKey => $expectedPath) {
    $route = $routes->find($routeKey);
    learningSupportUiAssert(
        $route instanceof RouteDefinition && $route->path() === $expectedPath,
        'R-08 주소가 동결 계약과 다릅니다: ' . $routeKey
    );
}

$routeAccess = require $pluginRoot . '/config/route-access.php';
foreach (array_slice(array_keys($expectedPaths), 2) as $routeKey) {
    learningSupportUiAssert(
        ($routeAccess[$routeKey] ?? null) === ['oj_manage_ai_feedback'],
        'R-08 관리자 화면 권한이 동결 계약과 다릅니다: ' . $routeKey
    );
}

$modules = [];
foreach (require $pluginRoot . '/config/modules.php' as $module) {
    $modules[(string) $module['key']] = $module;
}
learningSupportUiAssert(
    ! in_array(
        'ai_feedback',
        $modules['learning_dashboard']['dependencies'],
        true
    ),
    '내 학습은 AI 피드백과 독립적으로 켤 수 있어야 합니다.'
);
learningSupportUiAssert(
    ! in_array(
        'learning_dashboard',
        $modules['ranking']['dependencies'],
        true
    ),
    '전체 랭킹은 내 학습과 독립적으로 켤 수 있어야 합니다.'
);

$schemas = require $pluginRoot . '/config/ai-feedback-schema.php';
learningSupportUiAssert(
    array_column($schemas, 'name') === [
        'submission_ai_feedback',
        'learner_level_profiles',
    ],
    'R-08는 AI 피드백 소유 테이블 2개만 추가해야 합니다.'
);
foreach ($schemas as $schema) {
    learningSupportUiAssert(
        preg_match('/^(?:oj_|wp_)/', (string) $schema['name']) !== 1,
        'R-08 테이블 이름에 접두사를 사용할 수 없습니다.'
    );
}
$feedbackColumns = array_column($schemas[0]['columns'], 'name');
foreach ([
    'input_hash',
    'source_hash',
    'problem_hash',
    'guide_revision',
    'level_policy_version',
    'profile_last_submission_id',
    'assignment_revision',
    'profile_fingerprint',
] as $column) {
    learningSupportUiAssert(
        in_array($column, $feedbackColumns, true),
        'AI 피드백 재사용·감사 열이 없습니다: ' . $column
    );
}
$profileColumns = array_column($schemas[1]['columns'], 'name');
foreach ([
    'analysis_status',
    'proven_tier',
    'proven_difficulty',
    'breadth',
    'recent_challenge',
    'stability',
    'skill_score',
    'difficulty_coverage',
    'topic_coverage',
    'policy_version',
] as $column) {
    learningSupportUiAssert(
        in_array($column, $profileColumns, true),
        '학습 수준 분석 열이 없습니다: ' . $column
    );
}

$menuResolver = new PublicMenuResolver(
    new PublicMenuSettingsService(
        new LearningSupportUiSettings(),
        $routes,
        $routeHandlers,
        new LearningSupportUiUrls(),
        new LearningSupportUiFeatures([
            'learning_dashboard',
            'ranking',
        ]),
        new LearningSupportUiAuthorization()
    )
);
$menuKeys = array_map(
    static fn ($item): string => $item->key(),
    $menuResolver->resolve(new UserIdentity(
        1,
        'learner',
        'learner@example.com',
        '학습자'
    ))
);
learningSupportUiAssert(
    ! in_array('learning_dashboard.index', $menuKeys, true)
        && ! in_array('ranking.index', $menuKeys, true)
        && in_array('problems', $menuKeys, true),
    '기능을 끈 내 학습·전체 랭킹만 사용자 메뉴에서 숨겨야 합니다.'
);

$enabledMenu = (new PublicMenuResolver(
    new PublicMenuSettingsService(
        new LearningSupportUiSettings(),
        $routes,
        $routeHandlers,
        new LearningSupportUiUrls(),
        new LearningSupportUiFeatures([]),
        new LearningSupportUiAuthorization()
    )
))->resolve(new UserIdentity(
    1,
    'learner',
    'learner@example.com',
    '학습자'
));
$topLevelKeys = array_map(
    static fn ($item): string => $item->key(),
    $enabledMenu
);
$account = array_values(array_filter(
    $enabledMenu,
    static fn ($item): bool => $item->key() === 'account'
))[0] ?? null;
$accountChildren = $account === null
    ? []
    : array_map(
        static fn ($item): string => $item->key(),
        $account->children()
    );
$accountLabels = $account === null
    ? []
    : array_map(
        static fn ($item): string => $item->label(),
        $account->children()
    );
learningSupportUiAssert(
    ! in_array('learning_dashboard.index', $topLevelKeys, true)
        && ! in_array('ranking.index', $topLevelKeys, true)
        && array_slice($accountChildren, 0, 2) === [
            'learning_dashboard.index',
            'ranking.index',
        ],
    '내 학습과 전체 랭킹은 내 정보의 2차 메뉴여야 합니다.'
);
learningSupportUiAssert(
    array_slice($accountLabels, 0, 2) === ['내 학습', '전체 랭킹'],
    '내 정보의 학습 메뉴 용어가 확정 설계와 다릅니다.'
);

$sourceExpectations = [
    'modules/AI/Application/LearnerLevelCalculator.php' => [
        'new LearnerLevelProfile(',
        'effectiveDifficulty() < $tier',
        'submission->isProcessing()',
    ],
    'modules/AI/Application/SubmissionFeedbackJobService.php' => [
        'generation_context_hash',
        'assertCanAccess',
        'restart_after_job_id',
        'findCurrentForActor',
        'isCurrent',
        "isEnabled('ai_feedback')",
    ],
    'modules/AI/Application/FeedbackOutputValidator.php' => [
        'containsCopyReadyCode',
        '복사 가능한 정답 코드나 수정 패치',
    ],
    'modules/AI/Application/SubmissionAiFeedbackJobHandler.php' => [
        'ai_feedback.reused',
        'feedbackJobs->profileFor',
        'assertCanGenerate',
    ],
    'modules/AI/Infrastructure/Filesystem/PrivateFeedbackGuideRepository.php' => [
        'foundation-learning',
        'advanced-review',
        '기본 지침은 복제한 뒤 수정할 수 있습니다.',
    ],
    'modules/AI/Presentation/Action/AiFeedbackActionHandler.php' => [
        "'cancel'",
        "'retry'",
        'assertCanAccess',
        'canControlJob',
        "status() !== 'cancelled'",
    ],
    'modules/AI/Presentation/SubmissionFeedbackExtension.php' => [
        '이전 피드백을 숨겼습니다.',
        'isCurrent',
    ],
    'modules/AI/Presentation/Action/FeedbackGuideOperationActionHandler.php' => [
        'usesGuide',
        '대체 지침을 먼저 선택',
    ],
    'modules/AI/Presentation/Console/LearnerLevelPageHandler.php' => [
        'findMany',
        'recent(100000)',
        'PAGE_SIZE = 100',
        'ojp-pagination',
        '난이도 확정률',
        '주제 연결률',
        'name="policy"',
    ],
    'modules/LearningDashboard/Presentation/LearningDashboardPageHandler.php' => [
        '최근 30일 해결',
        '오답 뒤 해결',
        'levelAvailable',
    ],
    'modules/LearningDashboard/Application/LearningDashboardService.php' => [
        '$day * 4',
        'RECOMMENDATION_POLICY_VERSION',
        'catalogForUser(',
        '100000',
        'findManyForUser',
        '최근 미해결 문제 다시 도전',
        'targetDifficulty',
        'user->userId()',
        'submission->isProcessing()',
        'historyEligibility',
    ],
    'modules/Submissions/Application/SubmissionProjectionService.php' => [
        'result->isProcessing()',
    ],
    'modules/Auth/Infrastructure/WordPress/WordPressUserPreferenceRepository.php' => [
        'UserPreferenceBatchProvider',
        'findMany',
        'array_chunk($userIds, 500)',
    ],
    'modules/Auth/Infrastructure/WordPress/WordPressUserApiGateway.php' => [
        'array_chunk($userIds, 500)',
        'orderby',
    ],
    'modules/Problems/Application/ProblemLearningProvider.php' => [
        'findManyForUser',
        'problems->findMany',
        'array_chunk($problems, 500)',
    ],
    'modules/Ranking/Application/RankingService.php' => [
        'UserPreferenceBatchProvider',
        'preferences->findMany',
        'acceptedSubmissionCount',
        "activity:v6",
        '$previousSolved',
    ],
    'modules/Ranking/Infrastructure/Judge/MysqliJudgeUserActivityProvider.php' => [
        "`s`.`valid` = 1",
        "`s`.`contest_id` = 0",
        "`p`.`defunct` = 'N'",
        "`s`.`result` BETWEEN",
        'accepted_submission_count',
        'solved_problem_count',
    ],
    'modules/Ranking/Infrastructure/WordPress/WordPressRankingSnapshotCache.php' => [
        'get_transient',
        'set_transient',
        'oj_platform_ranking_',
    ],    'modules/Auth/Presentation/Action/SaveUserSettingsActionHandler.php' => [
        "'ranking-visibility'",
        'updateRankingVisibility',
        "'/ranking'",
    ],
    'modules/Ranking/Presentation/RankingPageHandler.php' => [
        '공개된 상시 문제의 유효한 채점 완료 제출',
        '맞은 제출 ÷ 채점 완료 제출',
        '(정답/제출)',
        '내 현재 랭킹',
        'name="mode" value="ranking-visibility"',
        "hiddenFields('oj_save_user_settings')",
        "'auth.profile'",
    ],
    'src/Kernel/Integration/Learning/ContestLearningHistoryEligibility.php' => [
        "currentStage(\$contest) === 'ended'",
        "isEnabled('contests')",
    ],
    'src/Kernel/Infrastructure/WordPress/WordPressClock.php' => [
        'current_datetime',
        'wp_timezone',
    ],
    'resources/ui/js/oj-ui.js' => [
        'data-oj-ai-feedback-cancel',
        "operation.value = 'cancel'",
        "operation.value === 'retry'",
        'replaceChildren',
    ],
];
foreach ($sourceExpectations as $relativePath => $terms) {
    $source = (string) file_get_contents($pluginRoot . '/' . $relativePath);
    learningSupportUiAssert(
        preg_match('//u', $source) === 1,
        'R-08 코드는 UTF-8이어야 합니다: ' . $relativePath
    );
    learningSupportUiAssert(
        preg_match('/\\\\u[0-9a-f]{4}/i', $source) !== 1,
        'R-08 한글 문구에 유니코드 이스케이프를 사용할 수 없습니다: '
            . $relativePath
    );
    learningSupportUiAssert(
        ! str_contains($source, '학교')
            && ! str_contains($source, '학생 수준'),
        'R-08 화면은 일반 학습용 문구를 사용해야 합니다: ' . $relativePath
    );
    foreach ($terms as $term) {
        learningSupportUiAssert(
            str_contains($source, $term),
            sprintf('%s 계약 근거가 없습니다: %s', $relativePath, $term)
        );
    }
}

fwrite(
    STDOUT,
    sprintf(
        "R-08 학습 지원 E2E 계약 검사 통과: Route %d개, Action %d개, assertion %d개\n",
        count($expectedRoutes),
        count($expectedActions),
        $learningSupportUiAssertions
    )
);
