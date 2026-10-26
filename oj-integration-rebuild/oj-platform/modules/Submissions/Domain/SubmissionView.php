<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

use InvalidArgumentException;

final class SubmissionView
{
    public function __construct(
        private readonly SubmissionSummary $summary,
        private readonly SubmissionAccessLevel $access,
        private readonly ?SubmissionDetail $detail
    ) {
        if (
            ($this->access === SubmissionAccessLevel::Full) !== ($this->detail !== null)
        ) {
            throw new InvalidArgumentException('제출 상세와 접근 단계가 일치하지 않습니다.');
        }
        if ($this->access === SubmissionAccessLevel::Denied) {
            throw new InvalidArgumentException('거부된 제출은 화면 객체로 만들 수 없습니다.');
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

    public function detail(): ?SubmissionDetail
    {
        return $this->detail;
    }
}
