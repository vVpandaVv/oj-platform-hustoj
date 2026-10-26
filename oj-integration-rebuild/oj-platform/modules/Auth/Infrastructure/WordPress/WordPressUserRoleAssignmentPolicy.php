<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserRoleAssignmentPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;

final class WordPressUserRoleAssignmentPolicy implements UserRoleAssignmentPolicy
{
    public function allows(
        int $actorUserId,
        UserIdentity $target
    ): bool {
        if (
            $actorUserId < 1
            || $actorUserId === $target->userId()
            || ! user_can($actorUserId, 'oj_manage_roles')
        ) {
            return false;
        }

        if (
            function_exists('is_super_admin')
            && is_super_admin($target->userId())
            && ! is_super_admin($actorUserId)
        ) {
            return false;
        }

        $targetIsAdministrator = in_array(
            'administrator',
            $target->roles(),
            true
        );

        return ! $targetIsAdministrator
            || user_can($actorUserId, 'manage_options');
    }
}
