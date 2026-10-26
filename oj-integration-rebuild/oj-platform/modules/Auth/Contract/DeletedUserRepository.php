<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\DeletedUserRecord;

interface DeletedUserRepository
{
    /**
     * @return list<DeletedUserRecord>
     */
    public function findPage(int $limit, int $offset = 0): array;

    public function hasCompleted(int $userId): bool;

    public function recordCompleted(
        int $userId,
        int $actorUserId,
        int $jobId,
        string $reason
    ): void;
}
