<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Domain;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobView;

final class JobDetails implements JobView
{
    /**
     * @param array<string, mixed> $targetSummary
     * @param array<string, mixed> $resultSummary
     * @param list<JobItemDetails> $items
     * @param list<JobStepDetails> $steps
     */
    public function __construct(
        private readonly int $id,
        private readonly string $jobType,
        private readonly string $ownerModule,
        private readonly string $accessPolicyKey,
        private readonly string $accessPolicyVersion,
        private readonly int $createdByUserId,
        private readonly string $status,
        private readonly int $totalCount,
        private readonly int $succeededCount,
        private readonly int $failedCount,
        private readonly int $cancelledCount,
        private readonly string $createdAt,
        private readonly ?string $completedAt,
        private readonly array $targetSummary,
        private readonly array $resultSummary,
        private readonly array $items,
        private readonly array $steps
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function jobType(): string
    {
        return $this->jobType;
    }

    public function ownerModule(): string
    {
        return $this->ownerModule;
    }

    public function accessPolicyKey(): string
    {
        return $this->accessPolicyKey;
    }

    public function accessPolicyVersion(): string
    {
        return $this->accessPolicyVersion;
    }

    public function createdByUserId(): int
    {
        return $this->createdByUserId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function totalCount(): int
    {
        return $this->totalCount;
    }

    public function succeededCount(): int
    {
        return $this->succeededCount;
    }

    public function failedCount(): int
    {
        return $this->failedCount;
    }

    public function cancelledCount(): int
    {
        return $this->cancelledCount;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function completedAt(): ?string
    {
        return $this->completedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function targetSummary(): array
    {
        return $this->targetSummary;
    }

    /**
     * @return array<string, mixed>
     */
    public function resultSummary(): array
    {
        return $this->resultSummary;
    }

    /**
     * @return list<JobItemDetails>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return list<JobStepDetails>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function isTerminal(): bool
    {
        return in_array(
            $this->status,
            ['completed', 'partial', 'failed', 'cancelled', 'needs_attention'],
            true
        );
    }

    public function resultMessage(): ?string
    {
        return ($this->items[0] ?? null)?->resultMessage();
    }
}
