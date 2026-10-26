<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface LegacyTransitionChallengeStore
{
    public function issue(
        string $candidateFingerprint,
        string $contextFingerprint
    ): string;

    public function consume(
        string $token,
        string $candidateFingerprint,
        string $contextFingerprint
    ): bool;
}
