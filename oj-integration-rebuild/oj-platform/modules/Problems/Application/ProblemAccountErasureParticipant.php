<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;

final class ProblemAccountErasureParticipant implements AccountErasureParticipant
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function moduleKey(): string
    {
        return 'problems';
    }

    public function blockers(int $userId): array
    {
        $count = (int) $this->database->value(
            'SELECT COUNT(*) FROM `problem_ownerships` WHERE `owner_user_id` = %d',
            [$userId]
        );

        return $count > 0
            ? [new AccountErasureBlocker('problems', 'owned_problems', '소유 문제', $count)]
            : [];
    }

    public function anonymize(
        int $userId,
        string $currentUserLogin,
        string $anonymousUserLogin
    ): void {
        unset($currentUserLogin, $anonymousUserLogin);
        $this->database->delete('problem_collaborators', ['user_id' => $userId], ['%d']);
        $this->database->delete('personal_problem_book_items', ['user_id' => $userId], ['%d']);
    }

    public function verify(int $userId, string $anonymousUserLogin): bool
    {
        unset($anonymousUserLogin);

        return (int) $this->database->value(
            'SELECT (SELECT COUNT(*) FROM `problem_ownerships` WHERE `owner_user_id` = %d)'
            . ' + (SELECT COUNT(*) FROM `problem_collaborators` WHERE `user_id` = %d)'
            . ' + (SELECT COUNT(*) FROM `personal_problem_book_items` WHERE `user_id` = %d)',
            [$userId, $userId, $userId]
        ) === 0;
    }
}
