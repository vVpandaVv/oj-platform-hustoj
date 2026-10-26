<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Setup;

interface SetupLock
{
    public function acquire(string $token, int $ttlSeconds): bool;

    public function release(string $token): void;
}
