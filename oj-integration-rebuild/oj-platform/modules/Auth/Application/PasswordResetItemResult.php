<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountProtectionDiagnostic;

final class PasswordResetItemResult
{
    public function __construct(
        private readonly int $userId,
        private readonly bool $succeeded,
        private readonly ?string $temporaryPassword,
        private readonly string $resultCode,
        private readonly ?AccountProtectionDiagnostic $diagnostic = null
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

    public function diagnostic(): ?AccountProtectionDiagnostic
    {
        return $this->diagnostic;
    }
}
