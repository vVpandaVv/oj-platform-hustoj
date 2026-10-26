<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

final class GuardianConsentClaim
{
    public function __construct(
        private readonly bool $required,
        private readonly ?int $verificationId = null
    ) {
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function verificationId(): ?int
    {
        return $this->verificationId;
    }
}
