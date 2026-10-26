<?php
declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Kernel\Setup\WordPress\ServerInstallScreen;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\DashboardBlockProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardItem;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class ServerPreparationDashboardProvider implements DashboardBlockProvider
{
    public function __construct(private readonly \CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityChecker $capabilities) {}
    public function key(): string { return 'operations.server_preparation'; }
    public function title(): string { return '서버 준비 필요'; }
    public function kind(): string { return 'alerts'; }
    public function priority(): int { return 65; }

    public function load(DashboardContext $context): ?DashboardBlock
    {
        if (! $this->capabilities->userCan($context->userId(), 'install_plugins')) {
            return null;
        }
        $items = [];
        foreach (ServerInstallScreen::pending() as $item) {
            $path = (string) parse_url($item['url'], PHP_URL_PATH);
            $query = (string) parse_url($item['url'], PHP_URL_QUERY);
            $items[] = new DashboardItem($item['title'], $item['reason'],
                RouteTarget::internalPath($path . ($query !== '' ? '?' . $query : '')),
                null, '설치 안내', 'warning', '설치 명령 실행 후 이 화면을 새로고침하세요.');
        }
        return $items === [] ? null : new DashboardBlock($this->key(), $this->title(),
            '준비되지 않은 필수 항목만 표시합니다.', $this->kind(), $items);
    }
}
