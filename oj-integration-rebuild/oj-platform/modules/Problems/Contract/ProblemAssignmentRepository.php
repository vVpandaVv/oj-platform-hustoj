<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\CollaboratorRole;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemAssignment;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

interface ProblemAssignmentRepository
{
    public function find(ProblemId $problemId): ProblemAssignment;

    public function assignOwner(
        ProblemId $problemId,
        int $ownerUserId,
        int $actorUserId
    ): void;

    public function grant(
        ProblemId $problemId,
        int $userId,
        CollaboratorRole $role,
        int $actorUserId
    ): void;

    public function revoke(ProblemId $problemId, int $userId): void;

    /**
     * @return list<int>
     */
    public function problemIdsForUser(int $userId): array;
}
