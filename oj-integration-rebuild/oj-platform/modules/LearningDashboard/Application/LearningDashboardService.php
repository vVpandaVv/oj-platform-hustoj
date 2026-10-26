<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\LearningDashboard\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearnerLevelSummary;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearnerLevelSummaryProvider;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\LearningDashboardCache;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Domain\DashboardProblemItem;
use CodingPanda\OjPlatform\Modules\LearningDashboard\Domain\LearningDashboardSnapshot;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\LearningHistoryEligibility;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use DateTimeZone;

final class LearningDashboardService
{
    private const ACCEPTED = 4;
    private const MAX_SUBMISSIONS = 100000;
    private const RECOMMENDATION_POLICY_VERSION = 'learning-recommendation-v1';
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly SubmissionProjectionProvider $submissions,
        private readonly LearningProblemProvider $problems,
        private readonly LearningHistoryEligibility $historyEligibility,
        private readonly LearnerLevelSummaryProvider $levels,
        private readonly Clock $clock,
        private readonly LearningDashboardCache $cache,
        private readonly ?\CodingPanda\OjPlatform\Modules\LearningDashboard\Contract\RecommendationPolicy $research = null
    ) {
    }

    public function snapshot(
        UserIdentity $user,
        int $recommendationPage = 0
    ): LearningDashboardSnapshot {
        $recommendationPage = max(0, min(100, $recommendationPage));
        // Resolve the current research condition before touching the ordinary-user cache.
        $researchIds = $this->research?->recommendationIds($user->userId());
        if ($researchIds !== null) { return $this->buildSnapshot($user, $recommendationPage, $researchIds); }
        $cacheKey = 'oj_learning_dashboard_'
            . $user->userId() . ':' . $recommendationPage;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $snapshot = $this->buildSnapshot($user, $recommendationPage);
        $this->cache->put($cacheKey, $snapshot, self::CACHE_TTL_SECONDS);

        return $snapshot;
    }

    private function buildSnapshot(
        UserIdentity $user,
        int $recommendationPage,
        ?array $researchIds = null
    ): LearningDashboardSnapshot {
        $submissions = $this->allSubmissions($user);
        usort(
            $submissions,
            static fn (
                SubmissionProjection $left,
                SubmissionProjection $right
            ): int => $right->submissionId() <=> $left->submissionId()
        );

        $history = [];
        $solved = [];
        $lastSubmissionId = 0;
        foreach ($submissions as $submission) {
            $problemId = $submission->problemId();
            $history[$problemId][] = $submission;
            $lastSubmissionId = max(
                $lastSubmissionId,
                $submission->submissionId()
            );
            if ($submission->resultCode() === self::ACCEPTED) {
                $solved[$problemId] = true;
            }
        }

        [$recentSolved, $recovered] = $this->learningMilestones(
            $submissions
        );

        $publicCatalog = [];
        foreach ($this->problems->catalogForUser(
            $user->userId(),
            100000
        ) as $problem) {
            if ($problem->isPublished()) {
                $publicCatalog[$problem->problemId()] = $problem;
            }
        }
        $historyCatalog = $publicCatalog;
        $missingProblemIds = array_values(array_diff(
            array_keys($history),
            array_keys($historyCatalog)
        ));
        if ($missingProblemIds !== []) {
            foreach ($this->problems->findManyForUser(
                $user->userId(),
                $missingProblemIds
            ) as $problemId => $problem) {
                $historyCatalog[$problemId] = $problem;
            }
        }
        $levelAvailable = $this->levels->isAvailable();
        $level = $levelAvailable
            ? $this->levels->summaryFor(
                $user->userId(),
                $lastSubmissionId
            )
            : null;
        $targetTier = $this->targetDifficulty(
            $submissions,
            $historyCatalog,
            $level
        );

        return new LearningDashboardSnapshot(
            count($submissions),
            count($history),
            count($solved),
            $recentSolved,
            $recovered,
            $levelAvailable,
            $level,
            $this->continueProblems($history, $solved, $historyCatalog),
            $this->reviewProblems($history, $solved, $historyCatalog),
            $researchIds !== null ? $this->researchRecommendations($researchIds, $publicCatalog) : $this->recommendations(
                $user,
                $history,
                $solved,
                $publicCatalog,
                $historyCatalog,
                $targetTier,
                $recommendationPage
            ),
            array_slice($submissions, 0, 8),
            $this->difficultyDistribution($solved, $historyCatalog),
            $this->topicDistribution($solved, $historyCatalog),
            $recommendationPage
        );
    }

    private function researchRecommendations(array $ids, array $catalog): array
    {
        $items=[];
        foreach ($ids as $id) { if (isset($catalog[$id])) { $p=$catalog[$id]; $items[]=new DashboardProblemItem($id,$p->title(),$p->effectiveDifficulty(),$p->topicKeys(),'현재 연구의 문제 제공 조건에 따라 선택했습니다.'); } }
        return array_slice($items,0,6);
    }

    /**
     * @return list<SubmissionProjection>
     */
    private function allSubmissions(UserIdentity $user): array
    {
        $items = [];
        $offset = 0;
        do {
            $page = $this->submissions->report(new SubmissionProjectionQuery(
                [$user->userLogin()],
                [],
                null,
                null,
                null,
                true,
                1000,
                $offset
            ));
            foreach ($page as $submission) {
                if (
                    ! $submission->isProcessing()
                    && $this->historyEligibility->includes(
                        $user->userId(),
                        $submission
                    )
                ) {
                    $items[] = $submission;
                }
            }
            $offset += count($page);
        } while (
            count($page) === 1000
            && $offset < self::MAX_SUBMISSIONS
        );

        return $items;
    }

    /**
     * @param array<int, list<SubmissionProjection>> $history
     * @param array<int, true> $solved
     * @param array<int, LearningProblem> $catalog
     * @param array<int, LearningProblem> $learningCatalog
     * @return list<DashboardProblemItem>
     */
    private function continueProblems(
        array $history,
        array $solved,
        array $catalog
    ): array {
        $items = [];
        foreach ($history as $problemId => $attempts) {
            if (isset($solved[$problemId], $catalog[$problemId])) {
                continue;
            }
            $items[] = [
                'last' => max(array_map(
                    static fn (SubmissionProjection $item): int =>
                        $item->submissionId(),
                    $attempts
                )),
                'item' => $this->problemItem(
                    $catalog[$problemId] ?? null,
                    '최근 풀이를 이어서 도전'
                ),
            ];
        }
        usort(
            $items,
            static fn (array $left, array $right): int =>
                $right['last'] <=> $left['last']
        );

        return array_values(array_filter(array_map(
            static fn (array $row): ?DashboardProblemItem => $row['item'],
            array_slice($items, 0, 6)
        )));
    }

    /**
     * @param array<int, list<SubmissionProjection>> $history
     * @param array<int, true> $solved
     * @param array<int, LearningProblem> $catalog
     * @param array<int, LearningProblem> $learningCatalog
     * @return list<DashboardProblemItem>
     */
    private function reviewProblems(
        array $history,
        array $solved,
        array $catalog
    ): array {
        $items = [];
        foreach ($solved as $problemId => $_) {
            $attempts = $history[$problemId] ?? [];
            if (count($attempts) < 3 || ! isset($catalog[$problemId])) {
                continue;
            }
            $items[] = [
                'attempts' => count($attempts),
                'item' => $this->problemItem(
                    $catalog[$problemId],
                    count($attempts) . '회 시도한 문제 복습'
                ),
            ];
        }
        usort(
            $items,
            static fn (array $left, array $right): int =>
                $right['attempts'] <=> $left['attempts']
        );

        return array_column(array_slice($items, 0, 6), 'item');
    }

    /**
     * @param array<int, list<SubmissionProjection>> $history
     * @param array<int, true> $solved
     * @param array<int, LearningProblem> $catalog
     * @param array<int, LearningProblem> $learningCatalog
     * @return list<DashboardProblemItem>
     */
    private function recommendations(
        UserIdentity $user,
        array $history,
        array $solved,
        array $catalog,
        array $learningCatalog,
        int $targetTier,
        int $page
    ): array {
        $solvedTopics = [];
        foreach (array_keys($solved) as $problemId) {
            $problem = $learningCatalog[$problemId] ?? null;
            if (! $problem instanceof LearningProblem) {
                continue;
            }
            foreach ($problem->topicKeys() as $topic) {
                $solvedTopics[$topic] = true;
            }
        }

        $unresolvedRecency = [];
        foreach ($history as $problemId => $attempts) {
            if (isset($solved[$problemId]) || ! isset($catalog[$problemId])) {
                continue;
            }
            $unresolvedRecency[$problemId] = max(array_map(
                static fn (SubmissionProjection $submission): int =>
                    $submission->submissionId(),
                $attempts
            ));
        }
        arsort($unresolvedRecency, SORT_NUMERIC);
        $unresolvedRanks = array_flip(array_keys($unresolvedRecency));

        $candidates = [];
        foreach ($catalog as $problemId => $problem) {
            if (isset($solved[$problemId])) {
                continue;
            }
            $difficulty = $problem->effectiveDifficulty() ?? $targetTier;
            $newTopics = count(array_filter(
                $problem->topicKeys(),
                static fn (string $topic): bool =>
                    ! isset($solvedTopics[$topic])
            ));
            $unresolvedRank = $unresolvedRanks[$problemId] ?? null;
            $score = 100
                - (abs($difficulty - $targetTier) * 18)
                + min(15, $newTopics * 5);
            if (is_int($unresolvedRank)) {
                $score += 50 + max(0, 18 - ($unresolvedRank * 2));
            }
            $candidates[] = [
                'score' => $score,
                'item' => $this->problemItem(
                    $problem,
                    $this->recommendationReason(
                        is_int($unresolvedRank),
                        $difficulty,
                        $targetTier,
                        $newTopics
                    )
                ),
            ];
        }
        usort($candidates, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            if ($score !== 0) {
                return $score;
            }

            return $left['item']->problemId()
                <=> $right['item']->problemId();
        });
        $pool = array_column(array_slice($candidates, 0, 18), 'item');
        $fixed = array_slice($pool, 0, 2);
        $rotating = array_slice($pool, 2);
        if ($rotating === []) {
            return $fixed;
        }
        $now = $this->clock->now();
        $day = intdiv(
            $now->getTimestamp() + $now->getOffset(),
            86400
        );
        $offset = (
            ($day * 4)
            + ($page * 4)
            + (int) sprintf('%u', crc32(
                $user->userId() . ':' . self::RECOMMENDATION_POLICY_VERSION
            ))
        ) % count($rotating);
        $ordered = [
            ...array_slice($rotating, $offset),
            ...array_slice($rotating, 0, $offset),
        ];

        return [...$fixed, ...array_slice($ordered, 0, 4)];
    }

    /**
     * @param list<SubmissionProjection> $submissions
     * @return array{0: int, 1: int}
     */
    private function learningMilestones(array $submissions): array
    {
        $cutoff = $this->clock->now()
            ->modify('-30 days')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $recentSolved = [];
        $failed = [];
        $recovered = [];
        foreach (array_reverse($submissions) as $submission) {
            $problemId = $submission->problemId();
            if ($submission->resultCode() === self::ACCEPTED) {
                if ($submission->submittedAt() >= $cutoff) {
                    $recentSolved[$problemId] = true;
                }
                if (isset($failed[$problemId])) {
                    $recovered[$problemId] = true;
                }
                continue;
            }
            if ($submission->resultCode() > self::ACCEPTED) {
                $failed[$problemId] = true;
            }
        }

        return [count($recentSolved), count($recovered)];
    }

    /**
     * @param list<SubmissionProjection> $submissions
     * @param array<int, LearningProblem> $catalog
     */
    private function targetDifficulty(
        array $submissions,
        array $catalog,
        ?LearnerLevelSummary $level
    ): int {
        foreach ($submissions as $submission) {
            if ($submission->resultCode() !== self::ACCEPTED) {
                continue;
            }
            $problem = $catalog[$submission->problemId()] ?? null;
            $difficulty = $problem instanceof LearningProblem
                ? $problem->effectiveDifficulty()
                : null;
            if ($difficulty !== null) {
                return min(5, $difficulty + 1);
            }
        }
        if ($level?->provenTier() !== null) {
            return min(5, max(1, $level->provenTier() + 1));
        }

        return match ($level?->level()) {
            'advanced' => 5,
            'intermediate' => 3,
            'beginner' => 2,
            default => 1,
        };
    }

    private function recommendationReason(
        bool $attempted,
        int $difficulty,
        int $targetTier,
        int $newTopics
    ): string {
        if ($attempted) {
            return '최근 미해결 문제 다시 도전';
        }
        if ($newTopics > 0) {
            return '새 주제를 포함한 난이도 ' . $difficulty . ' 문제';
        }
        if ($difficulty >= $targetTier) {
            return '최근 해결 난이도보다 한 단계 도전';
        }

        return '현재 수준에 맞는 기본 추천';
    }

    /**
     * @param array<int, true> $solved
     * @param array<int, LearningProblem> $catalog
     * @return array<int, int>
     */
    private function difficultyDistribution(
        array $solved,
        array $catalog
    ): array {
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach (array_keys($solved) as $problemId) {
            $problem = $catalog[$problemId] ?? null;
            $difficulty = $problem instanceof LearningProblem
                ? $problem->effectiveDifficulty()
                : null;
            if ($difficulty !== null) {
                ++$distribution[$difficulty];
            }
        }

        return $distribution;
    }

    /**
     * @param array<int, true> $solved
     * @param array<int, LearningProblem> $catalog
     * @return array<string, int>
     */
    private function topicDistribution(
        array $solved,
        array $catalog
    ): array {
        $distribution = [];
        foreach (array_keys($solved) as $problemId) {
            $problem = $catalog[$problemId] ?? null;
            if (! $problem instanceof LearningProblem) {
                continue;
            }
            foreach ($problem->topicKeys() as $topic) {
                $distribution[$topic] = ($distribution[$topic] ?? 0) + 1;
            }
        }
        arsort($distribution);

        return array_slice($distribution, 0, 8, true);
    }

    private function problemItem(
        ?LearningProblem $problem,
        string $reason
    ): ?DashboardProblemItem {
        if (! $problem instanceof LearningProblem) {
            return null;
        }

        return new DashboardProblemItem(
            $problem->problemId(),
            $problem->title(),
            $problem->effectiveDifficulty(),
            $problem->topicKeys(),
            $reason
        );
    }
}
