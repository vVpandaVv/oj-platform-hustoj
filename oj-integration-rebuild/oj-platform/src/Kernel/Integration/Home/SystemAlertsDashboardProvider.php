<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\DashboardBlockProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusReader;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardItem;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class SystemAlertsDashboardProvider implements DashboardBlockProvider
{
    public function __construct(
        private readonly SystemStatusReader $status,
        private readonly Authorization $permissions
    ) {
    }

    public function key(): string { return 'operations.alerts'; }
    public function title(): string { return '운영 알림'; }
    public function kind(): string { return 'alerts'; }
    public function priority(): int { return 70; }

    public function load(DashboardContext $context): ?DashboardBlock
    {
        if (! $this->canView($context->userId())) {
            return null;
        }
        $items = [];
        foreach ($this->status->current() as $status) {
            if (! in_array($status->tone(), ['warning', 'danger'], true)) {
                continue;
            }
            $items[] = new DashboardItem(
                $status->label(),
                $status->summary(),
                RouteTarget::route('console.system_status'),
                null,
                $status->tone() === 'danger' ? '조치 필요' : '확인 필요',
                $status->tone(),
                $status->nextAction()
            );
            if (count($items) >= 4) {
                break;
            }
        }

        return new DashboardBlock(
            $this->key(),
            $this->title(),
            '권한이 있는 시스템 상태 요약입니다.',
            $this->kind(),
            $items
        );
    }

    private function canView(int $userId): bool
    {
        foreach (['oj_view_system_status', 'oj_manage_settings', 'oj_manage_system'] as $capability) {
            if ($this->permissions->can($userId, $capability)) {
                return true;
            }
        }

        return false;
    }
}
