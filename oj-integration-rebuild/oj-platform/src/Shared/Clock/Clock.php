<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Clock;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
