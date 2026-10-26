<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;

final class CurrentAccount
{
    public function __construct(
        private readonly ?UserIdentity $identity,
        private readonly ?UserProfile $profile
    ) {
    }

    public function identity(): ?UserIdentity
    {
        return $this->identity;
    }

    public function profile(): ?UserProfile
    {
        return $this->profile;
    }

    public function isLoggedIn(): bool
    {
        return $this->identity !== null;
    }

    public function isActive(): bool
    {
        return $this->profile?->accessStatus() === AccessStatus::Active;
    }

    public function needsProfileCompletion(): bool
    {
        return $this->identity !== null && $this->profile === null;
    }

    public function mustChangePassword(): bool
    {
        return $this->profile?->mustChangePassword() === true;
    }
}
