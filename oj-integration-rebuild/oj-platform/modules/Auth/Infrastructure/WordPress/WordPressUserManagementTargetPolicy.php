<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserManagementTargetPolicy;
use Throwable;

final class WordPressUserManagementTargetPolicy implements UserManagementTargetPolicy
{
    public function __construct(
        private readonly WordPressPrivilegedAccountDetector $privilegedAccounts = new WordPressPrivilegedAccountDetector()
    ) {
    }

    public function allows(int $actorUserId, UserIdentity $target, string $capability = 'oj_edit_users'): bool
    {
        if ($actorUserId < 1
            || ! in_array($capability, ['oj_edit_users', 'oj_deactivate_users', 'oj_approve_user_registrations'], true)
            || ! function_exists('user_can') || ! function_exists('get_user_by')) {
            return false;
        }

        try {
            if (! user_can($actorUserId, $capability)) {
                return false;
            }
            $user = get_user_by('id', $target->userId());
            if (! is_object($user) || (int) ($user->ID ?? 0) !== $target->userId()
                || ! is_array($user->allcaps ?? null)) {
                return false;
            }

            return ! $this->privilegedAccounts->isPrivileged($target)
                || user_can($actorUserId, 'edit_user', $target->userId());
        } catch (Throwable) {
            return false;
        }
    }
}
