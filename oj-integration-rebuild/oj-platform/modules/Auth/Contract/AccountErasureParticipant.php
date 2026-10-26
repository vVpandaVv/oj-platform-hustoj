<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface AccountErasureParticipant
{
    public function moduleKey(): string;

    /** @return list<AccountErasureBlocker> */
    public function blockers(int $userId): array;

    public function anonymize(
        int $userId,
        string $currentUserLogin,
        string $anonymousUserLogin
    ): void;

    public function verify(int $userId, string $anonymousUserLogin): bool;
}
