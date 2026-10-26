<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionReviewProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;

final class SubmissionReviewService implements SubmissionReviewProvider
{
    public function __construct(private readonly SubmissionQueryService $submissions)
    {
    }

    public function findForReview(int $actorUserId, string $actorUserLogin, int $submissionId): ?SubmissionProjection
    {
        if ($actorUserId < 1 || $submissionId < 1) {
            return null;
        }
        $row = $this->submissions->reviewSummary($actorUserId, $actorUserLogin, new SubmissionId($submissionId));
        if ($row === null) {
            return null;
        }
        return $this->projection($row);
    }

    public function findManyForReview(int $actorUserId, string $actorUserLogin, array $submissionIds): array
    {
        if ($actorUserId < 1 || $submissionIds === []) {
            return [];
        }
        $ids = array_map(static fn (int $id): SubmissionId => new SubmissionId($id), $submissionIds);
        $result = [];
        foreach ($this->submissions->reviewSummaries($actorUserId, $actorUserLogin, $ids) as $row) {
            $result[$row->submissionId()->value()] = $this->projection($row);
        }
        return $result;
    }

    private function projection(\CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary $row): SubmissionProjection
    {
        return new SubmissionProjection($row->submissionId()->value(), $row->problemId(), $row->userLogin(), $row->resultCode(),
            $row->languageId(), $row->timeMilliseconds(), $row->memoryKilobytes(), $row->submittedAt(),
            $row->isValid(), $row->passRate(), $row->contestId());
    }
}
