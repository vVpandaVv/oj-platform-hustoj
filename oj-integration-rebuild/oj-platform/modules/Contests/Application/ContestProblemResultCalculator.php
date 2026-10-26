<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Application;

use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblem;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblemResult;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestScoringPolicy;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestSettings;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Clock\SystemClock;
use DateTimeImmutable;
use DateTimeZone;

final class ContestProblemResultCalculator
{
    private const PENALTY_RESULT_CODES = [6, 7, 8, 9, 10];
    private const WRONG_PENALTY_SECONDS = 1200;

    public function __construct(
        private readonly Clock $siteClock = new SystemClock()
    ) {
    }

    /** @param list<SubmissionProjection> $attempts */
    public function calculate(
        Contest $contest,
        ContestSettings $settings,
        ContestProblem $problem,
        array $attempts
    ): ContestProblemResult {
        $attempts = array_values(array_filter(
            $attempts,
            static fn (SubmissionProjection $attempt): bool =>
                $attempt->isValid()
        ));
        usort(
            $attempts,
            static fn (
                SubmissionProjection $left,
                SubmissionProjection $right
            ): int => strcmp($left->submittedAt(), $right->submittedAt())
                ?: $left->submissionId() <=> $right->submissionId()
        );

        $accepted = null;
        $bestPassRate = 0.0;
        $penaltyAttempts = 0;
        foreach ($attempts as $attempt) {
            $bestPassRate = max($bestPassRate, $attempt->passRate());
            if ($attempt->resultCode() === 4) {
                $accepted = $attempt;
                $bestPassRate = 1.0;
                break;
            }
            if (in_array(
                $attempt->resultCode(),
                self::PENALTY_RESULT_CODES,
                true
            )) {
                ++$penaltyAttempts;
            }
        }

        $elapsed = $accepted === null
            ? null
            : $this->elapsedSeconds($contest, $accepted);
        $correctness = 0.0;
        $timeScore = 0.0;
        $penaltyPoints = 0;
        if ($settings->scoringPolicy() === ContestScoringPolicy::STANDARD) {
            [$score, $correctness, $timeScore, $penaltyPoints] =
                $this->standardScore(
                $contest,
                $settings,
                $problem,
                $accepted !== null,
                $bestPassRate,
                $penaltyAttempts,
                $elapsed ?? 0
            );
        } elseif ($settings->scoringPolicy() === ContestScoringPolicy::OI) {
            $score = $problem->score() * $bestPassRate;
            $correctness = $score;
        } else {
            $score = $accepted === null ? 0.0 : 1.0;
        }
        $penaltySeconds = $accepted === null
            ? 0
            : ($elapsed ?? 0) + (
                $penaltyAttempts * self::WRONG_PENALTY_SECONDS
            );
        $latest = $attempts === []
            ? null
            : $attempts[array_key_last($attempts)]->submittedAt();

        return new ContestProblemResult(
            $problem->problemId(),
            round(max(0.0, $score), 6),
            $accepted !== null,
            $bestPassRate,
            count($attempts),
            $penaltyAttempts,
            $elapsed,
            round($correctness, 6),
            round($timeScore, 6),
            $penaltyPoints,
            $penaltySeconds,
            $latest
        );
    }

    private function elapsedSeconds(
        Contest $contest,
        SubmissionProjection $accepted
    ): int {
        $startsAt = new DateTimeImmutable(
            $contest->startsAt(),
            $this->siteClock->now()->getTimezone()
        );
        $submittedAt = new DateTimeImmutable(
            $accepted->submittedAt(),
            new DateTimeZone('UTC')
        );

        return max(0, $submittedAt->getTimestamp() - $startsAt->getTimestamp());
    }

    /** @return array{0: float, 1: float, 2: float, 3: int} */
    private function standardScore(
        Contest $contest,
        ContestSettings $settings,
        ContestProblem $problem,
        bool $accepted,
        float $passRate,
        int $wrongAttempts,
        int $elapsedSeconds
    ): array {
        $timeRatio = $settings->timeScorePercent() / 100;
        $correctnessBase = $problem->score() * (1 - $timeRatio);
        $correctness = $accepted
            ? $correctnessBase
            : (
                $settings->partialScorePolicy()
                    === 'pass_rate_correctness_base_v1'
                    ? $correctnessBase * $passRate
                    : 0.0
            );
        $timeScore = 0.0;
        if ($accepted) {
            $duration = max(
                1,
                $contest->durationSeconds(
                    $this->siteClock->now()->getTimezone()
                )
            );
            $boundedElapsed = min($duration, max(0, $elapsedSeconds));
            $timeScore = floor(
                ($problem->score() * $timeRatio)
                    * (($duration - $boundedElapsed) / $duration)
            );
        }
        $penaltyPoints = $wrongAttempts * $settings->wrongPenaltyPoints();

        return [
            max(0.0, $correctness + $timeScore - $penaltyPoints),
            $correctness,
            $timeScore,
            $penaltyPoints,
        ];
    }
}
