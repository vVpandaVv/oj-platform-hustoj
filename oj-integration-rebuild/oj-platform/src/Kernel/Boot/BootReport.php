<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Boot;

use DateTimeImmutable;

final class BootReport
{
    /**
     * @param list<string> $moduleIds
     * @param list<BootPhase> $completedPhases
     */
    public function __construct(
        private readonly array $moduleIds,
        private readonly array $completedPhases,
        private readonly DateTimeImmutable $completedAt
    ) {
    }

    /**
     * @return list<string>
     */
    public function moduleIds(): array
    {
        return $this->moduleIds;
    }

    /**
     * @return list<BootPhase>
     */
    public function completedPhases(): array
    {
        return $this->completedPhases;
    }

    public function completedAt(): DateTimeImmutable
    {
        return $this->completedAt;
    }
}
