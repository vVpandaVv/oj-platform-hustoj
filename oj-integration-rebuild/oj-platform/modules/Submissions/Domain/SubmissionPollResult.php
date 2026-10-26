<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

use InvalidArgumentException;

final class SubmissionPollResult
{
    public function __construct(
        private readonly SubmissionSummary $summary,
        private readonly SubmissionAccessLevel $access
    ) {
        if ($this->access === SubmissionAccessLevel::Denied) {
            throw new InvalidArgumentException(
                '거부된 제출은 채점 상태 응답으로 만들 수 없습니다.'
            );
        }
    }

    public function summary(): SubmissionSummary
    {
        return $this->summary;
    }

    public function access(): SubmissionAccessLevel
    {
        return $this->access;
    }
}
