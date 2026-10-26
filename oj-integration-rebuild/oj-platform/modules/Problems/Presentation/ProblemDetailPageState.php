<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation;

use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;

final class ProblemDetailPageState
{
    public function __construct(
        private readonly JudgeProblem $problem,
        private readonly ?ProblemMetadata $metadata,
        private readonly bool $canEdit,
        private readonly bool $canManageTestData,
        private readonly ?bool $inPersonalBook
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
}
