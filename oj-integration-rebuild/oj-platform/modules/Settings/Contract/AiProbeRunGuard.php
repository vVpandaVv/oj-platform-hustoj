<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

interface AiProbeRunGuard
{
    public function acquire(string $profileKey, int $actorUserId): string;

    public function release(string $profileKey, int $actorUserId, string $token): void;

    public function reset(string $profileKey, int $actorUserId): void;

    /** @return array{running:bool,retry_after:int} */
    public function status(string $profileKey, int $actorUserId): array;
}
