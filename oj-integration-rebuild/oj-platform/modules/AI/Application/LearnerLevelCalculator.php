<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\AI\Domain\LearnerLevelProfile;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;

final class LearnerLevelCalculator
{
    private const ACCEPTED = 4;

    /**
     * @var array<int, array{base: int, solved: int, topics: int}>
     */
    private const TIERS = [
        1 => ['base' => 20, 'solved' => 10, 'topics' => 4],
        2 => ['base' => 40, 'solved' => 8, 'topics' => 4],
        3 => ['base' => 60, 'solved' => 6, 'topics' => 3],
        4 => ['base' => 80, 'solved' => 4, 'topics' => 2],
        5 => ['base' => 100, 'solved' => 2, 'topics' => 2],
    ];

    /**
     * @param list<SubmissionProjection> $submissions
     * @param array<int, LearningProblem> $problems
     */
    public function calculate(
        int $userId,
        array $submissions,
        array $problems,
        string $analyzedAt
    ): LearnerLevelProfile {
        usort(
            $submissions,
            static fn (
                SubmissionProjection $left,
                SubmissionProjection $right
            ): int => $left->submissionId() <=> $right->submissionId()
        );
        $history = [];
        $lastSubmissionId = 0;
        foreach ($submissions as $submission) {
            if (! $submission->isValid() || $submission->isProcessing()) {
                continue;
            }
            $history[$submission->problemId()][] = $submission;
            $lastSubmissionId = max(
                $lastSubmissionId,
                $submission->submissionId()
            );
        }
        $submissionCount = array_sum(array_map('count', $history));
        $attemptedCount = count($history);
        $solvedIds = [];
        foreach ($history as $problemId => $attempts) {
            foreach ($attempts as $attempt) {
                if ($attempt->resultCode() === self::ACCEPTED) {
                    $solvedIds[$problemId] = true;
                    break;
                }
            }
        }
        $solvedCount = count($solvedIds);
        $status = $this->status($submissionCount, $attemptedCount);
        $coverage = $this->coverage($history, $problems);
        if ($status === 'insufficient') {
            return new LearnerLevelProfile(
                $userId,
                $status,
                null,
                null,
                [
                    'proven_difficulty' => null,
                    'breadth' => null,
                    'recent_challenge' => null,
                    'stability' => null,
                    'skill_score' => null,
                ],
                $submissionCount,
                $attemptedCount,
                $solvedCount,
                min(20, $attemptedCount),
                $coverage['difficulty'],
                $coverage['topics'],
                $lastSubmissionId,
                $analyzedAt
            );
        }

        [$provenTier, $provenDifficulty] = $this->proven(
            array_keys($solvedIds),
            $problems
        );
        $breadth = $this->breadth(array_keys($solvedIds), $problems);
        $recent = $this->recent($history, 20);
        $recentChallenge = $this->recentChallenge(
            $recent,
            $problems,
            $provenTier
        );
        $stability = $this->stability($recent);
        $skill = round(max(0, min(100,
            ($provenDifficulty * 0.50)
            + ($breadth * 0.15)
            + ($recentChallenge * 0.20)
            + ($stability * 0.15)
        )), 1);
        $level = null;
        if (in_array($status, ['classified', 'reliable'], true)) {
            $level = match (true) {
                $skill >= 70 && $provenDifficulty >= 60 => 'advanced',
                $skill >= 40 && $provenDifficulty >= 40 => 'intermediate',
                default => 'beginner',
            };
        }

        return new LearnerLevelProfile(
            $userId,
            $status,
            $level,
            $provenTier,
            [
                'proven_difficulty' => $provenDifficulty,
                'breadth' => $breadth,
                'recent_challenge' => $recentChallenge,
                'stability' => $stability,
                'skill_score' => $skill,
            ],
            $submissionCount,
            $attemptedCount,
            $solvedCount,
            count($recent),
            $coverage['difficulty'],
            $coverage['topics'],
            $lastSubmissionId,
            $analyzedAt
        );
    }

    private function status(int $submissions, int $problems): string
    {
        return match (true) {
            $submissions >= 30 && $problems >= 10 => 'reliable',
            $submissions >= 20 && $problems >= 8 => 'classified',
            $submissions >= 10 && $problems >= 5 => 'provisional',
            default => 'insufficient',
        };
    }

    /**
     * @param list<int> $solvedIds
     * @param array<int, LearningProblem> $problems
     * @return array{0: ?int, 1: float}
     */
    private function proven(array $solvedIds, array $problems): array
    {
        $bestTier = null;
        $bestScore = 0.0;
        foreach (self::TIERS as $tier => $rule) {
            $solved = 0;
            $topics = [];
            foreach ($solvedIds as $problemId) {
                $problem = $problems[$problemId] ?? null;
                if (
                    ! $problem instanceof LearningProblem
                    || $problem->effectiveDifficulty() === null
                    || $problem->effectiveDifficulty() < $tier
                ) {
                    continue;
                }
                ++$solved;
                foreach ($problem->topicKeys() as $topic) {
                    $topics[$topic] = true;
                }
            }
            if ($solved === 0) {
                continue;
            }
            $score = $rule['base']
                * min($solved / $rule['solved'], 1)
                * min(count($topics) / $rule['topics'], 1);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTier = $tier;
            }
        }

        return [$bestTier, round($bestScore, 1)];
    }

    /**
     * @param list<int> $solvedIds
     * @param array<int, LearningProblem> $problems
     */
    private function breadth(array $solvedIds, array $problems): float
    {
        $topics = [];
        foreach ($solvedIds as $problemId) {
            $problem = $problems[$problemId] ?? null;
            if (! $problem instanceof LearningProblem) {
                continue;
            }
            foreach ($problem->topicKeys() as $topic) {
                $topics[$topic] = true;
            }
        }

        return round(min(count($topics) / 8, 1) * 100, 1);
    }

    /**
     * @param array<int, list<SubmissionProjection>> $history
     * @return array<int, list<SubmissionProjection>>
     */
    private function recent(array $history, int $limit): array
    {
        uasort($history, static function (array $left, array $right): int {
            $leftId = end($left)->submissionId();
            $rightId = end($right)->submissionId();

            return $rightId <=> $leftId;
        });

        return array_slice($history, 0, $limit, true);
    }

    /**
     * @param array<int, list<SubmissionProjection>> $recent
     * @param array<int, LearningProblem> $problems
     */
    private function recentChallenge(
        array $recent,
        array $problems,
        ?int $provenTier
    ): float {
        if ($provenTier === null) {
            return 0.0;
        }
        $known = 0;
        $challenge = 0;
        $challengeSolved = 0;
        foreach ($recent as $problemId => $attempts) {
            $problem = $problems[$problemId] ?? null;
            $difficulty = $problem instanceof LearningProblem
                ? $problem->effectiveDifficulty()
                : null;
            if ($difficulty === null) {
                continue;
            }
            ++$known;
            if ($difficulty < $provenTier) {
                continue;
            }
            ++$challenge;
            foreach ($attempts as $attempt) {
                if ($attempt->resultCode() === self::ACCEPTED) {
                    ++$challengeSolved;
                    break;
                }
            }
        }
        if ($known === 0 || $challenge === 0) {
            return 0.0;
        }

        return round(
            (($challenge / $known) * 60)
            + (($challengeSolved / $challenge) * 40),
            1
        );
    }

    /**
     * @param array<int, list<SubmissionProjection>> $recent
     */
    private function stability(array $recent): float
    {
        if ($recent === []) {
            return 0.0;
        }
        $solved = 0;
        $acceptedAttemptCounts = [];
        foreach ($recent as $attempts) {
            $attemptNumber = 0;
            foreach ($attempts as $attempt) {
                ++$attemptNumber;
                if ($attempt->resultCode() === self::ACCEPTED) {
                    ++$solved;
                    $acceptedAttemptCounts[] = $attemptNumber;
                    break;
                }
            }
        }
        if ($solved === 0) {
            return 0.0;
        }
        $average = array_sum($acceptedAttemptCounts)
            / count($acceptedAttemptCounts);
        $efficiency = match (true) {
            $average <= 2 => 100,
            $average <= 3 => 80,
            $average <= 5 => 60,
            $average <= 8 => 40,
            default => 20,
        };

        return round(
            (($solved / count($recent)) * 100 * 0.60)
            + ($efficiency * 0.40),
            1
        );
    }

    /**
     * @param array<int, list<SubmissionProjection>> $history
     * @param array<int, LearningProblem> $problems
     * @return array{difficulty: float, topics: float}
     */
    private function coverage(array $history, array $problems): array
    {
        if ($history === []) {
            return ['difficulty' => 0.0, 'topics' => 0.0];
        }
        $difficulty = 0;
        $topics = 0;
        foreach (array_keys($history) as $problemId) {
            $problem = $problems[$problemId] ?? null;
            if (! $problem instanceof LearningProblem) {
                continue;
            }
            if ($problem->effectiveDifficulty() !== null) {
                ++$difficulty;
            }
            if ($problem->topicKeys() !== []) {
                ++$topics;
            }
        }
        $total = count($history);

        return [
            'difficulty' => round(($difficulty / $total) * 100, 1),
            'topics' => round(($topics / $total) * 100, 1),
        ];
    }
}
