<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Domain;

use InvalidArgumentException;

final class PreparedSubmission
{
    public function __construct(
        private readonly int $problemId,
        private readonly string $userLogin,
        private readonly string $nickname,
        private readonly int $languageId,
        private readonly string $ipAddress,
        private readonly int $codeLength,
        private readonly int $contestId = 0,
        private readonly int $contestProblemNumber = -1
    ) {
        if (
            $this->problemId < 1
            || trim($this->userLogin) === ''
            || $this->languageId < 0
            || $this->codeLength < 1
        ) {
            throw new InvalidArgumentException('준비할 제출 정보가 올바르지 않습니다.');
        }
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

    public function ipAddress(): string
    {
        return $this->ipAddress;
    }

    public function codeLength(): int
    {
        return $this->codeLength;
    }

    public function contestId(): int
    {
        return $this->contestId;
    }

    public function contestProblemNumber(): int
    {
        return $this->contestProblemNumber;
    }

}
