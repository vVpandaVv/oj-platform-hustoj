<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Domain;

final class GuardianConsentReview
{
    public function __construct(
        public readonly string $childEmail,
        public readonly string $guardianName,
        public readonly string $guardianEmail,
        public readonly AgreementDocument $document
    ) {
    }
}
