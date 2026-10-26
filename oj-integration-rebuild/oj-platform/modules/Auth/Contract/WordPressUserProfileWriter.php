<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface WordPressUserProfileWriter
{
    public function updateProfileIdentity(
        int $userId,
        string $email,
        string $displayName,
        string $nickname
    ): UserIdentity;
}
