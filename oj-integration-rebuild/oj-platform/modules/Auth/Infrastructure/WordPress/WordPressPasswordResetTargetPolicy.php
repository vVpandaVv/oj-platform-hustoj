<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Auth\Contract\PasswordResetTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\PasswordResetTargetDiagnostics;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountProtectionDiagnostic;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;

final class WordPressPasswordResetTargetPolicy implements PasswordResetTargetPolicy, PasswordResetTargetDiagnostics
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
            $actorUserId < 1
            || ! function_exists('user_can')
            || ! user_can($actorUserId, 'oj_edit_users')
        ) {
            return false;
        }

        // OJ user managers can manage ordinary learners without WordPress edit_users.
        // Privileged targets remain excluded even for WordPress administrators.
        return ! $this->protectsTarget($target);
    }

    public function protectsTarget(UserIdentity $target): bool
    {
        return $this->inspectTarget($target)->isProtected();
    }

    public function inspectTarget(UserIdentity $target): AccountProtectionDiagnostic
    {
        return $this->privilegedAccounts->inspect($target);
    }
}
