<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;

final class GroupAccountErasureParticipant implements AccountErasureParticipant
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function moduleKey(): string
    {
        return 'groups';
    }

    public function blockers(int $userId): array
    {
        $count = (int) $this->database->value(
            'SELECT COUNT(*) FROM `learning_groups` WHERE `owner_user_id` = %d AND `deleted_at` IS NULL',
            [$userId]
        );

        return $count > 0
            ? [new AccountErasureBlocker('groups', 'owned_groups', '소유 그룹', $count)]
            : [];
    }

    public function anonymize(
        int $userId,
        string $currentUserLogin,
        string $anonymousUserLogin
    ): void {
        unset($currentUserLogin, $anonymousUserLogin);
        $this->database->delete('group_managers', ['user_id' => $userId], ['%d']);
        $this->database->delete('group_memberships', ['user_id' => $userId], ['%d']);
    }

    public function verify(int $userId, string $anonymousUserLogin): bool
    {
        unset($anonymousUserLogin);

        return (int) $this->database->value(
            'SELECT (SELECT COUNT(*) FROM `learning_groups` WHERE `owner_user_id` = %d AND `deleted_at` IS NULL)'
            . ' + (SELECT COUNT(*) FROM `group_managers` WHERE `user_id` = %d)'
            . ' + (SELECT COUNT(*) FROM `group_memberships` WHERE `user_id` = %d)',
            [$userId, $userId, $userId]
        ) === 0;
    }
}
