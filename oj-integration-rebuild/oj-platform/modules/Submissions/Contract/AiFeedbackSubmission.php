<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

final class AiFeedbackSubmission
{
    public function __construct(
        private readonly int $submissionId,
        private readonly int $problemId,
        private readonly int $ownerUserId,
        private readonly int $languageId,
        private readonly int $resultCode,
        private readonly int $timeMilliseconds,
        private readonly int $memoryKilobytes,
        private readonly string $submittedAt,
        private readonly string $source,
        private readonly string $compileError,
        private readonly string $runtimeError,
        private readonly bool $valid,
        private readonly bool $processing
    ) {
    }

    public function submissionId(): int
    {
        return $this->submissionId;
    }

    public function problemId(): int
    {
        return $this->problemId;
    }

    public function ownerUserId(): int
    {
        return $this->ownerUserId;
    }

    public function languageId(): int
    {
        return $this->languageId;
    }

    public function resultCode(): int
    {
        return $this->resultCode;
    }

    public function timeMilliseconds(): int
    {
        return $this->timeMilliseconds;
    }

    public function memoryKilobytes(): int
    {
        return $this->memoryKilobytes;
    }

    public function submittedAt(): string
    {
        return $this->submittedAt;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function compileError(): string
    {
        return $this->compileError;
    }

    public function runtimeError(): string
    {
        return $this->runtimeError;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function isProcessing(): bool
    {
        return $this->processing;
    }

    public function sourceHash(): string
    {
        return hash('sha256', $this->source);
    }
}
