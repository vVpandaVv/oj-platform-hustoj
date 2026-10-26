<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Ai;

use InvalidArgumentException;

final class AiTaskResponse
{
    /**
     * @param array<string, mixed> $output
     */
    public function __construct(
        private readonly array $output,
        private readonly string $profileKey,
        private readonly string $providerKey,
        private readonly string $modelKey,
        private readonly string $profileFingerprint
    ) {
        if (
            preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $this->profileKey) !== 1
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->providerKey) !== 1
            || trim($this->modelKey) === ''
            || strlen($this->modelKey) > 191
            || preg_match('/^[a-f0-9]{64}$/', $this->profileFingerprint) !== 1
        ) {
            throw new InvalidArgumentException('AI 작업 응답 정보가 올바르지 않습니다.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function output(): array
    {
        return $this->output;
    }

    public function profileKey(): string
    {
        return $this->profileKey;
    }

    public function providerKey(): string
    {
        return $this->providerKey;
    }

    public function modelKey(): string
    {
        return $this->modelKey;
    }

    public function profileFingerprint(): string
    {
        return $this->profileFingerprint;
    }
}
