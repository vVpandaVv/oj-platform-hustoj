<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Domain;

final class JobStepDetails
{
    public function __construct(
        private readonly int $itemId,
        private readonly string $stepKey,
        private readonly int $stepOrder,
        private readonly string $status,
        private readonly ?string $startedAt,
        private readonly ?string $completedAt,
        private readonly ?string $compensationStatus
    ) {
    }

    public function itemId(): int
    {
        return $this->itemId;
    }

    public function stepKey(): string
    {
        return $this->stepKey;
    }

    public function stepOrder(): int
    {
        return $this->stepOrder;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function startedAt(): ?string
    {
        return $this->startedAt;
    }

    public function completedAt(): ?string
    {
        return $this->completedAt;
    }

    public function compensationStatus(): ?string
    {
        return $this->compensationStatus;
    }
}
