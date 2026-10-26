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
use CodingPanda\OjPlatform\Modules\Blockly\Contract\BlocklyAssets;
use CodingPanda\OjPlatform\Modules\Submissions\Application\RuntimeErrorParser;
use CodingPanda\OjPlatform\Modules\Submissions\Application\JudgeExecutionParser;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionCodeEditorAssets;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$submissionUiAssertions = 0;

function submissionUiAssert(bool $condition, string $message): void
{
    global $submissionUiAssertions;
    ++$submissionUiAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class SubmissionUiHooks implements HookRegistrar
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

final class SubmissionUiErrors implements ErrorReporter
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
    new SubmissionUiHooks(),
    new SubmissionUiErrors()
))->build();
$container->get(Kernel::class)->boot();
$runtimeParser = $container->get(RuntimeErrorParser::class);
submissionUiAssert(
    $runtimeParser instanceof RuntimeErrorParser,
    '공통 실행 오류 파서가 제출 모듈에 연결되어야 합니다.'
);
$submissionHtml = $container->get(SubmissionHtml::class);
submissionUiAssert($container->get(JudgeExecutionParser::class) instanceof JudgeExecutionParser, 'HUSTOJ 실행 결과 파서를 공통 조립 경계에서 제공');
$caseRuntimeHtml = $submissionHtml->judgeReport(file_get_contents(dirname(__DIR__) . '/Fixtures/judge-runtime-error.txt'));
$caseWrongHtml = $submissionHtml->judgeReport(file_get_contents(dirname(__DIR__) . '/Fixtures/judge-wrong-answer.txt'));
submissionUiAssert(substr_count($caseRuntimeHtml, 'data-oj-case-error') === 7, '실제 제출 화면 렌더러에서 테스트별 RE 오류를 보존');
submissionUiAssert(substr_count($caseWrongHtml, 'data-oj-output-comparison') === 6, '실제 제출 화면 렌더러에서 WA 비교표를 제공');
$runtimeSample = "Traceback (most recent call last):\n"
    . "  File \"/srv/judge/Main.py\", line 3, in <module>\n"
    . "    print(1 / 0)\n"
    . "ZeroDivisionError: <script>alert(1)</script>";
$runtimeTable = $submissionHtml->judgeReport($runtimeSample);
submissionUiAssert(
    str_contains($runtimeTable, 'data-oj-runtime-diagnostics')
        && str_contains($runtimeTable, '<th>구분</th>')
        && str_contains($runtimeTable, '<th>오류 유형</th>')
        && str_contains($runtimeTable, '<th>위치</th>')
        && str_contains($runtimeTable, 'ZeroDivisionError')
        && str_contains($runtimeTable, 'Main.py:3'),
    '학생 실행 오류는 파싱된 공통 표로 표시되어야 합니다.'
);
submissionUiAssert(
    ! str_contains($runtimeTable, '/srv/judge/')
        && ! str_contains($runtimeTable, 'Traceback')
        && ! str_contains($runtimeTable, '<script>'),
    '학생 실행 오류 표에 서버 경로·원문·실행 가능한 HTML을 노출하면 안 됩니다.'
);
$adminRuntimeTable = $submissionHtml->judgeReport($runtimeSample, true);
submissionUiAssert(
    str_contains($adminRuntimeTable, '채점 기록 원문 확인')
        && str_contains($adminRuntimeTable, 'Traceback'),
    '관리자 실행 오류 표는 접힌 원문 진단을 함께 제공해야 합니다.'
);

$listSummary = new SubmissionSummary(
    new SubmissionId(701),
    1000,
    'student',
    '학습자',
    6,
    4,
    12,
    2048,
    '2026-08-10 10:00:00',
    '2026-08-10 10:00:01',
    0,
    -1,
    null,
    null,
    true
);
$display = [
    'show_time' => true,
    'show_memory' => true,
    'show_language' => true,
];
$fullList = $submissionHtml->table(
    [$listSummary],
    false,
    false,
    false,
    $display
);
submissionUiAssert(
    str_contains($fullList, 'href="/submissions/701"')
        && str_contains($fullList, '<th>언어</th>')
        && str_contains($fullList, '<th>시간</th>')
        && str_contains($fullList, '<th>메모리</th>'),
    '일반 모드 제출 목록은 상세 링크와 허용된 성능 정보를 표시해야 합니다.'
);
$resultOnlyList = $submissionHtml->table(
    [$listSummary],
    false,
    false,
    false,
    $display,
    false
);
submissionUiAssert(
    ! str_contains($resultOnlyList, 'href="/submissions/701"')
        && ! str_contains($resultOnlyList, '<th>언어</th>')
        && ! str_contains($resultOnlyList, '<th>시간</th>')
        && ! str_contains($resultOnlyList, '<th>메모리</th>')
        && str_contains($resultOnlyList, '문제 1000'),
    '수행 모드 제출 목록은 문제·제출 시각·채점 결과만 표시해야 합니다.'
);

$routeHandlers = $container->get(RouteHandlerRegistry::class);
$expectedRoutes = [
    'submissions.form',
    'submissions.form.problem',
    'submissions.index',
    'submissions.detail',
    'submissions.problem',
    'console.submissions.index',
    'console.submissions.detail',
    'console.submissions.deleted',
    'console.submissions.events',
    'console.submissions.settings',
    'console.submissions.block_coding',
];
foreach ($expectedRoutes as $routeKey) {
    submissionUiAssert(
        $routeHandlers->find($routeKey) !== null,
        'R-06 Route Handler가 없습니다: ' . $routeKey
    );
}

$actionHandlers = $container->get(ActionHandlerRegistry::class);
foreach ([
    'oj_submit_submission',
    'oj_submission_poll',
    'oj_submission_rejudge',
    'oj_submission_delete',
    'oj_submission_restore',
    'oj_status_rejudge',
    'oj_status_delete',
    'oj_save_submission_settings',
    'oj_save_submission_display_settings',
    'oj_save_blockly_settings',
] as $actionKey) {
    submissionUiAssert(
        $actionHandlers->find($actionKey) !== null,
        'R-06 Action Handler가 없습니다: ' . $actionKey
    );
}

/** @var DefinitionRegistry<RouteDefinition> $routes */
$routes = $container->get(FoundationServiceIds::ROUTES);
$publicPaths = [];
foreach ([
    'submissions.form',
    'submissions.form.problem',
    'submissions.index',
    'submissions.detail',
    'submissions.problem',
] as $routeKey) {
    $route = $routes->find($routeKey);
    submissionUiAssert(
        $route instanceof RouteDefinition,
        '제출 공개 Route 계약이 없습니다: ' . $routeKey
    );
    if ($route instanceof RouteDefinition) {
        $publicPaths[$routeKey] = $route->path();
    }
}
submissionUiAssert(
    $publicPaths === [
        'submissions.form' => '/submit',
        'submissions.form.problem' => '/submit/{problem_id}',
        'submissions.index' => '/submissions',
        'submissions.detail' => '/submissions/{submission_id}',
        'submissions.problem' => '/problems/{problem_id}/submissions',
    ],
    'R-06 공개 주소가 동결 계약과 다릅니다.'
);
foreach ($routes->all() as $route) {
    submissionUiAssert(
        ! str_starts_with($route->path(), '/status'),
        '레거시 /status 공개 경로를 만들면 안 됩니다.'
    );
}

$assets = $container->get(BlocklyAssets::class);
submissionUiAssert($assets->isReady(), 'Blockly 고정 자산이 준비되지 않았습니다.');
foreach ($assets->urls() as $url) {
    submissionUiAssert(
        ! preg_match('#^https?://#i', $url),
        'Blockly는 외부 CDN 주소를 사용하면 안 됩니다.'
    );
}
$aceAssets = $container->get(SubmissionCodeEditorAssets::class);
submissionUiAssert($aceAssets->isReady(), 'Ace 고정 자산과 공식 테마·언어 모드가 준비되어야 합니다.');
foreach ($aceAssets->urls() as $url) {
    submissionUiAssert(
        ! preg_match('#^https?://#i', $url),
        'Ace는 외부 CDN 주소를 사용하면 안 됩니다.'
    );
}
submissionUiAssert(
    str_ends_with($aceAssets->urls()['core'], '/ace.js'),
    'Ace 코어 자산 주소가 필요합니다.'
);

$css = (string) file_get_contents(
    $pluginRoot . '/resources/ui/css/oj-ui-system.css'
);
foreach ([
    '.ojp-submission-form',
    '.ojp-completion-editor',
    '.ojp-blockly-layout',
    '.ojp-blockly-workspace',
    '.ojp-code-editor--dark',
    '.ojp-ace-editor-shell',
    '.ojp-ace-editor-surface',
    '.ojp-result-summary',
] as $selector) {
    submissionUiAssert(
        str_contains($css, $selector),
        '공통 UI CSS에 R-06 선택자가 없습니다: ' . $selector
    );
}
submissionUiAssert(
    ! str_contains($css, '!important'),
    '공통 UI CSS에 !important를 사용할 수 없습니다.'
);
submissionUiAssert(
    str_contains($css, '--ojp-editor-font-size')
        && str_contains($css, 'var(--ojp-editor-font-size, 16px)'),
    '사용자 편집기 글자 크기는 공통 CSS 변수로 적용해야 합니다.'
);

$uiScript = (string) file_get_contents(
    $pluginRoot . '/resources/ui/js/oj-ui.js'
);
submissionUiAssert(
    str_contains($uiScript, 'loadFeatureScripts(urls)')
        && str_contains($uiScript, 'var intervals = [2000, 3000, 5000, 8000, 10000];'),
    'Blockly 지연 로딩과 제출 상세 폴링 간격 계약이 필요합니다.'
);
submissionUiAssert(
    str_contains($uiScript, "window.ace.config.set('basePath', assets.base)")
        && str_contains($uiScript, "source.dispatchEvent(new Event('input'")
        && str_contains($uiScript, "language.addEventListener('change', updateAceMode)"),
    'Ace는 로컬 자산, 원본 textarea, 선택 언어 모드와 동기화되어야 합니다.'
);
$blocklyScript = (string) file_get_contents(
    $pluginRoot . '/resources/features/blockly/js/editor.js'
);
submissionUiAssert(
    str_contains($blocklyScript, 'window.OJPlatformBlockly = Object.freeze')
        && ! str_contains($blocklyScript, 'https://')
        && ! str_contains($blocklyScript, 'http://'),
    '공통 Blockly 편집기는 단일 로컬 모듈이어야 합니다.'
);

$submissionFormSource = (string) file_get_contents(
    $pluginRoot . '/modules/Submissions/Presentation/Route/SubmissionFormPageHandler.php'
) . (string) file_get_contents(
    $pluginRoot . '/modules/Submissions/Application/SubmissionFormRenderer.php'
);
submissionUiAssert(
    str_contains($submissionFormSource, '$this->preferences->forUser')
        && str_contains($submissionFormSource, 'UserSubmissionPreferenceProvider')
        && str_contains($submissionFormSource, 'UserSubmissionPreferences')
        && ! str_contains($submissionFormSource, 'Auth\\Domain\\UserPreferences')
        && str_contains($submissionFormSource, 'defaultLanguage()')
        && str_contains($submissionFormSource, 'defaultEditor()')
        && str_contains($submissionFormSource, 'editorTheme()')
        && str_contains($submissionFormSource, 'editorFontSize()')
        && str_contains($submissionFormSource, 'codeTemplate()')
        && str_contains($submissionFormSource, 'data-oj-ace-editor')
        && str_contains($submissionFormSource, 'data-oj-ace-mode'),
    '저장한 사용자 제출 환경을 실제 제출 폼 초기값에 연결해야 합니다.'
);

fwrite(
    STDOUT,
    sprintf(
        "R-06 제출 화면 E2E 계약 검사 통과: assertion %d개\n",
        $submissionUiAssertions
    )
);
