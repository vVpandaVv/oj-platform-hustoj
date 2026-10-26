<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Contract;

use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;

/**
 * Narrow read-only surface used by the addon context authorization gateway.
 * Mirrors the existing GroupSubmissionAccess idiom for cross-module access.
 */
interface GroupContextAccess
{
    public function findById(int $groupId): ?LearningGroup;

    public function canManage(int $userId, LearningGroup $group): bool;

    /** @return list<LearningGroup> */
    public function managedBy(int $userId): array;

    /** @return list<LearningGroup> */
    public function learningBy(int $userId): array;

    /** @return list<int> */
    public function activeLearnerUserIds(int $groupId): array;

    public function isActiveMember(int $groupId, int $userId): bool;

    /** @return 'global_manager'|'owner'|'manager'|'none' */
    public function relationOf(int $userId, LearningGroup $group): string;
}
