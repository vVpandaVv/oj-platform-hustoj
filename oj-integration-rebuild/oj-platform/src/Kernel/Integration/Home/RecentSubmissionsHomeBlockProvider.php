<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeBlockProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockItem;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class RecentSubmissionsHomeBlockProvider implements HomeBlockProvider
{
    public function __construct(private readonly HomeSubmissionFeed $feed)
    {
    }

    public function key(): string { return 'recent_submissions'; }
    public function title(): string { return '최근 제출'; }

    public function load(HomeBlockContext $context, int $limit): HomeBlock
    {
        $restricted = $this->feed->isRestricted($context->userId());
        $items = [];
        foreach (array_slice(
            $this->feed->entries($context->userId(), $context->userLogin()),
            0,
            $limit
        ) as $entry) {
            $description = $restricted || $entry->languageLabel() === ''
                ? '제출 ' . $entry->submissionId()
                : $entry->languageLabel() . ' · 제출 ' . $entry->submissionId();
            $items[] = new HomeBlockItem(
                $entry->problemId() . ' · ' . $entry->problemTitle(),
                $description,
                $restricted
                    ? RouteTarget::route('submissions.index')
                    : RouteTarget::route(
                        'submissions.detail',
                        ['submission_id' => $entry->submissionId()]
                    ),
                $entry->resultLabel(),
                $entry->resultTone(),
                $entry->submittedAt(),
                '확인'
            );
        }

        return new HomeBlock(
            $this->key(),
            $this->title(),
            $restricted
                ? '수행 모드에서는 채점 결과만 표시합니다.'
                : '최근 채점 결과 5건을 표시합니다.',
            'list',
            $items,
            RouteTarget::route('submissions.index'),
            '아직 제출한 문제가 없습니다.',
            '문제를 제출하면 최근 결과가 여기에 표시됩니다.'
        );
    }
}
