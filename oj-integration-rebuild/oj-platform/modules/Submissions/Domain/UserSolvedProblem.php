<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

use InvalidArgumentException;

final class UserSolvedProblem
{
    public function __construct(
        private readonly int $problemId,
        private readonly string $solvedAt
    ) {
        if ($this->problemId < 1 || trim($this->solvedAt) === '') {
            throw new InvalidArgumentException('최근 해결 문제 정보가 올바르지 않습니다.');
        }
    }

    public function problemId(): int { return $this->problemId; }
    public function solvedAt(): string { return $this->solvedAt; }
}
