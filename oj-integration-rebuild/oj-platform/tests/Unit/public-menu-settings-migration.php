<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Addon\AddonPublicMenuContributions;
use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandlerCollection;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\PublicMenuEntry;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$assertions = 0;

function publicMenuMigrationAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class StalePublicMenuSettings implements SettingsManager
{
    /** @param array<string, mixed> $storedValue */
    public function __construct(private readonly array $storedValue)
    {
    }

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        unset($default);

        return new SettingsSnapshot(1, $key === 'public_menu' ? $this->storedValue : $default);
    }

    public function save(string $key, mixed $value, int $expectedRevision): SettingsSnapshot
    {
        unset($key);

        return new SettingsSnapshot($expectedRevision + 1, $value);
    }
}

final class PublicMenuMigrationUrls implements RouteUrlProvider
{
    public function path(string $routeName, array $parameters = []): string
    {
        unset($parameters);

        return '/' . str_replace('.', '/', $routeName);
    }
}

final class PublicMenuMigrationHandlers implements RouteHandlerCollection
{
    public function register(string $routeName, RouteHandler $handler): void
    {
        unset($routeName, $handler);
    }

    public function find(string $routeName): ?RouteHandler
    {
        unset($routeName);

        return new class implements RouteHandler {
            public function handle($route, $request)
            {
                throw new RuntimeException('Not called by this test.');
            }
        };
    }

    public function keys(): array
    {
        return [];
    }
}

final class PublicMenuMigrationFeatures implements FeatureFlags
{
    public function isEnabled(string $featureKey): bool
    {
        unset($featureKey);

        return true;
    }
}

final class PublicMenuMigrationAuthorization implements Authorization
{
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool
    {
        unset($userId, $capability, $context);

        return true;
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        unset($userId, $capabilities);

        return true;
    }

    public function isSuperAdmin(int $userId): bool
    {
        unset($userId);

        return true;
    }
}

/** @var DefinitionRegistry<RouteDefinition> $routes */
$routes = new DefinitionRegistry('public-menu-migration-routes', RouteDefinition::class);
foreach ([
    'home',
    'problems.index',
    'quizzes.index',
    'problem_books.index',
    'submissions.index',
    'classes.index',
    'groups.index',
    'contests.index',
    'board.index',
    'notices.index',
    'messaging.inbox',
    'messaging.find',
    'learning_dashboard.index',
    'research.index',
    'ranking.index',
    'auth.profile',
    'auth.settings',
    'auth.agreements',
    'addon.readcontents_library.library',
] as $routeKey) {
    $routes->register(RouteDefinition::fromArray([
        'name' => $routeKey,
        'owner_module' => 'public_ui',
        'feature_key' => 'public_ui',
        'path' => '/' . str_replace('.', '/', $routeKey),
        'area' => 'public',
        'methods' => ['GET'],
        'authentication' => 'authenticated',
        'response_type' => 'html',
    ]));
}
$routes->freeze();

// A snapshot saved before the "학습 공간" menu was split into 수업/대회·평가/학습지원:
// a 'learning' root with 'classes.index'/'contests.index'/'groups.index' children.
// After the split, the new defaults() has no more 'learning' root, and 'contests.index'
// is no longer a level-2 key — it became the *route* of the new 'contests' root instead.
// 읽기자료(readcontents-library)처럼, 애드온 스스로는 기본값이 꺼짐(enabled=false)인
// 메뉴 항목을 관리자가 "사용자 메뉴" 화면에서 직접 켜둔(enabled=true) 뒤 저장한 상태를
// 흉내낸다. 상위 메뉴는 개편 전 값(learning)을 그대로 들고 있다.
$readingMaterialsKey = 'addon.readcontents_library.menu';
$staleEntries = [
    new PublicMenuEntry('learning', '학습', 1, null, 'first_accessible_child', null, 'all', 40, 'primary', true),
    new PublicMenuEntry('classes.index', '수업', 2, 'learning', 'classes.index', null, 'all', 10, 'primary', true),
    new PublicMenuEntry('contests.index', '대회·평가', 2, 'learning', 'contests.index', null, 'all', 20, 'primary', true),
    new PublicMenuEntry('groups.index', '그룹', 2, 'learning', 'groups.index', null, 'all', 30, 'primary', true),
    new PublicMenuEntry(
        $readingMaterialsKey,
        '읽기자료',
        2,
        'learning',
        'addon.readcontents_library.library',
        null,
        'learner',
        40,
        'primary',
        true
    ),
];
$staleValue = ['items' => array_map(
    static fn (PublicMenuEntry $entry): array => $entry->toArray(),
    $staleEntries
)];

$addonMenus = new AddonPublicMenuContributions();
$addonMenus->add(
    new PublicMenuEntry(
        $readingMaterialsKey,
        '읽기자료',
        2,
        'learning_support',
        'addon.readcontents_library.library',
        null,
        'learner',
        40,
        'primary',
        false
    ),
    'addon_readcontents_library_view'
);

$service = new PublicMenuSettingsService(
    new StalePublicMenuSettings($staleValue),
    $routes,
    new PublicMenuMigrationHandlers(),
    new PublicMenuMigrationUrls(),
    new PublicMenuMigrationFeatures(),
    new PublicMenuMigrationAuthorization(),
    $addonMenus
);

$entries = null;
$thrown = null;
try {
    $entries = $service->entries();
} catch (Throwable $error) {
    $thrown = $error;
}
publicMenuMigrationAssert(
    $thrown === null,
    '메뉴 구조 개편 전 저장된 사용자 메뉴를 읽을 때 예외가 발생하면 안 됩니다: '
        . ($thrown?->getMessage() ?? '')
);

$byKey = [];
foreach ($entries ?? [] as $entry) {
    $byKey[$entry->key()] = $entry;
}
publicMenuMigrationAssert(
    isset($byKey['messaging.find']) && $byKey['messaging.find']->parentKey() === 'community',
    '기존 메뉴 설정에도 커뮤니티 사용자 찾기 항목을 보완해야 합니다.'
);
publicMenuMigrationAssert(
    ! isset($byKey['learning']),
    '더 이상 존재하지 않는 옛 1차 메뉴(learning)는 남아 있으면 안 됩니다.'
);
publicMenuMigrationAssert(
    isset($byKey['classes']) && $byKey['classes']->level() === 1,
    '새 1차 메뉴(classes)가 있어야 합니다.'
);
publicMenuMigrationAssert(
    ! isset($byKey['contests']),
    'D-010: 대회·평가는 더 이상 1차 메뉴가 아닙니다.'
);
publicMenuMigrationAssert(
    isset($byKey['contests.index']) && $byKey['contests.index']->parentKey() === 'classes',
    'D-010: 대회·평가는 수업 아래 2차 메뉴여야 합니다.'
);
publicMenuMigrationAssert(
    isset($byKey['classes.index']) && $byKey['classes.index']->label() === '내 수업',
    '옛 기본 이름(수업)으로 저장된 항목은 새 기본 이름(내 수업)을 따라야 합니다.'
);
publicMenuMigrationAssert(
    isset($byKey['classes.index']) && $byKey['classes.index']->parentKey() === 'classes',
    '옛 저장값의 잘못된 상위 메뉴(learning)를 새 상위 메뉴(classes)로 바로잡아야 합니다.'
);
publicMenuMigrationAssert(
    isset($byKey['groups.index']) && $byKey['groups.index']->parentKey() === 'classes',
    '그룹 메뉴도 새 상위 메뉴(classes)로 바로잡아야 합니다.'
);
publicMenuMigrationAssert(
    isset($byKey[$readingMaterialsKey])
        && $byKey[$readingMaterialsKey]->parentKey() === 'learning_support',
    '읽기자료 메뉴의 상위 메뉴도 학습 활동(learning_support)으로 바로잡아야 합니다.'
);
publicMenuMigrationAssert(
    isset($byKey[$readingMaterialsKey]) && $byKey[$readingMaterialsKey]->enabled() === true,
    '관리자가 이미 켜둔 읽기자료 메뉴는 상위 메뉴만 고치고, 사용 여부는 꺼진 기본값으로 되돌리면 안 됩니다.'
);
$routeUsage = [];
foreach ($entries ?? [] as $entry) {
    $routeKey = $entry->routeKey();
    if ($routeKey === null || $routeKey === 'first_accessible_child') {
        continue;
    }
    $routeUsage[$routeKey] = ($routeUsage[$routeKey] ?? 0) + 1;
}
foreach ($routeUsage as $routeKey => $count) {
    publicMenuMigrationAssert(
        $count === 1,
        'Route가 사용자 메뉴에 두 번 이상 들어가면 안 됩니다: ' . $routeKey
    );
}

fwrite(STDOUT, sprintf("R-10 사용자 메뉴 개편 이전 저장값 단위 검사 통과: assertion %d개\n", $assertions));
