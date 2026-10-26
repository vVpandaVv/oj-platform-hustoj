<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Boot;

use CodingPanda\OjPlatform\Kernel\Exception\KernelException;

final class BootTrace
{
    /**
     * @var list<BootPhase>
     */
    private array $completed = [];

    public function advance(BootPhase $phase): void
    {
        $expected = BootPhase::cases()[count($this->completed)] ?? null;

        if ($expected !== $phase) {
            throw KernelException::invalidBootPhase(
                $expected?->value ?? 'complete',
                $phase->value
            );
        }

        $this->completed[] = $phase;
    }

    /**
     * @return list<BootPhase>
     */
    public function completedPhases(): array
    {
        return $this->completed;
    }

    public function isComplete(): bool
    {
        return count($this->completed) === count(BootPhase::cases());
    }
}
