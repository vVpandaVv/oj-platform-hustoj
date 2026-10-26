<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\DashboardBlockProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardBlock;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext;
use CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardItem;

final class ManagedLearningDashboardProvider implements DashboardBlockProvider
{
    public function __construct(private readonly LearningSpaceHomeCatalog $spaces)
    {
    }

    public function key(): string { return 'learning.managed_spaces'; }
    public function title(): string { return '담당 학습 공간'; }
    public function kind(): string { return 'shortcuts'; }
    public function priority(): int { return 40; }

    public function load(DashboardContext $context): ?DashboardBlock
    {
        $snapshot = $this->spaces->managed($context->userId(), $context->now());
        $items = [];
        foreach (array_slice($snapshot->items(), 0, 6) as $space) {
            $items[] = new DashboardItem(
                $space->title(),
                $space->category() . ' · ' . $space->meta(),
                $space->target(),
                null,
                $space->category(),
                $space->tone()
            );
        }

        return $items === [] ? null : new DashboardBlock(
            $this->key(),
            $this->title(),
            '현재 운영할 수 있는 수업·대회·그룹입니다.',
            $this->kind(),
            $items
        );
    }
}
