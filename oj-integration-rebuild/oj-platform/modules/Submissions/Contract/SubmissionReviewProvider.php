<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionReviewProvider
{
    /** Returns a summary only when the actor has full submission-detail access. */
    public function findForReview(int $actorUserId, string $actorUserLogin, int $submissionId): ?SubmissionProjection;

    /** @param list<int> $submissionIds
     *  @return array<int, SubmissionProjection> */
    public function findManyForReview(int $actorUserId, string $actorUserLogin, array $submissionIds): array;
}
