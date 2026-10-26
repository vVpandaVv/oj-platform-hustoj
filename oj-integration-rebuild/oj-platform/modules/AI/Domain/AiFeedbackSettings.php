<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Domain;

use InvalidArgumentException;

final class AiFeedbackSettings
{
    /**
     * @param array<string, string> $activeGuides
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $resultScope,
        private readonly array $activeGuides
    ) {
        if (! in_array($this->resultScope, ['all', 'incorrect'], true)) {
            throw new InvalidArgumentException('AI 피드백 결과 범위가 올바르지 않습니다.');
        }
        foreach (['insufficient', 'beginner', 'intermediate', 'advanced'] as $level) {
            if (trim((string) ($this->activeGuides[$level] ?? '')) === '') {
                throw new InvalidArgumentException('수준별 AI 답변 지침이 필요합니다.');
            }
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function resultScope(): string
    {
        return $this->resultScope;
    }

    public function guideKey(?string $level): string
    {
        $key = $level !== null && isset($this->activeGuides[$level])
            ? $level
            : 'insufficient';

        return $this->activeGuides[$key];
    }

    /**
     * @return array<string, string>
     */
    public function activeGuides(): array
    {
        return $this->activeGuides;
    }
}
