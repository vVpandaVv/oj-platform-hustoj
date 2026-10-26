<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard\DashboardPageState;
use CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard\DashboardPageView;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardItem;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockItem;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomePageState;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomePageView;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;
use CodingPanda\OjPlatform\Ui\Icon\IconRenderer;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$routes = new class implements RouteUrlProvider {
    public function path(string $routeName, array $parameters = []): string
    {
        $path = '/' . str_replace('.', '/', $routeName);
        if ($parameters !== []) {
            $path .= '?' . http_build_query($parameters);
        }

        return $path;
    }
};
$assets = new class implements UiAssetLocator {
    public function stylesheetUrl(): string { return '/oj-ui.css'; }
    public function scriptUrl(): string { return '/oj-ui.js'; }
    public function mathJaxLoaderUrl(): string { return '/mathjax.js'; }
    public function iconSpriteUrl(): string { return '/lucide.svg'; }
    public function faviconUrl(): string { return '/favicon.svg'; }
};
$icons = new IconRenderer($assets);
$identity = new UserIdentity(7, 'learner7', 'learner7@example.test', '학습자');
$now = new DateTimeImmutable('2026-08-10 09:00:00');

$homeState = new HomePageState($identity, $now, [
    new HomeBlock('continue_learning', '이어서 하기', '', 'banner', [
        new HomeBlockItem(
            '문자열 기초',
            '최근 풀이를 이어갑니다.',
            RouteTarget::route('problems.detail', ['problem_id' => 1001]),
            '이어서 하기',
            'info',
            '',
            '계속하기'
        ),
    ]),
    new HomeBlock('learning_spaces', '내 학습 공간', '', 'cards', [
        new HomeBlockItem(
            '알고리즘 기초',
            '수업 문제와 학습 현황',
            RouteTarget::route('classes.show', ['class_id' => 3]),
            '수업',
            'info',
            '8월 28일 종료'
        ),
    ]),
]);
$homeHtml = (new HomePageView($routes, $icons))->render($homeState);
$assert(str_contains($homeHtml, '학습자님, 안녕하세요.'), '사용자 홈에 로그인 사용자 이름을 표시해야 합니다.');
$assert(str_contains($homeHtml, 'ojp-space-card__footer'), '학습 공간 카드에 상태와 이동 영역이 있어야 합니다.');
$assert(str_contains($homeHtml, '/lucide.svg#arrow-right'), '사용자 홈 아이콘은 공통 Sprite를 사용해야 합니다.');

$dashboardState = new DashboardPageState($identity, $now, '담당 운영자', false, [
    new DashboardBlock('tasks.approvals', '가입 승인 대기', '', 'tasks', [
        new DashboardItem(
            '가입 승인 대기',
            '신청 정보 확인',
            RouteTarget::route('console.users.index'),
            2
        ),
    ]),
    new DashboardBlock('jobs.recent', '최근 작업', '', 'jobs', [
        new DashboardItem(
            '문제 메타데이터 생성',
            '2026-08-10 08:40',
            RouteTarget::route('console.jobs.show', ['job_id' => 12]),
            null,
            '진행 중',
            'info',
            '4 성공 · 0 실패 · 10 전체',
            40
        ),
    ]),
    new DashboardBlock('operations.alerts', '운영 알림', '', 'alerts', [
        new DashboardItem(
            'Judge DB 연결',
            '연결 상태를 확인하세요.',
            RouteTarget::route('console.system_status'),
            null,
            '확인 필요',
            'warning'
        ),
    ]),
]);
$dashboardHtml = (new DashboardPageView($routes, $icons))->render($dashboardState);
$assert(! str_contains($dashboardHtml, '관리자 / 대시보드'), '대시보드는 셸의 공통 경로 안내를 중복하지 않아야 합니다.');
$assert(str_contains($dashboardHtml, 'ojp-dashboard-metrics'), '관리자 대시보드에 권한 범위 지표를 표시해야 합니다.');
$assert(str_contains($dashboardHtml, '<progress class="ojp-progress"'), '최근 작업 진행률은 접근 가능한 progress 요소로 표시해야 합니다.');
$assert(! str_contains($dashboardHtml, 'style='), '홈·대시보드 뷰에 인라인 스타일을 만들면 안 됩니다.');
$assert(str_contains($dashboardHtml, '담당 운영자'), '관리자 대시보드에 현재 역할 범위를 표시해야 합니다.');

$shortcut = new DashboardItem('문제 관리', '', RouteTarget::route('console.problems.index'));
$shortcutState = new DashboardPageState($identity, $now, '관리자', false, [
    new DashboardBlock('management.shortcuts', '', '', 'shortcuts', [$shortcut]),
    new DashboardBlock('learning.spaces', '', '', 'shortcuts', [$shortcut, $shortcut]),
]);
$shortcutHtml = (new DashboardPageView($routes, $icons))->render($shortcutState);
$assert(str_contains($shortcutHtml, '담당 바로가기</span><strong>3</strong>'), '바로가기 지표는 학습 공간과 관리 메뉴 전체를 세어야 합니다.');
$catalogClass = \CodingPanda\OjPlatform\Kernel\Integration\Home\LearningSpaceHomeCatalog::class;
$catalog = (new ReflectionClass($catalogClass))->newInstanceWithoutConstructor();
$spaceItems = [];
for ($i = 1; $i <= 25; ++$i) {
    $spaceItems[] = new \CodingPanda\OjPlatform\Kernel\Integration\Home\LearningSpaceSummary('수업', '공간-' . $i, '', '', 'info', RouteTarget::route('classes.show', ['class_id'=>$i]));
}
$cache = new ReflectionProperty($catalogClass, 'cache');
$cache->setValue($catalog, ['7:visible' => new \CodingPanda\OjPlatform\Kernel\Integration\Home\LearningSpaceSnapshot($spaceItems, false)]);
$provider = new \CodingPanda\OjPlatform\Kernel\Integration\Home\LearningSpacesHomeBlockProvider($catalog);
$summary = $provider->load(new \CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext($identity, 'ko_KR', $now), 4);
$assert(count($summary->items()) === 4 && $summary->allTarget()?->resolve($routes) === '/learning_spaces/index', '홈 요약 제한은 유지하고 전체 목록 링크를 제공해야 합니다.');
$accounts = new class($identity) implements \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider {
    public function __construct(public ?UserIdentity $identity) {}
    public function current(): \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount { return new \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount($this->identity, null); }
};
$pages = new class implements \CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder {
    public function guest(string $title, string $content, \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request, int $status = 200): \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse { return \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse::html($content, $status); }
    public function authenticated(string $title, string $content, \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request, int $status = 200): \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse { return $this->guest($title, $content, $request, $status); }
};
$clock = new class($now) implements \CodingPanda\OjPlatform\Shared\Clock\Clock { public function __construct(private DateTimeImmutable $time) {} public function now(): DateTimeImmutable { return $this->time; } };
$listHandler = new \CodingPanda\OjPlatform\Kernel\Integration\Home\LearningSpacesPageHandler($pages, $accounts, $catalog, new HomePageView($routes, $icons), $clock, new \CodingPanda\OjPlatform\Ui\Component\PaginationView());
$route = new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch(\CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::fromArray(['name'=>'learning_spaces.index','owner_module'=>'public_ui','feature_key'=>'public_ui','path'=>'/learning-spaces','area'=>'public','methods'=>['GET'],'authentication'=>'authenticated','response_type'=>'html']), []);
$first = $listHandler->handle($route, new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET', '/learning-spaces'))->body();
$second = $listHandler->handle($route, new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET', '/learning-spaces', ['page'=>'2']))->body();
$assert(substr_count($first, 'class="ojp-space-card"') === 20 && substr_count($second, 'class="ojp-space-card"') === 5, '전체 목록은 모든 공간을 20개씩 누락 없이 보여야 합니다.');
$assert(! str_contains($first, '<h3>공간-21</h3>') && str_contains($second, '<h3>공간-21</h3>'), '페이지 사이에 공간이 중복되면 안 됩니다.');
$accounts->identity = null;
$assert($listHandler->handle($route, new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET', '/learning-spaces'))->status() === 302, '비로그인 직접 요청은 목록을 노출하면 안 됩니다.');
$accounts->identity = $identity;
$cache->setValue($catalog, ['7:visible' => new \CodingPanda\OjPlatform\Kernel\Integration\Home\LearningSpaceSnapshot([], true)]);
$empty = $listHandler->handle($route, new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET', '/learning-spaces'))->body();
$assert(str_contains($empty, '일부 학습 공간') && str_contains($empty, '현재 표시할 학습 공간이 없습니다.'), '일부 조회 실패와 빈 목록은 명확히 구분해서 알려야 합니다.');

if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    if (! is_dir($argv[2])) {
        mkdir($argv[2], 0777, true);
    }
    $css = 'file:///' . str_replace('\\', '/', $pluginRoot) . '/resources/ui/css/oj-ui-system.css';
    foreach (['home' => $homeHtml, 'dashboard' => $dashboardHtml] as $name => $body) {
        file_put_contents($argv[2] . '/' . $name . '.html', '<!doctype html><html lang="ko"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES) . '"><body><main class="ojp-main">' . $body . '</main></body></html>');
    }
}

fwrite(
    STDOUT,
    sprintf("DS-09 홈·대시보드 뷰 검사 통과: assertion %d개\n", $assertions)
);
