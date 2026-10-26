<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Profile;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\PublicProfileSectionProvider;
use CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileActivity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileMetric;
use CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileSection;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\UserSubmissionProfileProvider;

final class SubmissionPublicProfileSectionProvider implements PublicProfileSectionProvider
{
    public function __construct(
        private readonly UserSubmissionProfileProvider $submissions,
        private readonly LearningProblemProvider $problems,
        private readonly ProblemTopicDictionary $topics,
        private readonly RouteUrlProvider $routes
    ) {
    }

    public function key(): string { return 'learning_activity'; }
    public function priority(): int { return 100; }

    public function provide(
        int $viewerUserId,
        int $targetUserId,
        string $targetUserLogin,
        int $limit
    ): PublicProfileSection {
        $profile = $this->submissions->find($targetUserLogin, $limit);
        $accuracy = $profile->recentAccuracyPercent();
        $metrics = [
            new PublicProfileMetric('solved', '해결한 문제', $profile->solvedProblems() . '개'),
            new PublicProfileMetric('submissions', '전체 제출', $profile->totalSubmissions() . '회'),
            new PublicProfileMetric('recent_accuracy', '최근 30일 정답률', $accuracy === null ? '-' : $accuracy . '%'),
            new PublicProfileMetric('streak', '연속 학습', $profile->currentStreak() . '일'),
        ];

        $activities = [];
        $topicCounts = [];
        $solved = $profile->recentSolvedProblems();
        $problemIds = array_map(
            static fn ($item): int => $item->problemId(),
            $solved
        );
        $visibleProblems = $this->problems->findManyForUser($viewerUserId, $problemIds);
        foreach ($solved as $item) {
            $problem = $visibleProblems[$item->problemId()] ?? null;
            if ($problem === null) {
                continue;
            }
            $description = [];
            $difficulty = $problem->effectiveDifficulty();
            if ($difficulty !== null) {
                $description[] = '난이도 ' . $difficulty;
            }
            $topicLabels = [];
            foreach ($problem->topicKeys() as $topicKey) {
                $topic = $this->topics->find($topicKey);
                if ($topic !== null && ! $topic->isCategory() && $topic->isActive()) {
                    $topicLabels[] = $topic->label();
                }
            }
            $topicLabels = array_values(array_unique($topicLabels));
            if ($topicLabels !== []) {
                $description[] = implode(', ', array_slice($topicLabels, 0, 2));
                foreach ($topicLabels as $topicLabel) {
                    $topicCounts[$topicLabel] = ($topicCounts[$topicLabel] ?? 0) + 1;
                }
            }
            $activities[] = new PublicProfileActivity(
                '문제 ' . $problem->problemId() . ' · ' . $problem->title(),
                implode(' · ', $description),
                $item->solvedAt(),
                $this->routes->path('problems.show', [
                    'problem_id' => $problem->problemId(),
                ])
            );
        }
        uksort($topicCounts, static function (string $left, string $right) use ($topicCounts): int {
            $byCount = $topicCounts[$right] <=> $topicCounts[$left];

            return $byCount !== 0 ? $byCount : strcmp($left, $right);
        });
        $highlights = array_slice(array_keys($topicCounts), 0, 3);

        return new PublicProfileSection(
            $this->key(),
            '학습 활동',
            '최근 제출 결과를 기준으로 집계합니다.',
            $metrics,
            $activities,
            $highlights
        );
    }
}
