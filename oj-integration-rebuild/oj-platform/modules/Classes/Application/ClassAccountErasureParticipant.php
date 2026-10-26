<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureBlocker;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;

final class ClassAccountErasureParticipant implements AccountErasureParticipant
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function moduleKey(): string
    {
        return 'classes';
    }

    public function blockers(int $userId): array
    {
        $count = (int) $this->database->value(
            'SELECT COUNT(*) FROM `classes` WHERE `owner_user_id` = %d AND `deleted_at` IS NULL',
            [$userId]
        );

        return $count > 0
            ? [new AccountErasureBlocker('classes', 'owned_classes', '소유 수업', $count)]
            : [];
    }

    public function anonymize(
        int $userId,
        string $currentUserLogin,
        string $anonymousUserLogin
    ): void {
        unset($currentUserLogin, $anonymousUserLogin);
        $this->database->delete('class_instructors', ['user_id' => $userId], ['%d']);
        $this->database->delete('class_enrollments', ['user_id' => $userId], ['%d']);
    }

    public function verify(int $userId, string $anonymousUserLogin): bool
    {
        unset($anonymousUserLogin);

        return (int) $this->database->value(
            'SELECT (SELECT COUNT(*) FROM `classes` WHERE `owner_user_id` = %d AND `deleted_at` IS NULL)'
            . ' + (SELECT COUNT(*) FROM `class_instructors` WHERE `user_id` = %d)'
            . ' + (SELECT COUNT(*) FROM `class_enrollments` WHERE `user_id` = %d)',
            [$userId, $userId, $userId]
        ) === 0;
    }
}
