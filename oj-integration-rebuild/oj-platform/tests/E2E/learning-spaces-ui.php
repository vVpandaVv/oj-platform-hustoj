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

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$learningUiAssertions = 0;

function learningUiAssert(bool $condition, string $message): void
{
    global $learningUiAssertions;
    ++$learningUiAssertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class LearningUiHooks implements HookRegistrar
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

final class LearningUiErrors implements ErrorReporter
{
    public function report(Throwable $error): void
    {
        unset($error);
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
    new LearningUiHooks(),
    new LearningUiErrors()
))->build();
$container->get(Kernel::class)->boot();

$routeHandlers = $container->get(RouteHandlerRegistry::class);
$expectedRoutes = [
    'classes.index',
    'classes.show',
    'classes.submissions',
    'classes.progress',
    'contests.index',
    'contests.show',
    'contests.submissions',
    'contests.ranking',
    'contests.statistics',
    'contests.ip_check',
    'contests.ip_change',
    'groups.index',
    'groups.show',
    'groups.members',
    'groups.submissions',
    'groups.ranking',
    'console.learning.index',
    'console.classes.index',
    'console.classes.new',
    'console.classes.edit',
    'console.classes.participants',
    'console.classes.submissions',
    'console.classes.progress',
    'console.classes.delete',
    'console.classes.deleted',
    'console.classes.deleted_submissions',
    'console.classes.deleted_progress',
    'console.contests.index',
    'console.contests.deleted',
    'console.contests.new',
    'console.contests.edit',
    'console.contests.delete',
    'console.contests.submissions',
    'console.contests.ranking',
    'console.contests.statistics',
    'console.contests.ip_check',
    'console.contests.deleted_submissions',
    'console.groups.index',
    'console.groups.new',
    'console.groups.edit',
    'console.groups.members',
    'console.groups.approvals',
    'console.groups.managers',
    'console.groups.bulk_members',
    'console.groups.delete',
    'console.groups.submissions',
    'console.groups.ranking',
    'console.groups.deleted',
    'console.groups.deleted_submissions',
];
foreach ($expectedRoutes as $routeKey) {
    learningUiAssert(
        $routeHandlers->find($routeKey) !== null,
        'R-07 Route Handler가 없습니다: ' . $routeKey
    );
}

$actionHandlers = $container->get(ActionHandlerRegistry::class);
$expectedActions = [
    'oj_class_save',
    'oj_class_membership_command',
    'oj_class_lifecycle_command',
    'oj_class_export_progress',
    'oj_contest_save',
    'oj_contest_participation_command',
    'oj_contest_lifecycle_command',
    'oj_contest_allowed_ip',
    'oj_contest_admin_allowed_ip',
    'oj_contest_public_admin_allowed_ip',
    'oj_contest_export',
    'oj_group_save',
    'oj_group_join',
    'oj_group_cancel_request',
    'oj_group_leave',
    'oj_group_add_member',
    'oj_group_approve_membership',
    'oj_group_reject_membership',
    'oj_group_remove_membership',
    'oj_group_public_approve_membership',
    'oj_group_public_reject_membership',
    'oj_group_public_remove_membership',
    'oj_group_grant_manager',
    'oj_group_revoke_manager',
    'oj_group_archive',
    'oj_group_restore',
    'oj_group_bulk_members',
    'oj_group_reset_member_password',
    'oj_group_save_member_names',
];
foreach ($expectedActions as $actionKey) {
    learningUiAssert(
        $actionHandlers->find($actionKey) !== null,
        'R-07 Action Handler가 없습니다: ' . $actionKey
    );
}

/** @var DefinitionRegistry<RouteDefinition> $routes */
$routes = $container->get(FoundationServiceIds::ROUTES);
$expectedPublicPaths = [
    'classes.index' => '/classes',
    'classes.show' => '/classes/{class_id}',
    'classes.submissions' => '/classes/{class_id}/submissions',
    'classes.progress' => '/classes/{class_id}/progress',
    'contests.index' => '/contests',
    'contests.show' => '/contests/{contest_id}',
    'contests.submissions' => '/contests/{contest_id}/submissions',
    'contests.ranking' => '/contests/{contest_id}/ranking',
    'contests.statistics' => '/contests/{contest_id}/statistics',
    'contests.ip_check' => '/contests/{contest_id}/ip-check',
    'contests.ip_change' => '/contests/{contest_id}/ip-change',
    'groups.index' => '/groups',
    'groups.show' => '/groups/{group_id}',
    'groups.members' => '/groups/{group_id}/members',
    'groups.submissions' => '/groups/{group_id}/submissions',
    'groups.ranking' => '/groups/{group_id}/ranking',
];
foreach ($expectedPublicPaths as $routeKey => $expectedPath) {
    $route = $routes->find($routeKey);
    learningUiAssert(
        $route instanceof RouteDefinition && $route->path() === $expectedPath,
        'R-07 공개 주소가 동결 계약과 다릅니다: ' . $routeKey
    );
}

$moduleConfig = require $pluginRoot . '/config/modules.php';
$modules = [];
foreach ($moduleConfig as $module) {
    $modules[(string) $module['key']] = $module;
}
foreach (['classes', 'contests'] as $moduleKey) {
    learningUiAssert(
        ! in_array('groups', $modules[$moduleKey]['dependencies'], true),
        $moduleKey . '는 Groups OFF 상태에서도 독립적으로 켜져야 합니다.'
    );
}
learningUiAssert(
    array_keys(array_intersect_key(
        $modules,
        array_flip(['classes', 'contests', 'groups'])
    )) === ['classes', 'contests', 'groups'],
    '학습 공간 모듈 선언 순서는 수업, 대회, 그룹이어야 합니다.'
);

$schemaFiles = [
    'classes-schema.php',
    'contests-schema.php',
    'groups-schema.php',
];
$schemaNames = [];
$schemasByName = [];
foreach ($schemaFiles as $schemaFile) {
    foreach (require $pluginRoot . '/config/' . $schemaFile as $schema) {
        $name = (string) $schema['name'];
        $schemaNames[] = $name;
        $schemasByName[$name] = $schema;
        learningUiAssert(
            preg_match('/^(?:oj_|wp_)/', $name) !== 1,
            'R-07 테이블 이름에 접두사를 사용할 수 없습니다: ' . $name
        );
    }
}
learningUiAssert(
    $schemaNames === [
        'classes',
        'class_instructors',
        'class_enrollments',
        'class_group_links',
        'class_problem_links',
        'contest_settings',
        'contest_problem_settings',
        'contest_managers',
        'contest_participants',
        'contest_ip_logs',
        'deleted_contests',
        'learning_groups',
        'group_managers',
        'group_memberships',
    ],
    'R-07 소유 테이블 14개가 설계 순서와 일치해야 합니다.'
);
$contestColumns = array_column(
    $schemasByName['contest_settings']['columns'],
    'name'
);
foreach ([
    'ranking_visibility',
    'partial_score_policy',
    'scoring_policy',
    'ranking_policy',
] as $column) {
    learningUiAssert(
        in_array($column, $contestColumns, true),
        '대회 점수·공개 정책 열이 없습니다: ' . $column
    );
}
$groupColumns = array_column(
    $schemasByName['learning_groups']['columns'],
    'name'
);
learningUiAssert(
    in_array('evaluation_mode', $groupColumns, true),
    '그룹 일반·수행 모드 열이 없습니다.'
);

$sourceExpectations = [
    'modules/Classes/Presentation/ClassHtml.php' => [
        '첫 해결',
        '참여자',
        'JudgeResults',
    ],
    'modules/Classes/Presentation/Console/ClassManagementPageHandler.php' => [
        'instructor_user_logins',
        'groupChoices',
        'problem_visible[',
        'problem_available_from[',
        'problem_available_until[',
        'problem_remove[',
        '문제 구성',
    ],
    'modules/Classes/Presentation/Route/ClassCatalogPageHandler.php' => [
        'classProblemsForUser',
        '공개 예정',
        '공개 상태',
    ],
    'modules/Classes/Presentation/Console/ClassMemberPageHandler.php' => [
        'target_user_login',
        '로그인 아이디',
    ],
    'modules/Contests/Presentation/ContestHtml.php' => [
        '총점',
        '벌점',
        '풀이 시간',
        'JudgeResults',
        '수정',
        '현재 서버 시각',
        '시간대',
        '판정 기준',
        'data-ojp-server-clock',
        'data-ojp-server-clock-value',
        'data-contest-transition',
        '$this->clock->now()',
        'statisticsTable',
        'name="sort"',
        '정확성',
        '평균 시도',
        'ContestScoringPolicy::OI',
    ],
    'modules/Contests/Presentation/Route/ContestReportPageHandler.php' => ['statisticsTable', "queryString('sort'"],
    'modules/Contests/Presentation/Console/ContestReportPageHandler.php' => [
        'statisticsTable',
        'report_type',
        'rejudgeControls->render',
        '/submissions',
    ],
    'modules/Classes/Presentation/Console/ClassReportPageHandler.php' => [
        'rejudgeControls->render',
        'submissionTable($report, true, true)',
    ],
    'modules/Groups/Presentation/Console/GroupReportPageHandler.php' => [
        'rejudgeControls->render',
        '/submissions',
    ],
    'modules/Submissions/Presentation/SubmissionRejudgeControls.php' => [
        'oj_submission_rejudge',
        'return_path',
        'data-oj-submission-bulk',
        '선택 재채점',
    ],
    'modules/Contests/Presentation/Action/ExportContestActionHandler.php' => ['statisticsLines', 'problem_', 'report_type'],
    'modules/Contests/Presentation/ContestRequestFactory.php' => ['checkdate', '[ T]'],
    'modules/Contests/Presentation/Route/ContestCatalogPageHandler.php' => [
        '대회 시작 전',
        '준비 단계에서는 제출할 수 없습니다.',
        '자동으로 진행 중으로 전환됩니다.',
    ],
    'modules/Contests/Presentation/Console/ContestManagementPageHandler.php' => [
        'ICPC',
        'OI',
        'pass_rate',
        'manager_role_logins',
        'target_user_login',
        'groupChoices',
        '시작 시각이 되면 자동으로 진행 중으로 전환됩니다.',
        '지금 시작',
        'timeSummary',
    ],
    'src/Kernel/Composition/CompositionRoot.php' => ['$siteClock'],
    'modules/Contests/Presentation/Action/ContestLifecycleActionHandler.php' => [
        "'start'",
        'startNow',
        '대회 시작 완료',
    ],
    'modules/Groups/Presentation/Console/GroupMemberPageHandler.php' => [
        '일괄',
        '비밀번호 초기화',
        'target_user_login',
    ],
    'modules/Groups/Presentation/Route/GroupPageHandler.php' => [
        '수행 모드',
        '제출 상세',
    ],
    'modules/Groups/Presentation/GroupHtml.php' => [
        'JudgeResults',
    ],
    'resources/ui/css/oj-ui-system.css' => ['ojp-ranking-cell', 'ojp-ranking-toolbar', 'ojp-ranking-row.is-current'],
    'resources/ui/js/oj-ui.js' => [
        'initializeServerClock',
        'data-ojp-server-clock',
        'data-ojp-server-clock-value',
    ],
];
foreach ($sourceExpectations as $relativePath => $terms) {
    $source = (string) file_get_contents($pluginRoot . '/' . $relativePath);
    learningUiAssert(
        preg_match('//u', $source) === 1,
        'R-07 화면 코드는 UTF-8이어야 합니다: ' . $relativePath
    );
    learningUiAssert(
        preg_match('/\\\\u[0-9a-f]{4}/i', $source) !== 1,
        'R-07 한글 문구에 유니코드 이스케이프를 사용할 수 없습니다: '
            . $relativePath
    );
    learningUiAssert(
        ! str_contains($source, '학교'),
        '일반 학습용 채점 시스템 화면에 학교 전용 문구를 사용할 수 없습니다: '
            . $relativePath
    );
    foreach ($terms as $term) {
        learningUiAssert(
            str_contains($source, $term),
            sprintf('%s 화면 계약 문구가 없습니다: %s', $relativePath, $term)
        );
    }
    learningUiAssert(
        ! str_contains($source, '사용자 번호')
            && ! str_contains($source, '연결 그룹 번호'),
        'R-07 화면에서 내부 숫자 ID 입력 문구를 노출할 수 없습니다: '
            . $relativePath
    );
}
$compositionSource = (string) file_get_contents(
    $pluginRoot . '/src/Kernel/Composition/CompositionRoot.php'
);
$contestCompositionStart = strpos(
    $compositionSource,
    '(new ContestsComposition())->assemble('
);
$contestCompositionCall = $contestCompositionStart === false
    ? ''
    : substr($compositionSource, $contestCompositionStart, 700);
learningUiAssert(
    str_contains($contestCompositionCall, '$siteClock,')
        && ! preg_match('/(?<!site)\$clock,/', $contestCompositionCall),
    '대회 모듈은 UTC 시스템 시계가 아닌 WordPress 사이트 시계를 사용해야 합니다.'
);
$publicGroupHandler = (string) file_get_contents(
    $pluginRoot . '/modules/Groups/Presentation/Route/GroupPageHandler.php'
);
learningUiAssert(
    str_contains($publicGroupHandler, '$this->pages->authenticated('),
    '공개 그룹 화면은 로그인 사용자 응답 계약을 사용해야 합니다.'
);
learningUiAssert(
    ! str_contains($publicGroupHandler, '$this->pages->respond('),
    '공개 그룹 화면이 관리자 응답 계약을 호출하면 안 됩니다.'
);
foreach ([
    'modules/Classes/Presentation/ClassHtml.php',
    'modules/Contests/Presentation/ContestHtml.php',
    'modules/Groups/Presentation/GroupHtml.php',
] as $relativePath) {
    $source = (string) file_get_contents($pluginRoot . '/' . $relativePath);
    learningUiAssert(
        ! str_contains($source, 'return match ($code)'),
        '채점 결과 문구를 화면마다 중복 정의할 수 없습니다: ' . $relativePath
    );
    learningUiAssert(
        str_contains($source, 'name="submission_ids[]"')
            && str_contains($source, 'bool $selectable = false'),
        '담당자 제출 표는 사용자 화면과 분리된 선택 열을 제공해야 합니다: '
            . $relativePath
    );
}

fwrite(
    STDOUT,
    sprintf(
        "R-07 학습 공간 E2E 계약 검사 통과: Route %d개, Action %d개, assertion %d개\n",
        count($expectedRoutes),
        count($expectedActions),
        $learningUiAssertions
    )
);
