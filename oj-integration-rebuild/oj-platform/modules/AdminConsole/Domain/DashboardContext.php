<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Domain;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use DateTimeImmutable;

final class DashboardContext
{
    public function __construct(
        private readonly UserIdentity $identity,
        private readonly DateTimeImmutable $now
    ) {
    }

    public function identity(): UserIdentity { return $this->identity; }
    public function userId(): int { return $this->identity->userId(); }
    public function now(): DateTimeImmutable { return $this->now; }
}
