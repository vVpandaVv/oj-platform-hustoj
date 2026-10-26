<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

final class SubmissionProjection
{
    public function __construct(
        private readonly int $submissionId,
        private readonly int $problemId,
        private readonly string $userLogin,
        private readonly int $resultCode,
        private readonly int $languageId,
        private readonly int $timeMilliseconds,
        private readonly int $memoryKilobytes,
        private readonly string $submittedAt,
        private readonly bool $valid,
        private readonly float $passRate = 0.0,
        private readonly int $contestId = 0,
        private readonly string $resultLabel = '',
        private readonly bool $processing = false
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

    public function userLogin(): string
    {
        return $this->userLogin;
    }

    public function resultCode(): int
    {
        return $this->resultCode;
    }

    public function languageId(): int
    {
        return $this->languageId;
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

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function passRate(): float
    {
        return max(0.0, min(1.0, $this->passRate));
    }

    public function contestId(): int
    {
        return max(0, $this->contestId);
    }

    public function resultLabel(): string
    {
        return $this->resultLabel;
    }

    public function isProcessing(): bool
    {
        return $this->processing;
    }
}
