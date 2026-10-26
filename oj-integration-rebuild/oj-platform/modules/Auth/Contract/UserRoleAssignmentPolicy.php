<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;

interface UserRoleAssignmentPolicy
{
    public function allows(
        int $actorUserId,
        UserIdentity $target
    ): bool;
}
