<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Board\Integration\Problems;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityContextRegistry;
use CodingPanda\OjPlatform\Modules\Board\Application\CommunityQueryService;
use CodingPanda\OjPlatform\Modules\Board\Domain\CommunityPostSearch;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSection;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSectionItem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSectionProvider;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use Throwable;

final class ProblemCommunitySectionProvider implements ProblemDetailSectionProvider
{
    public function __construct(
        private readonly CommunityContextRegistry $contexts,
        private readonly CommunityQueryService $queries,
        private readonly FeatureFlags $features,
        private readonly RouteUrlProvider $urls
    ) {
    }

    public function key(): string
    {
        return 'problem-community';
    }

    public function provide(
        int $problemId,
        int $userId,
        int $limit = 5
    ): ?ProblemDetailSection {
        try {
            if (
                ! $this->features->isEnabled('board')
                || ! $this->features->isEnabled('problems')
            ) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }
        try {
            $access = $this->contexts->resolve('problem', $problemId, $userId);
            if (
                ! $access->canView()
                || $access->isProtected()
                || $access->isOrphaned()
            ) {
                return null;
            }
            $listing = $this->queries->listing(
                $userId,
                new CommunityPostSearch(
                    'board',
                    '',
                    'problem',
                    $problemId,
                    'all',
                    ['publish']
                ),
                1,
                max(1, min(5, $limit)),
                false,
                false
            );
        } catch (Throwable) {
            return null;
        }
        $items = [];
        foreach ($listing->items() as $item) {
            $post = $item->post();
            $items[] = new ProblemDetailSectionItem(
                $post->title(),
                $this->urls->path(
                    'board.thread',
                    ['board_thread_id' => $post->id()]
                ),
                $post->updatedAt() . ' · 댓글 ' . $item->commentCount() . '개',
                $item->isAnswered() ? '답변 있음' : '답변 대기'
            );
        }

        return new ProblemDetailSection(
            'problem-community',
            '이 문제의 질문',
            $items,
            '질문하기',
            $this->urls->path('board.new')
                . '?context=problem&target=' . $problemId,
            '전체 질문',
            $this->urls->path('board.index')
                . '?context=problem&target=' . $problemId
        );
    }
}
