<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

final class ProblemRankingEntry
{
    public function __construct(
        private readonly string $userKey,
        private readonly int $attempts,
        private readonly string $acceptedAt,
        private readonly int $runtimeMilliseconds,
        private readonly int $memoryKilobytes
    ) {
    }

    public function userKey(): string
    {
        return $this->userKey;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function acceptedAt(): string
    {
        return $this->acceptedAt;
    }

    public function runtimeMilliseconds(): int
    {
        return $this->runtimeMilliseconds;
    }

    public function memoryKilobytes(): int
    {
        return $this->memoryKilobytes;
    }
}
