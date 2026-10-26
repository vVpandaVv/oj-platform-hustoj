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
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\PublicMenuEntry;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$operationsUiAssertions = 0;

function operationsUiAssert(bool $condition, string $message): void
{
    global $operationsUiAssertions;
    ++$operationsUiAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function operationsUiContains(
    string $needle,
    string $contents,
    string $message
): void {
    operationsUiAssert(str_contains($contents, $needle), $message);
}

function operationsUiThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        operationsUiAssert(true, $message);

        return;
    }

    throw new RuntimeException($message);
}

final class OperationsUiHooks implements HookRegistrar
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

final class OperationsUiErrors implements ErrorReporter
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
    new OperationsUiHooks(),
    new OperationsUiErrors()
))->build();
$container->get(Kernel::class)->boot();

$expectedRoutes = [
    'console.settings.index' => [
        '/oj-console/settings',
        'settings',
        'AdminConsole',
        ['oj_access_console'],
    ],
    'console.settings.features' => [
        '/oj-console/settings/features',
        'settings',
        'Settings',
        ['oj_manage_settings'],
    ],
    'console.settings.site' => [
        '/oj-console/settings/site',
        'settings',
        'Settings',
        ['oj_manage_settings'],
    ],
    'console.settings.menu' => [
        '/oj-console/settings/menu',
        'settings',
        'PublicUi',
        ['oj_manage_settings'],
    ],
    'console.settings.home' => [
        '/oj-console/settings/home',
        'settings',
        'PublicUi',
        ['oj_manage_settings'],
    ],
    'console.settings.database' => [
        '/oj-console/settings/database',
        'settings',
        'JudgeDb',
        ['oj_manage_settings'],
    ],
    'console.settings.smtp' => [
        '/oj-console/settings/smtp',
        'settings',
        'Settings',
        ['oj_manage_smtp'],
    ],
    'console.settings.ai_provider' => [
        '/oj-console/settings/ai-provider',
        'settings',
        'Settings',
        ['oj_manage_settings'],
    ],
    'console.settings.ai_routing' => [
        '/oj-console/settings/ai-routing',
        'settings',
        'Settings',
        ['oj_manage_settings'],
    ],
    'console.settings.ai_usage' => [
        '/oj-console/settings/ai-usage',
        'settings',
        'Settings',
        ['oj_manage_settings'],
    ],
    'console.settings.system' => [
        '/oj-console/settings/system',
        'settings',
        'Settings',
        ['oj_manage_system'],
    ],
    'console.system_status' => [
        '/oj-console/system-status',
        'settings',
        'AdminConsole',
        [
            'oj_view_system_status',
            'oj_manage_settings',
            'oj_manage_system',
        ],
    ],
    'console.settings.migration' => [
        '/oj-console/settings/migration',
        'migration',
        'Migration',
        ['oj_manage_migration'],
    ],
];

$routeHandlers = $container->get(RouteHandlerRegistry::class);
/** @var DefinitionRegistry<RouteDefinition> $routes */
$routes = $container->get(FoundationServiceIds::ROUTES);
$routeAccess = require $pluginRoot . '/config/route-access.php';

foreach ($expectedRoutes as $routeKey => [$path, $owner, $module, $capabilities]) {
    $handler = $routeHandlers->find($routeKey);
    operationsUiAssert(
        $handler !== null,
        'R-10 Route Handler가 없습니다: ' . $routeKey
    );
    operationsUiAssert(
        str_contains($handler::class, '\\Modules\\' . $module . '\\'),
        'R-10 화면 구현 모듈이 일치하지 않습니다: ' . $routeKey
    );

    $definition = $routes->find($routeKey);
    operationsUiAssert(
        $definition instanceof RouteDefinition,
        'R-10 Route 정의가 없습니다: ' . $routeKey
    );
    operationsUiAssert(
        $definition instanceof RouteDefinition && $definition->path() === $path,
        'R-10 Route 경로가 일치하지 않습니다: ' . $routeKey
    );
    operationsUiAssert(
        $definition instanceof RouteDefinition
            && $definition->owner()->value() === $owner,
        'R-10 Route Registry 소유가 일치하지 않습니다: ' . $routeKey
    );
    operationsUiAssert(
        ($routeAccess[$routeKey] ?? null) === $capabilities,
        'R-10 Route 접근 권한이 일치하지 않습니다: ' . $routeKey
    );
}

$expectedActions = [
    'oj_save_feature_flags' => ['settings', 'Settings'],
    'oj_save_site_settings' => ['settings', 'Settings'],
    'oj_save_public_menu_settings' => ['settings', 'PublicUi'],
    'oj_reset_public_menu_settings' => ['settings', 'PublicUi'],
    'oj_save_home_settings' => ['settings', 'PublicUi'],
    'oj_save_judge_db_settings' => ['judge_db', 'JudgeDb'],
    'oj_send_smtp_test_mail' => ['settings', 'Settings'],
    'oj_save_smtp_settings' => ['settings', 'Settings'],
    'oj_save_ai_provider_settings' => ['settings', 'Settings'],
    'oj_save_ai_task_routing' => ['settings', 'Settings'],
    'oj_save_system_settings' => ['settings', 'Settings'],
    'oj_migration_scan' => ['migration', 'Migration'],
    'oj_save_migration_settings' => ['migration', 'Migration'],
    'oj_migration_dry_run' => ['migration', 'Migration'],
    'oj_migration_run' => ['migration', 'Migration'],
    'oj_migration_validate' => ['migration', 'Migration'],
    'oj_migration_cleanup_report' => ['migration', 'Migration'],
    'oj_migration_deactivate_legacy_plugins' => ['migration', 'Migration'],
    'oj_platform_clear_last_fatal' => ['admin_console', 'AdminConsole'],
];

$actionHandlers = $container->get(ActionHandlerRegistry::class);
/** @var DefinitionRegistry<ActionDefinition> $actions */
$actions = $container->get(FoundationServiceIds::ACTIONS);

foreach ($expectedActions as $actionKey => [$owner, $module]) {
    $handler = $actionHandlers->find($actionKey);
    operationsUiAssert(
        $handler !== null,
        'R-10 Action Handler가 없습니다: ' . $actionKey
    );
    operationsUiAssert(
        str_contains($handler::class, '\\Modules\\' . $module . '\\'),
        'R-10 Action 구현 모듈이 일치하지 않습니다: ' . $actionKey
    );
    $definition = $actions->find($actionKey);
    operationsUiAssert(
        $definition instanceof ActionDefinition
            && $definition->owner()->value() === $owner,
        'R-10 Action Registry 소유가 일치하지 않습니다: ' . $actionKey
    );
}


$menuSettings = $container->get(PublicMenuSettingsService::class);
operationsUiThrows(
    static fn () => $menuSettings->save([
        new PublicMenuEntry(
            'login',
            '로그인',
            1,
            null,
            'auth.login',
            null,
            'all',
            10,
            'primary',
            true
        ),
    ], $menuSettings->snapshot()->revision()),
    '비로그인 Route를 사용자 메뉴 저장 요청으로 우회할 수 없어야 합니다.'
);
operationsUiThrows(
    static fn () => $menuSettings->save([
        new PublicMenuEntry(
            'problem',
            '문제 상세',
            1,
            null,
            'problems.show',
            null,
            'all',
            10,
            'primary',
            true
        ),
    ], $menuSettings->snapshot()->revision()),
    '현재 사용자로 채울 수 없는 매개변수 Route를 메뉴에 저장할 수 없어야 합니다.'
);

$aiPage = file_get_contents(
    $pluginRoot
        . '/modules/Settings/Presentation/Console/AiProviderSettingsPageHandler.php'
);
$aiCatalog = file_get_contents(
    $pluginRoot . '/modules/Settings/Application/AiProviderCatalog.php'
);
$menuEntry = file_get_contents(
    $pluginRoot . '/modules/PublicUi/Domain/PublicMenuEntry.php'
);
$menuService = file_get_contents(
    $pluginRoot . '/modules/PublicUi/Application/PublicMenuSettingsService.php'
);
$publicShell = file_get_contents(
    $pluginRoot . '/modules/PublicUi/Shell/PublicShell.php'
);
$systemPage = file_get_contents(
    $pluginRoot
        . '/modules/Settings/Presentation/Console/SystemSettingsPageHandler.php'
);
$migrationPage = file_get_contents(
    $pluginRoot
        . '/modules/Migration/Presentation/Console/MigrationPlanningPageHandler.php'
);
$judgeDatabasePage = file_get_contents(
    $pluginRoot
        . '/modules/JudgeDb/Presentation/Console/JudgeDatabaseSettingsPageHandler.php'
);
$setupScreen = file_get_contents(
    $pluginRoot . '/src/Kernel/Setup/WordPress/WordPressSetupScreen.php'
);
$javascript = file_get_contents(
    $pluginRoot . '/resources/ui/js/oj-ui.js'
);
$css = file_get_contents(
    $pluginRoot . '/resources/ui/css/oj-ui-system.css'
);

foreach ([
    $aiPage,
    $aiCatalog,
    $menuEntry,
    $menuService,
    $publicShell,
    $systemPage,
    $migrationPage,
    $judgeDatabasePage,
    $setupScreen,
    $javascript,
    $css,
] as $contents) {
    operationsUiAssert(
        is_string($contents),
        'R-10 화면 계약 파일을 읽을 수 없습니다.'
    );
}

operationsUiContains(
    "pending_extension_columns",
    (string) $judgeDatabasePage,
    'Judge DB 후보 검사는 생성 대기 확장 필드를 표시해야 합니다.'
);
operationsUiContains(
    '핵심 채점 테이블 8개, 제출 호환 테이블 1개',
    (string) $judgeDatabasePage,
    'Judge DB 검사 화면의 테이블 수가 Profile 계약과 다릅니다.'
);
operationsUiContains(
    'bann, front, body, rear, langmask',
    (string) $judgeDatabasePage,
    'Judge DB 화면에 설치 가능한 확장 필드 5개를 명시해야 합니다.'
);
operationsUiContains(
    "'/oj-console/settings/database'",
    (string) $setupScreen,
    '초기 설치 완료 후 Judge DB 설정 단계로 연결되어야 합니다.'
);
operationsUiContains(
    'Judge DB 연결',
    (string) $setupScreen,
    '초기 설치 화면에 Judge DB 연결 상태가 표시되어야 합니다.'
);

operationsUiContains(
    "queryString('profile_key')",
    (string) $aiPage,
    '등록한 AI 연결을 같은 화면에서 수정할 수 있어야 합니다.'
);
operationsUiContains(
    'data-ojp-provider-select',
    (string) $aiPage,
    'AI 제공자 선택 제어가 공통 스크립트와 연결되어야 합니다.'
);
operationsUiContains(
    'data-ojp-provider-example',
    (string) $aiPage,
    'AI 제공자별 즉시 예시 영역이 있어야 합니다.'
);
operationsUiContains(
    "'gemma4:latest'",
    (string) $aiCatalog,
    'Ollama 기본 모델 예시는 gemma4:latest여야 합니다.'
);
foreach ([
    "'gemini'",
    "'ollama'",
    "'mlx_lm'",
    "'vllm'",
    "'sglang'",
    "'openai_compatible_gateway'",
] as $providerKey) {
    operationsUiContains(
        $providerKey,
        (string) $aiCatalog,
        'AI 제공자 카탈로그 항목이 없습니다: ' . $providerKey
    );
}
operationsUiContains(
    'function initializeAiProviderForm',
    (string) $javascript,
    'AI 제공자 화면 초기화 함수가 공통 UI 스크립트에 있어야 합니다.'
);
operationsUiContains(
    "safeEach('[data-ojp-provider-select]'",
    (string) $javascript,
    'AI 제공자 화면 초기화 함수를 등록해야 합니다.'
);

operationsUiContains(
    'in_array($this->level, [1, 2], true)',
    (string) $menuEntry,
    '사용자 메뉴는 1차·2차만 허용해야 합니다.'
);
operationsUiContains(
    "!== 'https'",
    (string) $menuEntry,
    '외부 사용자 메뉴는 HTTPS만 허용해야 합니다.'
);
operationsUiContains(
    '한 1차 메뉴에 2차 메뉴는 최대 12개입니다.',
    (string) $menuService,
    '사용자 메뉴는 2차 메뉴 수량을 제한해야 합니다.'
);
operationsUiContains(
    'rel="noopener noreferrer"',
    (string) $publicShell,
    '외부 메뉴 링크는 opener와 referrer를 차단해야 합니다.'
);

operationsUiContains(
    '쓰기 동결은 Migration 승인 계획만 변경할 수 있습니다.',
    (string) $systemPage,
    '시스템 설정 화면은 Migration의 쓰기 동결 소유권을 안내해야 합니다.'
);
operationsUiAssert(
    ! str_contains((string) $systemPage, 'name="write_freeze'),
    '시스템 설정 화면에서 Migration 쓰기 동결을 직접 변경하면 안 됩니다.'
);
operationsUiContains(
    '사전 검사, 복구 증거, 계획 해시, Jobs 검증과 7일 관찰',
    (string) $migrationPage,
    'R-11 이전 화면은 승인형 실행 순서를 안내해야 합니다.'
);
operationsUiAssert(
    str_contains((string) $migrationPage, '<form'),
    'R-11 이전 계획 화면은 승인된 POST 폼을 제공해야 합니다.'
);
foreach ([
    'oj_migration_scan', 'oj_save_migration_settings', 'oj_migration_dry_run',
    'oj_migration_run', 'oj_migration_validate', 'oj_migration_cleanup_report',
    'oj_migration_deactivate_legacy_plugins',
] as $actionKey) {
    operationsUiContains(
        "'" . $actionKey . "'",
        (string) $migrationPage,
        'R-11 이전 화면에 명령이 연결되지 않았습니다: ' . $actionKey

    );
}
$requiredClasses = [
    '.ojp-two-column',
    '.ojp-settings-list',
    '.ojp-switch',
    '.ojp-provider-example',
    '.ojp-danger-details',
    '.ojp-step-list',
    '.ojp-status-grid',
    '.ojp-status-card',
    '.ojp-table--form',
];
foreach ($requiredClasses as $className) {
    operationsUiContains(
        $className,
        (string) $css,
        'R-10 공통 UI CSS 클래스가 없습니다: ' . $className
    );
}

$presentationRoots = [
    $pluginRoot . '/modules/AdminConsole/Presentation',
    $pluginRoot . '/modules/JudgeDb/Presentation',
    $pluginRoot . '/modules/Migration/Presentation',
    $pluginRoot . '/modules/PublicUi/Presentation',
    $pluginRoot . '/modules/Settings/Presentation',
];
foreach ($presentationRoots as $root) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        )
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        operationsUiAssert(
            is_string($contents)
                && preg_match('/\\\\u[0-9a-fA-F]{4}/', $contents) !== 1,
            '운영 화면 PHP에 유니코드 이스케이프 한글이 있습니다: '
                . $file->getPathname()
        );
        operationsUiAssert(
            is_string($contents)
                && preg_match('/style\\s*=\\s*["\']/', $contents) !== 1,
            '운영 화면에 인라인 style이 있습니다: ' . $file->getPathname()
        );
    }
}

fwrite(
    STDOUT,
    sprintf(
        "R-10 운영 화면 E2E 계약 검사 통과: Route %d개, Action %d개, assertion %d개\n",
        count($expectedRoutes),
        count($expectedActions),
        $operationsUiAssertions
    )
);
