<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizAttempt;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizRevision;

final class QuizAccessPolicy
{
    public function __construct(
        private readonly Authorization $permissions,
        private readonly ProblemAccessPolicy $problems
    ) {
    }

    public function canManage(int $userId, ProblemId $problemId): bool
    {
        return $this->permissions->can($userId, 'oj_manage_quizzes')
            && $this->problems->canEdit($userId, $problemId);
    }

    public function canRegradeCompleted(int $userId): bool
    {
        return $this->permissions->can($userId, 'oj_manage_quizzes')
            && $this->permissions->can($userId, 'oj_manage_problems');
    }

    public function canViewAttempt(
        int $userId,
        QuizAttempt $attempt
    ): bool {
        return $attempt->userId() === $userId
            || $this->canManage($userId, $attempt->problemId());
    }

    public function canViewStatistics(
        int $userId,
        QuizRevision $revision
    ): bool {
        return $revision->definition()->publicStatistics()
            || $this->canManage($userId, $revision->problemId());
    }
}
