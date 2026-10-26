<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\VisualGradingMode;
use InvalidArgumentException;

final class ProblemEditCommand
{
    /**
     * @param list<string> $topicKeys
     */
    public function __construct(
        private readonly ProblemDraft $problem,
        private readonly ?DifficultyScore $difficulty,
        private readonly array $topicKeys,
        private readonly CodeEntryMode $entryMode,
        private readonly ?string $completionSchema,
        private readonly string $adminNotes,
        private readonly string $generationMethod = 'manual',
        private readonly ?string $difficultySource = 'manual',
        private readonly VisualGradingMode $visualGradingMode = VisualGradingMode::None,
        private readonly ?string $orderingSchema = null,
        private readonly ?string $orderingHash = null
    ) {
        if (count($this->topicKeys) > 3) {
            throw new InvalidArgumentException('문제 주제는 최대 3개까지 선택할 수 있습니다.');
        }
    }

    public function problem(): ProblemDraft
    {
        return $this->problem;
    }

    public function difficulty(): ?DifficultyScore
    {
        return $this->difficulty;
    }

    /**
     * @return list<string>
     */
    public function topicKeys(): array
    {
        return $this->topicKeys;
    }

    public function entryMode(): CodeEntryMode
    {
        return $this->entryMode;
    }

    public function completionSchema(): ?string
    {
        return $this->completionSchema;
    }

    public function adminNotes(): string
    {
        return $this->adminNotes;
    }

    public function generationMethod(): string
    {
        return $this->generationMethod;
    }

    public function difficultySource(): ?string
    {
        return $this->difficultySource;
    }

    public function visualGradingMode(): VisualGradingMode
    {
        return $this->visualGradingMode;
    }

    public function orderingSchema(): ?string { return $this->orderingSchema; }

    public function orderingHash(): ?string { return $this->orderingHash; }
}
