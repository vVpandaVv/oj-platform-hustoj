<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeBlockProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class LearningSpacesHomeBlockProvider implements HomeBlockProvider
{
    public function __construct(private readonly LearningSpaceHomeCatalog $spaces)
    {
    }

    public function key(): string { return 'learning_spaces'; }
    public function title(): string { return '내 학습 공간'; }

    public function load(HomeBlockContext $context, int $limit): HomeBlock
    {
        $snapshot = $this->spaces->visible($context->userId(), $context->now());
        $items = [];
        foreach (array_slice($snapshot->items(), 0, $limit) as $space) {
            $items[] = $space->homeItem();
        }

        return new HomeBlock(
            $this->key(),
            $this->title(),
            '참여하거나 바로 열 수 있는 공간입니다.',
            'cards',
            $items,
            RouteTarget::route('learning_spaces.index'),
            '현재 표시할 학습 공간이 없습니다.',
            '문제 목록에서 자유 학습을 시작할 수 있습니다.',
            false,
            $snapshot->partial()
        );
    }
}
