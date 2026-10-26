<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard;

use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use RuntimeException;

final class DashboardPageLoader
{
    private const MANAGEMENT_CAPABILITIES = [
        'oj_manage_system',
        'oj_manage_settings',
        'oj_manage_users',
        'oj_manage_problems',
        'oj_manage_submissions',
        'oj_manage_classes',
        'oj_manage_contests',
        'oj_manage_groups',
        'oj_manage_board',
    ];

    public function __construct(
        private readonly CurrentAccountProvider $accounts,
        private readonly Authorization $permissions,
        private readonly DashboardBlockRegistry $blocks,
        private readonly Clock $clock
    ) {
    }

    public function load(): DashboardPageState
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            throw new RuntimeException('관리자 대시보드에 로그인 사용자 정보가 없습니다.');
        }
        $userId = $identity->userId();
        $canApprove = $this->permissions->can($userId, 'oj_approve_user_registrations');
        $management = array_values(array_filter(
            self::MANAGEMENT_CAPABILITIES,
            fn (string $capability): bool => $this->permissions->can($userId, $capability)
        ));
        $approvalOnly = $canApprove && $management === [];
        $roleLabel = $this->permissions->can($userId, 'oj_manage_system')
            ? '플랫폼 관리자'
            : ($approvalOnly ? '가입 승인 담당자' : '담당 운영자');
        $now = $this->clock->now();

        return new DashboardPageState(
            $identity,
            $now,
            $roleLabel,
            $approvalOnly,
            $this->blocks->load(new DashboardContext($identity, $now))
        );
    }
}
