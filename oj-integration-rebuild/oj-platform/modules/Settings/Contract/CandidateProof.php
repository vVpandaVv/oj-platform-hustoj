<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Contract;

use InvalidArgumentException;

final class CandidateProof
{
    /**
     * @param array<string, mixed> $safeResult
     */
    public function __construct(
        private readonly string $fingerprint,
        private readonly SecretReference $payloadReference,
        private readonly int $actorUserId,
        private readonly int $expiresAt,
        private readonly array $safeResult
    ) {
        if (
            preg_match('/^[a-f0-9]{64}$/', $this->fingerprint) !== 1
            || $this->actorUserId < 1
            || $this->expiresAt < 1
        ) {
            throw new InvalidArgumentException('후보 검사 증명이 올바르지 않습니다.');
        }
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function payloadReference(): SecretReference
    {
        return $this->payloadReference;
    }

    public function actorUserId(): int
    {
        return $this->actorUserId;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function safeResult(): array
    {
        return $this->safeResult;
    }
}
