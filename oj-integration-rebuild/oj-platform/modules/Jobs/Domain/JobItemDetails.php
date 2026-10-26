<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Domain;

final class JobItemDetails
{
    /**
     * @param array<string, mixed> $safeResult
     */
    public function __construct(
        private readonly int $id,
        private readonly string $targetType,
        private readonly string $targetKey,
        private readonly string $status,
        private readonly int $attemptCount,
        private readonly int $maxAttempts,
        private readonly ?string $resultCode,
        private readonly ?string $resultMessage,
        private readonly array $safeResult
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    public function targetKey(): string
    {
        return $this->targetKey;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function resultCode(): ?string
    {
        return $this->resultCode;
    }

    public function resultMessage(): ?string
    {
        return $this->resultMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function safeResult(): array
    {
        return $this->safeResult;
    }
}
