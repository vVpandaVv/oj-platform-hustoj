<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\DashboardBlockProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard\DashboardBlockRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeBlockProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomeBlockRegistry;

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

$errors = new class implements ErrorReporter {
    /** @var list<Throwable> */
    public array $reported = [];

    public function report(Throwable $error): void
    {
        $this->reported[] = $error;
    }
};
$identity = new UserIdentity(7, 'learner7', 'learner7@example.test', '학습자');
$now = new DateTimeImmutable('2026-08-10 09:00:00');

$homeContext = new HomeBlockContext($identity, 'ko_KR', $now);
$home = new HomeBlockRegistry($errors);
$limitedProvider = new class implements HomeBlockProvider {
    public int $receivedLimit = 0;

    public function key(): string { return 'learning_spaces'; }
    public function title(): string { return '내 학습 공간'; }

    public function load(HomeBlockContext $context, int $limit): HomeBlock
    {
        unset($context);
        $this->receivedLimit = $limit;

        return new HomeBlock($this->key(), $this->title(), '', 'cards', []);
    }
};
$home->register($limitedProvider);
$home->register(new class implements HomeBlockProvider {
    public function key(): string { return 'recent_submissions'; }
    public function title(): string { return '최근 제출'; }

    public function load(HomeBlockContext $context, int $limit): ?HomeBlock
    {
        unset($context, $limit);
        throw new RuntimeException('최근 제출 조회 실패');
    }
});

$spaces = $home->load('learning_spaces', $homeContext, 99);
$failedHome = $home->load('recent_submissions', $homeContext, 5);
$assert($spaces instanceof HomeBlock && ! $spaces->failed(), '정상 홈 블록을 불러오지 못했습니다.');
$assert($limitedProvider->receivedLimit === 6, '홈 블록 표시 수를 최대 6개로 제한해야 합니다.');
$assert($failedHome instanceof HomeBlock && $failedHome->failed(), '실패한 홈 블록을 격리해야 합니다.');
$assert(count($errors->reported) === 1, '홈 블록 실패를 오류 보고기에 한 번 전달해야 합니다.');

$duplicateRejected = false;
try {
    $home->register($limitedProvider);
} catch (InvalidArgumentException) {
    $duplicateRejected = true;
}
$assert($duplicateRejected, '홈 블록 키 중복 등록을 거부해야 합니다.');

$dashboardContext = new DashboardContext($identity, $now);
$dashboard = new DashboardBlockRegistry($errors);
$dashboard->register(new class implements DashboardBlockProvider {
    public function key(): string { return 'jobs.recent'; }
    public function title(): string { return '최근 작업'; }
    public function kind(): string { return 'jobs'; }
    public function priority(): int { return 20; }

    public function load(DashboardContext $context): DashboardBlock
    {
        unset($context);
        throw new RuntimeException('작업 조회 실패');
    }
});
$dashboard->register(new class implements DashboardBlockProvider {
    public function key(): string { return 'tasks.approvals'; }
    public function title(): string { return '가입 승인 대기'; }
    public function kind(): string { return 'tasks'; }
    public function priority(): int { return 10; }

    public function load(DashboardContext $context): DashboardBlock
    {
        unset($context);

        return new DashboardBlock($this->key(), $this->title(), '', $this->kind(), []);
    }
});

$blocks = $dashboard->load($dashboardContext);
$assert(count($blocks) === 2, '한 대시보드 공급자 실패가 다른 블록을 제거하면 안 됩니다.');
$assert($blocks[0]->key() === 'tasks.approvals', '대시보드 블록은 우선순위 순으로 정렬해야 합니다.');
$assert($blocks[1]->key() === 'jobs.recent' && $blocks[1]->failed(), '실패한 대시보드 블록을 격리해야 합니다.');
$assert(count($errors->reported) === 2, '대시보드 실패도 오류 보고기에 전달해야 합니다.');

fwrite(
    STDOUT,
    sprintf("DS-09 홈·대시보드 단위 검사 통과: assertion %d개\n", $assertions)
);
