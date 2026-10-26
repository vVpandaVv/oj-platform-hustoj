<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Contract;

interface ContextRule
{
    public function supports(string $capability, PermissionContext $context): bool;

    public function allows(int $userId, string $capability, PermissionContext $context): bool;
}
