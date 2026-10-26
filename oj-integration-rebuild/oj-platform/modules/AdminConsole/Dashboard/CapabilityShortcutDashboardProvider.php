<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\DashboardBlockProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardItem;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class CapabilityShortcutDashboardProvider implements DashboardBlockProvider
{
    public function __construct(private readonly Authorization $permissions)
    {
    }

    public function key(): string { return 'shortcuts.capabilities'; }
    public function title(): string { return '관리 기능'; }
    public function kind(): string { return 'shortcuts'; }
    public function priority(): int { return 80; }

    public function load(DashboardContext $context): ?DashboardBlock
    {
        $definitions = [
            ['oj_manage_users', 'console.users.index', '사용자', '승인·계정 상태·일괄 등록'],
            ['oj_approve_user_registrations', 'console.users.index', '가입 승인', '승인 대기 계정 확인'],
            ['oj_manage_problems', 'console.problems.index', '문제', '문제·퀴즈·문제집'],
            ['oj_manage_submissions', 'console.submissions.index', '제출', '채점 현황·재채점·무효 처리'],
            ['oj_manage_classes', 'console.classes.index', '수업', '수업과 학습 현황'],
            ['oj_manage_contests', 'console.contests.index', '대회·평가', '일정·참가·순위'],
            ['oj_manage_groups', 'console.groups.index', '그룹', '구성원과 수행 모드'],
            ['oj_manage_board', 'console.community.index', '커뮤니티', '게시판과 공지'],
            ['oj_manage_settings', 'console.settings.index', '운영', '기능과 연결 설정'],
        ];
        $items = [];
        $seenRoutes = [];
        foreach ($definitions as [$capability, $routeName, $title, $description]) {
            if (
                isset($seenRoutes[$routeName])
                || ! $this->permissions->can($context->userId(), $capability)
            ) {
                continue;
            }
            $seenRoutes[$routeName] = true;
            $items[] = new DashboardItem(
                $title,
                $description,
                RouteTarget::route($routeName)
            );
            if (count($items) >= 8) {
                break;
            }
        }

        return $items === [] ? null : new DashboardBlock(
            $this->key(),
            $this->title(),
            '현재 권한에서 사용할 수 있는 대표 기능입니다.',
            $this->kind(),
            $items
        );
    }
}
