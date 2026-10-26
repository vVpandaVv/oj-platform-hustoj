<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Contract;

interface Authorization
{
    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool;

    /**
     * @param list<string> $capabilities
     */
    public function canAny(int $userId, array $capabilities): bool;

    public function isSuperAdmin(int $userId): bool;
}
