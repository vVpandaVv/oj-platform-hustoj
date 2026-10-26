<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

use InvalidArgumentException;

final class SubmissionSettings
{
    public function __construct(
        private readonly int $disabledLanguageMask,
        private readonly int $maxSourceBytes,
        private readonly int $cooldownSeconds,
        private readonly bool $allowResubmitAfterAccept,
        private readonly int $pageSize,
        private readonly int $maximumPollSeconds,
        private readonly bool $allowCodeCopy = true,
        private readonly bool $allowCodePaste = true
    ) {
        if (
            $this->disabledLanguageMask < 0
            || $this->maxSourceBytes < 1024
            || $this->maxSourceBytes > 61440
            || $this->cooldownSeconds < 0
            || $this->cooldownSeconds > 600
            || $this->pageSize < 10
            || $this->pageSize > 100
            || $this->maximumPollSeconds < 30
            || $this->maximumPollSeconds > 600
        ) {
            throw new InvalidArgumentException('제출 설정 범위가 올바르지 않습니다.');
        }
    }

    public function disabledLanguageMask(): int
    {
        return $this->disabledLanguageMask;
    }

    public function allowCodeCopy(): bool { return $this->allowCodeCopy; }
    public function allowCodePaste(): bool { return $this->allowCodePaste; }

    public function maxSourceBytes(): int
    {
        return $this->maxSourceBytes;
    }

    public function cooldownSeconds(): int
    {
        return $this->cooldownSeconds;
    }

    public function allowResubmitAfterAccept(): bool
    {
        return $this->allowResubmitAfterAccept;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }

    public function maximumPollSeconds(): int
    {
        return $this->maximumPollSeconds;
    }
}
