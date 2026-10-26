<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Domain\GuardianVerificationChallenge;

interface GuardianConsentNotifier
{
    public function send(
        string $childEmail,
        string $guardianName,
        string $guardianEmail,
        GuardianVerificationChallenge $challenge
    ): void;
}
