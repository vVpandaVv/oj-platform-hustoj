<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;

final class RegistrationResult
{
    public function __construct(
        private readonly int $userId,
        private readonly AccessStatus $accessStatus
    ) {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function accessStatus(): AccessStatus
    {
        return $this->accessStatus;
    }
}
