<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Contract;

interface CapabilityChecker
{
    public function userCan(int $userId, string $capability): bool;

    public function isSuperAdmin(int $userId): bool;
}
