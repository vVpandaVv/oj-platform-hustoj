<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;

final class ContestAccountErasureParticipant implements AccountErasureParticipant
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function moduleKey(): string
    {
        return 'contests';
    }

    public function blockers(int $userId): array
    {
        $count = (int) $this->database->value(
            'SELECT COUNT(*) FROM `contest_settings` WHERE `owner_user_id` = %d',
            [$userId]
        );

        return $count > 0
            ? [new AccountErasureBlocker('contests', 'owned_contests', '소유 대회·평가', $count)]
            : [];
    }

    public function anonymize(
        int $userId,
        string $currentUserLogin,
        string $anonymousUserLogin
    ): void {
        unset($currentUserLogin, $anonymousUserLogin);
        $this->database->delete('contest_managers', ['user_id' => $userId], ['%d']);
        $this->database->delete('contest_ip_logs', ['user_id' => $userId], ['%d']);
        $this->database->update(
            'contest_participants',
            [
                'status' => 'withdrawn',
                'allowed_ip' => null,
                'withdrawn_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['user_id' => $userId],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    public function verify(int $userId, string $anonymousUserLogin): bool
    {
        unset($anonymousUserLogin);

        return (int) $this->database->value(
            'SELECT (SELECT COUNT(*) FROM `contest_settings` WHERE `owner_user_id` = %d)'
            . ' + (SELECT COUNT(*) FROM `contest_managers` WHERE `user_id` = %d)'
            . ' + (SELECT COUNT(*) FROM `contest_ip_logs` WHERE `user_id` = %d)'
            . ' + (SELECT COUNT(*) FROM `contest_participants` WHERE `user_id` = %d'
            . " AND (`status` <> 'withdrawn' OR `allowed_ip` IS NOT NULL))",
            [$userId, $userId, $userId, $userId]
        ) === 0;
    }
}
