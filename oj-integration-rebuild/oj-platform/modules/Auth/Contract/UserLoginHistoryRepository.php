<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\UserLoginRecord;

interface UserLoginHistoryRepository
{
    public function record(int $userId, ?string $ipAddress, ?string $userAgent): void;

    /**
     * @return list<UserLoginRecord>
     */
    public function findForUser(int $userId, int $limit, int $offset = 0): array;

    public function countForUser(int $userId): int;
}
