<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\DashboardBlockProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardItem;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserDirectory;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class PendingApprovalDashboardProvider implements DashboardBlockProvider
{
    public function __construct(
        private readonly UserDirectory $users,
        private readonly Authorization $permissions
    ) {
    }

    public function key(): string { return 'tasks.user_approvals'; }
    public function title(): string { return '가입 승인 대기'; }
    public function kind(): string { return 'tasks'; }
    public function priority(): int { return 10; }

    public function load(DashboardContext $context): ?DashboardBlock
    {
        if (! $this->permissions->can(
            $context->userId(),
            'oj_approve_user_registrations'
        )) {
            return null;
        }
        $count = count($this->users->page(AccessStatus::Pending, 100));

        return new DashboardBlock(
            $this->key(),
            $this->title(),
            '승인할 계정의 신청 정보와 동의 상태를 확인합니다.',
            $this->kind(),
            $count < 1 ? [] : [new DashboardItem(
                $this->title(),
                '필수 동의와 법정대리인 확인 상태를 승인 화면에서 다시 검사합니다.',
                RouteTarget::route('console.users.index'),
                $count,
                '확인',
                'warning'
            )]
        );
    }
}
