<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Domain;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use DateTimeImmutable;

final class HomeBlockContext
{
    public function __construct(
        private readonly UserIdentity $identity,
        private readonly string $locale,
        private readonly DateTimeImmutable $now
    ) {
    }

    public function identity(): UserIdentity { return $this->identity; }
    public function userId(): int { return $this->identity->userId(); }
    public function userLogin(): string { return $this->identity->userLogin(); }
    public function locale(): string { return $this->locale; }
    public function now(): DateTimeImmutable { return $this->now; }
}
