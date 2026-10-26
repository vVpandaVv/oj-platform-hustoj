<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblem;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestRankingRow;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestScoringPolicy;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestSettings;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;

final class ContestRankingCalculator
{
    private readonly ContestProblemResultCalculator $problemResults;

    public function __construct(?ContestProblemResultCalculator $problemResults = null)
    {
        $this->problemResults = $problemResults ?? new ContestProblemResultCalculator();
    }

    /**
     * @param list<UserIdentity> $participants
     * @param list<ContestProblem> $problems
     * @param list<SubmissionProjection> $submissions
     * @return list<ContestRankingRow>
     */
    public function calculate(
        Contest $contest,
        ContestSettings $settings,
        array $participants,
        array $problems,
        array $submissions
    ): array {
        $byUserProblem = [];
        foreach ($submissions as $submission) {
            if (! $submission->isValid()) {
                continue;
            }
            $byUserProblem[$submission->userLogin()][$submission->problemId()][]
                = $submission;
        }

        $rows = [];
        foreach ($participants as $participant) {
            $rows[] = $this->participantRow(
                $contest,
                $settings,
                $participant,
                $problems,
                $byUserProblem[$participant->userLogin()] ?? []
            );
        }
        usort(
            $rows,
            fn (array $left, array $right): int =>
                $this->compareMetrics($left, $right, $settings)
                    ?: strcmp($left['login'], $right['login'])
        );

        $ranked = [];
        $previous = null;
        $rank = 0;
        foreach ($rows as $index => $row) {
            if (
                $previous === null
                || $this->compareMetrics($previous, $row, $settings) !== 0
            ) {
                $rank = $index + 1;
            }
            $ranked[] = new ContestRankingRow(
                $rank,
                $row['login'],
                $row['display'],
                $row['solved'],
                $row['score'],
                $row['penalty'],
                $row['completion'],
                $row['problems'],
                $row['problem_results'],
                $row['latest_submission_at']
            );
            $previous = $row;
        }

        return $ranked;
    }

    /**
     * @param list<ContestProblem> $problems
     * @param array<int, list<SubmissionProjection>> $attemptsByProblem
     * @return array{
     *   login: string,
     *   display: string,
     *   solved: int,
     *   score: float,
     *   penalty: int,
     *   completion: int,
     *   problems: array<int, float>,
     *   problem_results: array<int, \CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblemResult>,
     *   latest_submission_at: ?string
     * }
     */
    private function participantRow(
        Contest $contest,
        ContestSettings $settings,
        UserIdentity $participant,
        array $problems,
        array $attemptsByProblem
    ): array {
        $solved = 0;
        $totalScore = 0.0;
        $penalty = 0;
        $completion = 0;
        $problemScores = [];
        $problemResults = [];
        $latestSubmissionAt = null;

        foreach ($problems as $problem) {
            $attempts = $attemptsByProblem[$problem->problemId()] ?? [];
            $result = $this->problemResults->calculate(
                $contest,
                $settings,
                $problem,
                $attempts,
            );
            $elapsed = $result->firstAcceptedElapsedSeconds() ?? 0;
            if ($result->isAccepted()) {
                ++$solved;
                $completion = max($completion, $elapsed);
            }

            $score = $result->score();
            $problemScores[$problem->problemId()] = round($score, 6);
            $problemResults[$problem->problemId()] = $result;
            $totalScore += $score;
            $penalty += (
                $settings->scoringPolicy() === ContestScoringPolicy::STANDARD
                    ? $result->penaltyPoints()
                    : $result->penaltySeconds()
            );
            if (
                $result->latestSubmittedAt() !== null
                && (
                    $latestSubmissionAt === null
                    || $result->latestSubmittedAt() > $latestSubmissionAt
                )
            ) {
                $latestSubmissionAt = $result->latestSubmittedAt();
            }
        }

        return [
            'login' => $participant->userLogin(),
            'display' => $participant->displayName(),
            'solved' => $solved,
            'score' => round($totalScore, 6),
            'penalty' => $penalty,
            'completion' => $completion,
            'problems' => $problemScores,
            'problem_results' => $problemResults,
            'latest_submission_at' => $latestSubmissionAt,
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareMetrics(
        array $left,
        array $right,
        ContestSettings $settings
    ): int {
        if ($settings->rankingPolicy() === ContestScoringPolicy::ICPC_RANKING) {
            return $right['solved'] <=> $left['solved']
                ?: $left['penalty'] <=> $right['penalty'];
        }
        if ($settings->rankingPolicy() === ContestScoringPolicy::OI_RANKING) {
            return $right['score'] <=> $left['score']
                ?: $right['solved'] <=> $left['solved']
                ?: $left['penalty'] <=> $right['penalty'];
        }

        return $right['score'] <=> $left['score']
            ?: $right['solved'] <=> $left['solved']
            ?: $left['completion'] <=> $right['completion'];
    }
}
