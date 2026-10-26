<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use InvalidArgumentException;

final class FormDraft
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly FormDraftReference $reference,
        private readonly string $draftType,
        private readonly int $ownerUserId,
        private readonly string $targetType,
        private readonly string $targetKey,
        private readonly array $payload,
        private readonly int $createdAt,
        private readonly int $expiresAt
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $this->draftType) !== 1
            || $this->ownerUserId < 1
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->targetType) !== 1
            || trim($this->targetKey) === ''
            || strlen($this->targetKey) > 191
            || $this->createdAt < 1
            || $this->expiresAt <= $this->createdAt
        ) {
            throw new InvalidArgumentException('Form Draft 내용이 올바르지 않습니다.');
        }
    }

    public function reference(): FormDraftReference
    {
        return $this->reference;
    }

    public function draftType(): string
    {
        return $this->draftType;
    }

    public function ownerUserId(): int
    {
        return $this->ownerUserId;
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    public function targetKey(): string
    {
        return $this->targetKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }
}
