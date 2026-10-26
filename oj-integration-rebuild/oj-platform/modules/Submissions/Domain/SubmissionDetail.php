<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

final class SubmissionDetail
{
    public function __construct(
        private readonly SubmissionSummary $summary,
        private readonly string $userSource,
        private readonly string $judgeSource,
        private readonly string $compileError,
        private readonly string $runtimeError
    ) {
    }

    public function summary(): SubmissionSummary
    {
        return $this->summary;
    }

    public function userSource(): string
    {
        return $this->userSource;
    }

    public function judgeSource(): string
    {
        return $this->judgeSource;
    }

    public function compileError(): string
    {
        return $this->compileError;
    }

    public function runtimeError(): string
    {
        return $this->runtimeError;
    }
}
