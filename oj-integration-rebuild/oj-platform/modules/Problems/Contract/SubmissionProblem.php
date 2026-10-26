<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use InvalidArgumentException;

final class SubmissionProblem
{
    public function __construct(
        private readonly int $problemId,
        private readonly string $title
    ) {
        if ($this->problemId < 1) {
            throw new InvalidArgumentException('문제 번호는 1 이상이어야 합니다.');
        }
        if (trim($this->title) === '') {
            throw new InvalidArgumentException('문제 제목은 비어 있을 수 없습니다.');
        }
    }

    public function problemId(): int
    {
        return $this->problemId;
    }

    public function title(): string
    {
        return $this->title;
    }
}
