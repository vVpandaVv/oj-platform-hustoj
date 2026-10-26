<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

use InvalidArgumentException;

final class SubmissionSummary
{
    public function __construct(
        private readonly SubmissionId $submissionId,
        private readonly int $problemId,
        private readonly string $userLogin,
        private readonly string $nickname,
        private readonly int $languageId,
        private readonly int $resultCode,
        private readonly int $timeMilliseconds,
        private readonly int $memoryKilobytes,
        private readonly string $submittedAt,
        private readonly string $judgedAt,
        private readonly int $contestId,
        private readonly int $contestProblemNumber,
        private readonly ?int $classId,
        private readonly ?int $groupId,
        private readonly bool $valid,
        private readonly float $passRate = 0.0
    ) {
        if ($this->problemId < 1 || $this->languageId < 0) {
            throw new InvalidArgumentException('제출 문제 또는 언어 번호가 올바르지 않습니다.');
        }
        if (trim($this->userLogin) === '') {
            throw new InvalidArgumentException('제출 사용자 로그인 아이디가 필요합니다.');
        }
        if ($this->timeMilliseconds < 0 || $this->memoryKilobytes < 0) {
            throw new InvalidArgumentException('채점 시간과 메모리는 음수일 수 없습니다.');
        }
    }

    public function submissionId(): SubmissionId
    {
        return $this->submissionId;
    }

    public function problemId(): int
    {
        return $this->problemId;
    }

    public function userLogin(): string
    {
        return $this->userLogin;
    }

    public function nickname(): string
    {
        return $this->nickname;
    }

    public function languageId(): int
    {
        return $this->languageId;
    }

    public function resultCode(): int
    {
        return $this->resultCode;
    }

    public function timeMilliseconds(): int
    {
        return $this->timeMilliseconds;
    }

    public function memoryKilobytes(): int
    {
        return $this->memoryKilobytes;
    }

    public function submittedAt(): string
    {
        return $this->submittedAt;
    }

    public function judgedAt(): string
    {
        return $this->judgedAt;
    }

    public function contestId(): int
    {
        return $this->contestId;
    }

    public function contestProblemNumber(): int
    {
        return $this->contestProblemNumber;
    }

    public function classId(): ?int
    {
        return $this->classId;
    }

    public function groupId(): ?int
    {
        return $this->groupId;
    }

    public function withContext(?int $classId, ?int $groupId): self
    {
        return new self(
            $this->submissionId,
            $this->problemId,
            $this->userLogin,
            $this->nickname,
            $this->languageId,
            $this->resultCode,
            $this->timeMilliseconds,
            $this->memoryKilobytes,
            $this->submittedAt,
            $this->judgedAt,
            $this->contestId,
            $this->contestProblemNumber,
            $classId,
            $groupId,
            $this->valid,
            $this->passRate
        );
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function passRate(): float
    {
        return max(0.0, min(1.0, $this->passRate));
    }

    public function belongsTo(string $userLogin): bool
    {
        return hash_equals($this->userLogin, $userLogin);
    }
}
