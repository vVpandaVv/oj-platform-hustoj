<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Application;

use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;

interface GroupRepository
{
    public function find(int $groupId): ?LearningGroup;

    /** @return list<LearningGroup> */
    public function visibleFor(int $userId): array;

    /** @return list<LearningGroup> */
    public function managedBy(int $userId, bool $all): array;

    /** @return list<int> */
    public function memberUserIds(int $groupId, string $status = 'active'): array;

    /** @return list<int> */
    public function managerUserIds(int $groupId): array;

    /** @return list<int> */
    public function activeGroupIdsForUser(int $userId): array;

    /** @return list<int> */
    public function managedGroupIdsForUser(int $userId): array;

    /** @return list<int> */
    public function activePerformanceGroupIdsForUser(int $userId): array;

    public function isActiveMember(int $groupId, int $userId): bool;

    public function membershipStatus(int $groupId, int $userId): ?string;

    public function isActiveManager(int $groupId, int $userId): bool;

    public function hasActivePerformanceGroup(int $userId): bool;

    public function saveAggregate(
        int $actorUserId,
        GroupSaveCommand $command,
        ?string $joinCodeHash
    ): int;

    public function setMembershipStatus(
        int $groupId,
        int $userId,
        string $status,
        int $actorUserId
    ): void;

    public function setManager(
        int $groupId,
        int $userId,
        bool $enabled,
        int $actorUserId
    ): void;

    public function setDeleted(int $groupId, bool $deleted, int $actorUserId): void;
}
