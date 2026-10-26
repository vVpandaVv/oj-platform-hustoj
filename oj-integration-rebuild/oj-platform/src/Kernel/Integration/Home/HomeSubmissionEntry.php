<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Home;

final class HomeSubmissionEntry
{
    public function __construct(
        private readonly int $submissionId,
        private readonly int $problemId,
        private readonly string $problemTitle,
        private readonly string $resultLabel,
        private readonly string $resultTone,
        private readonly string $languageLabel,
        private readonly string $submittedAt,
        private readonly int $resultCode
    ) {
    }

    public function submissionId(): int { return $this->submissionId; }
    public function problemId(): int { return $this->problemId; }
    public function problemTitle(): string { return $this->problemTitle; }
    public function resultLabel(): string { return $this->resultLabel; }
    public function resultTone(): string { return $this->resultTone; }
    public function languageLabel(): string { return $this->languageLabel; }
    public function submittedAt(): string { return $this->submittedAt; }
    public function resultCode(): int { return $this->resultCode; }
}
