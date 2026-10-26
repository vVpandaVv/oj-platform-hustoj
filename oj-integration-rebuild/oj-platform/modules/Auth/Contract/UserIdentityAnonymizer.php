<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface UserIdentityAnonymizer
{
    public function anonymousLogin(int $userId): string;

    public function anonymize(int $userId): void;

    public function isAnonymized(int $userId): bool;
}
