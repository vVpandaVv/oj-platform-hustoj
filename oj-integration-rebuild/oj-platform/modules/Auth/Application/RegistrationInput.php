<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

final class RegistrationInput
{
    /**
     * @param list<int> $agreementDocumentIds
     */
    public function __construct(
        public readonly string $userLogin,
        public readonly string $email,
        public readonly string $password,
        public readonly string $displayName,
        public readonly ?string $affiliation,
        public readonly ?string $birthDate,
        public readonly ?string $guardianCode,
        public readonly array $agreementDocumentIds,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent
    ) {
    }
}
