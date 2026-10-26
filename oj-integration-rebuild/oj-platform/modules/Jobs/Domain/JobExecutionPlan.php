<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Domain;

use InvalidArgumentException;

final class JobExecutionPlan
{
    /**
     * @param array<string, mixed> $inputSummary
     * @param array<string, mixed> $targetSummary
     */
    public function __construct(
        private readonly string $jobType,
        private readonly string $ownerModule,
        private readonly string $handlerVersion,
        private readonly string $inputVersion,
        private readonly string $accessPolicyKey,
        private readonly string $accessPolicyVersion,
        private readonly string $executionMode,
        private readonly string $idempotencyKey,
        private readonly int $createdByUserId,
        private readonly int $totalCount,
        private readonly array $inputSummary,
        private readonly array $targetSummary
    ) {
        foreach (
            [
                $this->jobType,
                $this->ownerModule,
                $this->accessPolicyKey,
                $this->executionMode,
            ] as $key
        ) {
            if (preg_match('/^[a-z][a-z0-9_.]*$/', $key) !== 1) {
                throw new InvalidArgumentException('Jobs 실행 계획 키가 올바르지 않습니다.');
            }
        }
        if (
            preg_match('/^[1-9][0-9]*$/', $this->handlerVersion) !== 1
            || preg_match('/^[1-9][0-9]*$/', $this->inputVersion) !== 1
            || preg_match('/^[1-9][0-9]*$/', $this->accessPolicyVersion) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $this->idempotencyKey) !== 1
            || $this->createdByUserId < 1
            || $this->totalCount < 1
        ) {
            throw new InvalidArgumentException('Jobs 실행 계획 값이 올바르지 않습니다.');
        }
    }

    public function jobType(): string
    {
        return $this->jobType;
    }

    public function ownerModule(): string
    {
        return $this->ownerModule;
    }

    public function handlerVersion(): string
    {
        return $this->handlerVersion;
    }

    public function inputVersion(): string
    {
        return $this->inputVersion;
    }

    public function accessPolicyKey(): string
    {
        return $this->accessPolicyKey;
    }

    public function accessPolicyVersion(): string
    {
        return $this->accessPolicyVersion;
    }

    public function executionMode(): string
    {
        return $this->executionMode;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function createdByUserId(): int
    {
        return $this->createdByUserId;
    }

    public function totalCount(): int
    {
        return $this->totalCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSummary(): array
    {
        return $this->inputSummary;
    }

    /**
     * @return array<string, mixed>
     */
    public function targetSummary(): array
    {
        return $this->targetSummary;
    }
}
