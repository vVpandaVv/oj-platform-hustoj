<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use InvalidArgumentException;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;

final class ProblemNavigationContext
{
    private function __construct(
        private readonly ?int $classId,
        private readonly ?int $contestId,
        private readonly ?int $contestProblemNumber
    ) {
    }

    public static function general(): self
    {
        return new self(null, null, null);
    }

    public static function class(int $classId): self
    {
        if ($classId < 1) {
            throw new InvalidArgumentException('수업 번호는 양의 정수여야 합니다.');
        }

        return new self($classId, null, null);
    }

    public static function contest(
        int $contestId,
        int $contestProblemNumber
    ): self {
        if ($contestId < 1) {
            throw new InvalidArgumentException('대회 번호는 양의 정수여야 합니다.');
        }
        if ($contestProblemNumber < 0) {
            throw new InvalidArgumentException(
                '대회 문제 순서는 0 이상의 정수여야 합니다.'
            );
        }

        return new self(null, $contestId, $contestProblemNumber);
    }

    public static function fromQueryValues(
        string $contestId,
        string $contestProblemNumber,
        string $classId = ''
    ): self {
        $resolvedContestId = self::integer($contestId, 1);
        $resolvedClassId = self::integer($classId, 1);
        if ($resolvedContestId !== null && $resolvedClassId !== null) {
            return self::general();
        }
        if ($resolvedClassId !== null) {
            return self::class($resolvedClassId);
        }
        if ($resolvedContestId === null) {
            return self::general();
        }

        return new self(
            null,
            $resolvedContestId,
            self::integer($contestProblemNumber, 0)
        );
    }

    public function detailUrl(int $problemId): string
    {
        return $this->scopedUrl('/problems/' . self::problemId($problemId));
    }

    public function submitUrl(int $problemId): string
    {
        return $this->scopedUrl('/submit/' . self::problemId($problemId));
    }

    public function returnUrl(): string
    {
        if ($this->classId !== null) {
            return '/classes/' . $this->classId;
        }

        return $this->contestId === null
            ? '/problems'
            : '/contests/' . $this->contestId;
    }

    public function returnLabel(): string
    {
        return match (true) {
            $this->classId !== null => '수업으로 돌아가기',
            $this->contestId !== null => '대회로 돌아가기',
            default => '목록',
        };
    }

    public function rootLabel(): string
    {
        return match (true) {
            $this->classId !== null => '수업',
            $this->contestId !== null => '대회·평가',
            default => '문제',
        };
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

    public function accessContext(): ProblemAccessContext
    {
        if ($this->classId !== null) {
            return ProblemAccessContext::class($this->classId);
        }
        if ($this->contestId !== null) {
            return ProblemAccessContext::contest(
                $this->contestId,
                $this->contestProblemNumber
            );
        }

        return ProblemAccessContext::general();
    }

    private function scopedUrl(string $baseUrl): string
    {
        $query = [];
        if ($this->classId !== null) {
            $query['class_id'] = $this->classId;
        } elseif ($this->contestId !== null) {
            $query['contest_id'] = $this->contestId;
            if ($this->contestProblemNumber !== null) {
                $query['contest_problem_number'] = $this->contestProblemNumber;
            }
        }
        if ($query === []) {
            return $baseUrl;
        }

        return $baseUrl . '?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    private static function problemId(int $problemId): int
    {
        if ($problemId < 1) {
            throw new InvalidArgumentException('문제 번호는 양의 정수여야 합니다.');
        }

        return $problemId;
    }

    private static function integer(string $value, int $minimum): ?int
    {
        $value = trim($value);
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }
        $number = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => PHP_INT_MAX]]
        );

        return is_int($number) ? $number : null;
    }
}
