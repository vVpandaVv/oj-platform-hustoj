<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

use InvalidArgumentException;

final class GuardianVerificationChallenge
{
    public function __construct(
        private readonly int $verificationId,
        private readonly string $token,
        private readonly string $code,
        private readonly \DateTimeImmutable $expiresAt
    ) {
        if (
            $this->verificationId < 1
            || preg_match('/^[a-f0-9]{64}$/', $this->token) !== 1
            || preg_match('/^\d{8}$/', $this->code) !== 1
        ) {
            throw new InvalidArgumentException('법정대리인 확인 발급 정보가 올바르지 않습니다.');
        }
    }

    public function verificationId(): int
    {
        return $this->verificationId;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
