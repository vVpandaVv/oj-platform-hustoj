<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

use CodingPanda\OjPlatform\Modules\PublicUi\Contract\HomeBlockProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlock;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockContext;
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\HomeBlockItem;
use CodingPanda\OjPlatform\Ui\View\RouteTarget;

final class ContinueLearningHomeBlockProvider implements HomeBlockProvider
{
    private const ACCEPTED = 4;

    public function __construct(private readonly HomeSubmissionFeed $feed)
    {
    }

    public function key(): string { return 'continue_learning'; }
    public function title(): string { return '이어서 하기'; }

    public function load(HomeBlockContext $context, int $limit): HomeBlock
    {
        unset($limit);
        $entries = $this->feed->entries($context->userId(), $context->userLogin());
        $entry = null;
        $seen = [];
        foreach ($entries as $candidate) {
            if (isset($seen[$candidate->problemId()])) {
                continue;
            }
            $seen[$candidate->problemId()] = true;
            if ($candidate->resultCode() > self::ACCEPTED
                && ! $this->feed->hasAccepted($context->userLogin(), $candidate->problemId())) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry !== null) {
            $item = new HomeBlockItem(
                $entry->problemId() . ' · ' . $entry->problemTitle(),
                '최근 채점 결과: ' . $entry->resultLabel() . '. 풀이를 이어서 완성해 보세요.',
                RouteTarget::route('problems.show', ['problem_id' => $entry->problemId()]),
                '최근 미해결 문제',
                $entry->resultTone(),
                '',
                '이어서 풀기'
            );
        } elseif ($entries !== []) {
            $item = new HomeBlockItem(
                '내 학습 현황을 확인하세요.',
                '해결한 문제와 다음 추천 문제를 한곳에서 확인합니다.',
                RouteTarget::route('learning_dashboard.index'),
                '오늘의 학습',
                'success',
                '',
                '내 학습 열기'
            );
        } else {
            $item = new HomeBlockItem(
                '첫 문제를 찾아 학습을 시작하세요.',
                '주제와 난이도로 지금 풀 문제를 선택할 수 있습니다.',
                RouteTarget::route('problems.index'),
                '학습 시작',
                'info',
                '',
                '문제 찾기'
            );
        }

        return new HomeBlock(
            $this->key(),
            $this->title(),
            '',
            'banner',
            [$item]
        );
    }
}
