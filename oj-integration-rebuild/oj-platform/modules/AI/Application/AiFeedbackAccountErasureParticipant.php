<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountErasureParticipant;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;

final class AiFeedbackAccountErasureParticipant implements AccountErasureParticipant
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function moduleKey(): string
    {
        return 'ai_feedback';
    }

    public function blockers(int $userId): array
    {
        unset($userId);

        return [];
    }

    public function anonymize(
        int $userId,
        string $currentUserLogin,
        string $anonymousUserLogin
    ): void {
        unset($currentUserLogin, $anonymousUserLogin);
        $this->database->delete('learner_level_profiles', ['user_id' => $userId], ['%d']);
    }

    public function verify(int $userId, string $anonymousUserLogin): bool
    {
        unset($anonymousUserLogin);

        return (int) $this->database->value(
            'SELECT COUNT(*) FROM `learner_level_profiles` WHERE `user_id` = %d',
            [$userId]
        ) === 0;
    }
}
