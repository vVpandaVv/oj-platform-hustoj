<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use InvalidArgumentException;

final class ProblemAccessContext
{
    private function __construct(
        private readonly string $scope,
        private readonly ?int $classId,
        private readonly ?int $contestId,
        private readonly ?int $contestProblemNumber
    ) {
    }

    public static function general(): self
    {
        return new self('general', null, null, null);
    }

    public static function class(int $classId): self
    {
        if ($classId < 1) {
            throw new InvalidArgumentException('수업 번호는 양의 정수여야 합니다.');
        }

        return new self('class', $classId, null, null);
    }

    public static function contest(
        int $contestId,
        ?int $contestProblemNumber = null
    ): self {
        if ($contestId < 1) {
            throw new InvalidArgumentException('대회 번호는 양의 정수여야 합니다.');
        }
        if ($contestProblemNumber !== null && $contestProblemNumber < 0) {
            throw new InvalidArgumentException(
                '대회 문제 순서는 0 이상의 정수여야 합니다.'
            );
        }

        return new self('contest', null, $contestId, $contestProblemNumber);
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function isGeneral(): bool
    {
        return $this->scope === 'general';
    }

    public function classId(): ?int
    {
        return $this->classId;
    }

    public function contestId(): ?int
    {
        return $this->contestId;
    }

    public function contestProblemNumber(): ?int
    {
        return $this->contestProblemNumber;
    }
}
