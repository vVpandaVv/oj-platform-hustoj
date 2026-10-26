<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface UserManagementTargetPolicy
{
    public function allows(int $actorUserId, UserIdentity $target, string $capability = 'oj_edit_users'): bool;
}
