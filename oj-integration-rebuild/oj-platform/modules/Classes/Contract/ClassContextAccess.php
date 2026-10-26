<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Contract;

use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;

/**
 * Narrow read-only surface used by the addon context authorization gateway.
 * Mirrors the existing ClassSubmissionAccess idiom for cross-module access.
 */
interface ClassContextAccess
{
    public function findById(int $classId): ?LearningClass;

    public function canManage(int $userId, LearningClass $class): bool;

    /** @return list<LearningClass> */
    public function managedBy(int $userId): array;

    /** @return list<LearningClass> */
    public function learningBy(int $userId): array;

    /** @return list<int> */
    public function activeLearnerUserIds(int $classId): array;

    public function isActiveParticipant(int $classId, int $userId): bool;

    public function isActiveLearner(int $classId, int $userId): bool;

    /** @return 'global_manager'|'owner'|'instructor'|'none' */
    public function relationOf(int $userId, LearningClass $class): string;
}
