<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblem;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblemStatistics;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestRankingRow;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestStatistics;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;

final class ContestStatisticsCalculator
{
    /**
     * @param list<UserIdentity> $participants
     * @param list<ContestProblem> $problems
     * @param list<SubmissionProjection> $submissions
     * @param list<ContestRankingRow> $rankings
     */
    public function calculate(
        array $participants,
        array $problems,
        array $submissions,
        array $rankings
    ): ContestStatistics {
        $participantLogins = [];
        foreach ($participants as $participant) {
            $participantLogins[$participant->userLogin()] = true;
        }
        $problemIds = [];
        $buckets = [];
        foreach ($problems as $problem) {
            $problemIds[$problem->problemId()] = true;
            $buckets[$problem->problemId()] = [
                'submissions' => 0,
                'attempted' => [],
                'accepted' => [],
            ];
        }

        $submittedParticipants = [];
        $submissionCount = 0;
        foreach ($submissions as $submission) {
            if (
                ! $submission->isValid()
                || ! isset($participantLogins[$submission->userLogin()])
                || ! isset($problemIds[$submission->problemId()])
            ) {
                continue;
            }
            ++$submissionCount;
            $submittedParticipants[$submission->userLogin()] = true;
            $bucket = &$buckets[$submission->problemId()];
            ++$bucket['submissions'];
            $bucket['attempted'][$submission->userLogin()] = true;
            if ($submission->resultCode() === 4) {
                $bucket['accepted'][$submission->userLogin()] = true;
            }
            unset($bucket);
        }

        $problemStatistics = [];
        foreach ($problems as $problem) {
            $bucket = $buckets[$problem->problemId()];
            $attempted = count($bucket['attempted']);
            $accepted = count($bucket['accepted']);
            $problemStatistics[] = new ContestProblemStatistics(
                $problem->problemId(),
                $bucket['submissions'],
                $attempted,
                $accepted,
                $attempted === 0 ? 0.0 : ($accepted / $attempted) * 100,
                $attempted === 0 ? 0.0 : $bucket['submissions'] / $attempted
            );
        }

        $participantCount = count($participants);
        $scoreSum = array_sum(array_map(
            static fn (ContestRankingRow $row): float => $row->score(),
            $rankings
        ));
        $solvedSum = array_sum(array_map(
            static fn (ContestRankingRow $row): int => $row->solved(),
            $rankings
        ));

        return new ContestStatistics(
            $participantCount,
            $submissionCount,
            $participantCount === 0 ? 0.0 : $scoreSum / $participantCount,
            $participantCount === 0 ? 0.0 : $solvedSum / $participantCount,
            array_sum(array_map(
                static fn (ContestProblem $problem): float => $problem->score(),
                $problems
            )),
            max(0, $participantCount - count($submittedParticipants)),
            $problemStatistics
        );
    }
}
