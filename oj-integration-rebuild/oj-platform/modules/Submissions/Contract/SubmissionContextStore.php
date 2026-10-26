<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;

interface SubmissionContextStore
{
    public function save(
        SubmissionId $submissionId,
        SubmissionContext $context
    ): void;

    public function remove(SubmissionId $submissionId): void;

    /**
     * @param list<SubmissionId> $submissionIds
     * @return array<int, SubmissionContext>
     */
    public function findMany(array $submissionIds): array;

    /**
     * @return list<int>
     */
    public function submissionIds(
        ?int $classId,
        ?int $groupId,
        int $limit,
        int $offset
    ): array;
}
