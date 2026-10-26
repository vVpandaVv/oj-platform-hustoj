<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Ai;

use InvalidArgumentException;
use JsonException;

final class AiTaskAssignmentSnapshot
{
    public function __construct(
        private readonly string $taskType,
        private readonly int $routingRevision,
        private readonly string $primaryProfileKey,
        private readonly ?string $fallbackProfileKey,
        private readonly string $profileFingerprint
    ) {
        if (
            preg_match(
                '/^(?:[a-z][a-z0-9_]*|addon\.[a-z][a-z0-9_]{2,63}\.[a-z][a-z0-9_.-]*)$/D',
                $this->taskType
            ) !== 1
            || $this->routingRevision < 1
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $this->primaryProfileKey) !== 1
            || (
                $this->fallbackProfileKey !== null
                && (
                    preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $this->fallbackProfileKey) !== 1
                    || $this->fallbackProfileKey === $this->primaryProfileKey
                )
            )
            || preg_match('/^[a-f0-9]{64}$/', $this->profileFingerprint) !== 1
        ) {
            throw new InvalidArgumentException('AI 작업 배정 스냅샷이 올바르지 않습니다.');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $fallback = $value['fallback_profile_key'] ?? null;

        return new self(
            is_string($value['task_type'] ?? null) ? $value['task_type'] : '',
            is_int($value['routing_revision'] ?? null)
                ? $value['routing_revision']
                : 0,
            is_string($value['primary_profile_key'] ?? null)
                ? $value['primary_profile_key']
                : '',
            is_string($fallback) && $fallback !== '' ? $fallback : null,
            is_string($value['profile_fingerprint'] ?? null)
                ? $value['profile_fingerprint']
                : ''
        );
    }

    public function taskType(): string
    {
        return $this->taskType;
    }

    public function routingRevision(): int
    {
        return $this->routingRevision;
    }

    public function primaryProfileKey(): string
    {
        return $this->primaryProfileKey;
    }

    public function fallbackProfileKey(): ?string
    {
        return $this->fallbackProfileKey;
    }

    public function profileFingerprint(): string
    {
        return $this->profileFingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_type' => $this->taskType,
            'routing_revision' => $this->routingRevision,
            'primary_profile_key' => $this->primaryProfileKey,
            'fallback_profile_key' => $this->fallbackProfileKey,
            'profile_fingerprint' => $this->profileFingerprint,
        ];
    }

    public function hash(): string
    {
        try {
            return hash(
                'sha256',
                json_encode(
                    $this->toArray(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                )
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                'AI 작업 배정 스냅샷을 직렬화하지 못했습니다.',
                0,
                $error
            );
        }
    }
}
