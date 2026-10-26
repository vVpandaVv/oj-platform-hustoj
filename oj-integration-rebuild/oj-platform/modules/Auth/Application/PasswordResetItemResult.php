<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

final class PasswordResetItemResult
{
    public function __construct(
        private readonly int $userId,
        private readonly bool $succeeded,
        private readonly ?string $temporaryPassword,
        private readonly string $resultCode
    ) {
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function succeeded(): bool
    {
        return $this->succeeded;
    }

    public function temporaryPassword(): ?string
    {
        return $this->temporaryPassword;
    }

    public function resultCode(): string
    {
        return $this->resultCode;
    }
}
