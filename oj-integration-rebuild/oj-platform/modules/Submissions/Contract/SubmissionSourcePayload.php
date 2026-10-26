<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use InvalidArgumentException;

final class SubmissionSourcePayload
{
    /**
     * @param list<array{start: int, length: int}> $controlledRanges
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        private readonly string $mode,
        private readonly int $languageId,
        private readonly string $userSource,
        private readonly string $storedUserSource,
        private readonly array $controlledRanges,
        private readonly bool $languageOverride,
        private readonly array $metadata = []
    ) {
        if (
            ! in_array($this->mode, ['code', 'code_completion', 'code_ordering', 'blockly'], true)
            || $this->languageId < 0
        ) {
            throw new InvalidArgumentException('제출 소스 결과가 올바르지 않습니다.');
        }
        foreach ($this->controlledRanges as $range) {
            if (
                ! is_array($range)
                || ($range['start'] ?? -1) < 0
                || ($range['length'] ?? -1) < 0
            ) {
                throw new InvalidArgumentException('사용자 입력 코드 범위가 올바르지 않습니다.');
            }
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function languageId(): int
    {
        return $this->languageId;
    }

    public function userSource(): string
    {
        return $this->userSource;
    }

    public function storedUserSource(): string
    {
        return $this->storedUserSource;
    }

    /**
     * @return list<array{start: int, length: int}>
     */
    public function controlledRanges(): array
    {
        return $this->controlledRanges;
    }

    public function allowsLanguageOverride(): bool
    {
        return $this->languageOverride;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
