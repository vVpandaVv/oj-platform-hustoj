<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\DashboardBlockProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardItem;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class EndingContestDashboardProvider implements DashboardBlockProvider
{
    public function __construct(
        private readonly ContestService $contests,
        private readonly FeatureFlags $features
    ) {
    }

    public function key(): string { return 'tasks.ending_contests'; }
    public function title(): string { return '오늘 종료되는 대회·평가'; }
    public function kind(): string { return 'tasks'; }
    public function priority(): int { return 30; }

    public function load(DashboardContext $context): ?DashboardBlock
    {
        if (! $this->features->isEnabled('contests')) {
            return null;
        }
        $today = $context->now()->format('Y-m-d');
        $count = 0;
        foreach ($this->contests->managedBy($context->userId()) as $contest) {
            if (
                $contest->stage($context->now()->format('Y-m-d H:i:s')) === 'running'
                && str_starts_with($contest->endsAt(), $today)
            ) {
                ++$count;
            }
        }
        if ($count < 1) {
            return null;
        }

        return new DashboardBlock(
            $this->key(),
            $this->title(),
            '참가 현황과 종료 전 운영 상태를 확인합니다.',
            $this->kind(),
            [new DashboardItem(
                $this->title(),
                '담당 대회·평가의 참가 현황과 미제출 대상을 확인합니다.',
                RouteTarget::route('console.contests.index'),
                $count,
                '오늘',
                'warning'
            )]
        );
    }
}
