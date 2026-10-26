<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Home;

use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeBlockProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockItem;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class NoticeHomeBlockProvider implements HomeBlockProvider
{
    public function __construct(private readonly HomeNoticeProviderRegistry $notices)
    {
    }

    public function key(): string { return 'notices'; }
    public function title(): string { return '공지'; }

    public function load(HomeBlockContext $context, int $limit): HomeBlock
    {
        $items = [];
        foreach ($this->notices->current($context->userId(), $limit) as $notice) {
            $badge = $notice->isPinned()
                ? '고정'
                : ($notice->isImportant() ? '중요' : '공지');
            $tone = $notice->isImportant() ? 'warning' : 'info';
            $items[] = new HomeBlockItem(
                $notice->title(),
                '',
                RouteTarget::internalPath($notice->url()),
                $badge,
                $tone,
                $notice->publishedAt(),
                '읽기'
            );
        }

        return new HomeBlock(
            $this->key(),
            $this->title(),
            '최근 게시된 안내입니다.',
            'notices',
            $items,
            RouteTarget::route('notices.index'),
            '새 공지가 없습니다.',
            '새 안내가 게시되면 여기에 표시됩니다.'
        );
    }
}
