<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\PasswordResetTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;

final class WordPressPasswordResetTargetPolicy implements PasswordResetTargetPolicy
{
    public function __construct(
        private readonly WordPressPrivilegedAccountDetector $privilegedAccounts = new WordPressPrivilegedAccountDetector()
    ) {
    }

    public function allows(
        int $actorUserId,
        UserIdentity $target,
        UserProfile $profile
    ): bool {
        unset($profile);

        if (
            ! user_can($actorUserId, 'oj_edit_users')
            || ! user_can($actorUserId, 'edit_user', $target->userId())
        ) {
            return false;
        }

        return ! $this->protectsTarget($target);
    }

    public function protectsTarget(UserIdentity $target): bool
    {
        return $this->privilegedAccounts->isPrivileged($target);
    }
}
