<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;

final class ManagedUser
{
    public function __construct(
        private readonly UserIdentity $identity,
        private readonly UserProfile $profile
    ) {
    }

    public function identity(): UserIdentity
    {
        return $this->identity;
    }

    public function profile(): UserProfile
    {
        return $this->profile;
    }
}
