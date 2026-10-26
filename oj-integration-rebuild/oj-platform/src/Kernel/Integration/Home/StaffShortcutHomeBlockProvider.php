<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeBlockProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockItem;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class StaffShortcutHomeBlockProvider implements HomeBlockProvider
{
    public function __construct(
        private readonly LearningSpaceHomeCatalog $spaces,
        private readonly Authorization $permissions
    ) {
    }

    public function key(): string { return 'staff_shortcuts'; }
    public function title(): string { return '담당 공간 바로가기'; }

    public function load(HomeBlockContext $context, int $limit): ?HomeBlock
    {
        if (! $this->permissions->can($context->userId(), 'oj_access_console')) {
            return null;
        }
        $snapshot = $this->spaces->managed($context->userId(), $context->now());
        $items = [];
        foreach (array_slice($snapshot->items(), 0, max(0, $limit - 1)) as $space) {
            $items[] = new HomeBlockItem(
                $space->title(),
                $space->category() . ' · ' . $space->meta(),
                $space->target(),
                '',
                $space->tone(),
                '',
                '열기'
            );
        }
        $items[] = new HomeBlockItem(
            '관리자 화면',
            '권한이 있는 운영 메뉴를 엽니다.',
            RouteTarget::route('console.dashboard'),
            '',
            'info',
            '',
            '관리'
        );

        return new HomeBlock(
            $this->key(),
            $this->title(),
            '권한이 있는 대상만 표시합니다.',
            'shortcuts',
            array_slice($items, 0, $limit),
            RouteTarget::route('console.dashboard'),
            '',
            '',
            false,
            $snapshot->partial()
        );
    }
}
