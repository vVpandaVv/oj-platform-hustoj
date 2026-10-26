<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;

interface UserPreferenceBatchProvider
{
    /**
     * @param list<int> $userIds
     * @return array<int, UserPreferences>
     */
    public function findMany(array $userIds): array;
}
