<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class UserSubmissionProfile
{
    /**
     * @param list<UserSolvedProblem> $recentSolvedProblems
     * @param list<string> $acceptedDates
     */
    public function __construct(
        private readonly int $totalSubmissions,
        private readonly int $acceptedSubmissions,
        private readonly int $solvedProblems,
        private readonly int $recentSubmissions,
        private readonly int $recentAcceptedSubmissions,
        private readonly string $today,
        private readonly array $recentSolvedProblems,
        private readonly array $acceptedDates
    ) {
        foreach ([
            $this->totalSubmissions,
            $this->acceptedSubmissions,
            $this->solvedProblems,
            $this->recentSubmissions,
            $this->recentAcceptedSubmissions,
        ] as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('제출 프로필 집계는 음수일 수 없습니다.');
            }
        }
        if (
            $this->acceptedSubmissions > $this->totalSubmissions
            || $this->solvedProblems > $this->acceptedSubmissions
            || $this->recentAcceptedSubmissions > $this->recentSubmissions
        ) {
            throw new InvalidArgumentException(
                '제출 프로필 집계의 전체 수와 정답 수가 올바르지 않습니다.'
            );
        }
        if (DateTimeImmutable::createFromFormat('!Y-m-d', $this->today) === false) {
            throw new InvalidArgumentException('제출 프로필 기준 날짜가 올바르지 않습니다.');
        }
        foreach ($this->recentSolvedProblems as $problem) {
            if (! $problem instanceof UserSolvedProblem) {
                throw new InvalidArgumentException('최근 해결 문제 형식이 올바르지 않습니다.');
            }
        }
    }

    public function totalSubmissions(): int { return $this->totalSubmissions; }
    public function acceptedSubmissions(): int { return $this->acceptedSubmissions; }
    public function solvedProblems(): int { return $this->solvedProblems; }
    public function recentSubmissions(): int { return $this->recentSubmissions; }
    public function recentAcceptedSubmissions(): int { return $this->recentAcceptedSubmissions; }

    public function recentAccuracyPercent(): ?int
    {
        return $this->recentSubmissions === 0
            ? null
            : (int) round(
                ($this->recentAcceptedSubmissions / $this->recentSubmissions) * 100
            );
    }

    public function currentStreak(): int
    {
        $days = array_fill_keys(array_values(array_unique($this->acceptedDates)), true);
        $cursor = DateTimeImmutable::createFromFormat('!Y-m-d', $this->today);
        if (! $cursor instanceof DateTimeImmutable) {
            return 0;
        }
        if (! isset($days[$cursor->format('Y-m-d')])) {
            $cursor = $cursor->sub(new DateInterval('P1D'));
            if (! isset($days[$cursor->format('Y-m-d')])) {
                return 0;
            }
        }
        $streak = 0;
        while (isset($days[$cursor->format('Y-m-d')])) {
            ++$streak;
            $cursor = $cursor->sub(new DateInterval('P1D'));
        }

        return $streak;
    }

    /** @return list<UserSolvedProblem> */
    public function recentSolvedProblems(): array { return $this->recentSolvedProblems; }
}
