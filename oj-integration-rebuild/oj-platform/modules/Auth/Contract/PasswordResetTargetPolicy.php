<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;

interface PasswordResetTargetPolicy
{
    public function allows(
        int $actorUserId,
        UserIdentity $target,
        UserProfile $profile
    ): bool;

    /**
     * True when the target account must never be password-reset, regardless
     * of the actor's authorization (administrator or role-management holders).
     */
    public function protectsTarget(UserIdentity $target): bool;
}
