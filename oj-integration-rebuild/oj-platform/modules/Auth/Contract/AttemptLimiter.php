<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface AttemptLimiter
{
    public function consume(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds
    ): bool;

    /**
     * Clears every outstanding rate-limit counter across all scopes
     * (login, lost password, registration, guardian consent, ...) so an
     * administrator can unblock someone without waiting out the window.
     *
     * @return int number of counters actually cleared
     */
    public function resetAll(): int;
}
