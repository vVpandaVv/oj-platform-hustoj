<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation;

use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;

final class ProblemDetailPageState
{
    public function __construct(
        private readonly JudgeProblem $problem,
        private readonly ?ProblemMetadata $metadata,
        private readonly bool $canEdit,
        private readonly bool $canManageTestData,
        private readonly ?bool $inPersonalBook,
        private readonly ?QuizRevision $quiz = null
    ) {
    }

    public function problem(): JudgeProblem
    {
        return $this->problem;
    }

    public function metadata(): ?ProblemMetadata
    {
        return $this->metadata;
    }

    public function canEdit(): bool
    {
        return $this->canEdit;
    }

    public function canManageTestData(): bool
    {
        return $this->canManageTestData;
    }

    public function inPersonalBook(): ?bool
    {
        return $this->inPersonalBook;
    }

    public function quiz(): ?QuizRevision
    {
        return $this->quiz !== null
            && $this->quiz->problemId()->equals($this->problem->id())
            && $this->quiz->isCurrentPublished()
            && $this->quiz->definition()->isEnabled()
                ? $this->quiz : null;
    }
}
