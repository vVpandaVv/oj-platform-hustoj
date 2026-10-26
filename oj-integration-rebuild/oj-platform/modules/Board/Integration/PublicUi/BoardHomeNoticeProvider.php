<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Integration\PublicUi;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityQueryService;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostSearch;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeNoticeItem;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeNoticeProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use Throwable;

final class BoardHomeNoticeProvider implements HomeNoticeProvider
{
    public function __construct(
        private readonly CommunityQueryService $queries,
        private readonly FeatureFlags $features,
        private readonly RouteUrlProvider $urls
    ) {
    }

    public function current(int $userId, int $limit = 5): array
    {
        try {
            if (! $this->features->isEnabled('notices')) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }
        try {
            $listing = $this->queries->listing(
                $userId,
                new CommunityPostSearch(
                    'notice',
                    '',
                    null,
                    null,
                    'all',
                    ['publish']
                ),
                1,
                max(1, min(5, $limit)),
                false,
                false
            );
        } catch (Throwable) {
            return [];
        }
        $items = [];
        foreach ($listing->items() as $item) {
            $post = $item->post();
            $items[] = new HomeNoticeItem(
                $post->id(),
                $post->title(),
                $post->publishAt() ?? $post->createdAt(),
                $post->isPinned(),
                $post->isImportant(),
                $this->urls->path('notices.show', ['notice_id' => $post->id()])
            );
        }

        return $items;
    }
}
