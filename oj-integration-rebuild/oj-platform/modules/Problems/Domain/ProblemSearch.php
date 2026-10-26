<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

final class ProblemSearch
{
    public function __construct(
        private readonly string $keyword = '',
        private readonly ?ProblemVisibility $visibility = ProblemVisibility::Published,
        private readonly int $limit = 50,
        private readonly int $offset = 0,
        private readonly ?array $problemIds = null,
        private readonly ?bool $specialJudge = null
    ) {
        if ($this->limit < 1 || $this->limit > 100 || $this->offset < 0) {
            throw new InvalidArgumentException('문제 목록 범위가 올바르지 않습니다.');
        }
        if ($this->problemIds !== null) {
            foreach ($this->problemIds as $problemId) {
                if (! is_int($problemId) || $problemId < 1) {
                    throw new InvalidArgumentException('문제 목록의 담당 범위가 올바르지 않습니다.');
                }
            }
        }
    }

    public function keyword(): string
    {
        return trim($this->keyword);
    }

    public function visibility(): ?ProblemVisibility
    {
        return $this->visibility;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * @return list<int>|null
     */
    public function problemIds(): ?array
    {
        return $this->problemIds === null
            ? null
            : array_values(array_unique($this->problemIds));
    }

    public function specialJudge(): ?bool
    {
        return $this->specialJudge;
    }
}
