<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Application;

use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassProblem;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;

interface ClassRepository
{
    public function find(int $classId): ?LearningClass;

    /** @return list<LearningClass> */
    public function visibleFor(int $userId): array;

    /** @return list<LearningClass> */
    public function invitedFor(int $userId): array;

    /**
     * @param list<int> $groupIds
     * @return list<LearningClass>
     */
    public function linkedToGroups(array $groupIds): array;

    /** @return list<LearningClass> */
    public function managedBy(int $userId, bool $all): array;

    /** @return list<ClassProblem> */
    public function problems(int $classId): array;

    /** @return list<int> */
    public function participantUserIds(int $classId, string $status = 'active'): array;

    /**
     * @return array<int, string> user_id => enrollment approval timestamp (Y-m-d H:i:s)
     */
    public function participantApprovedAtMap(int $classId): array;

    /** @return list<int> */
    public function instructorUserIds(int $classId): array;

    /** @return list<int> */
    public function groupIds(int $classId): array;

    public function isActiveParticipant(int $classId, int $userId): bool;
    public function enrollmentStatus(int $classId, int $userId): ?string;


    public function isActiveInstructor(int $classId, int $userId): bool;

    public function saveAggregate(
        int $actorUserId,
        ClassSaveCommand $command,
        ?string $passwordHash
    ): int;

    public function setEnrollmentStatus(
        int $classId,
        int $userId,
        string $status,
        int $actorUserId
    ): void;

    public function transitionEnrollmentStatus(
        int $classId,
        int $userId,
        ?string $expectedStatus,
        string $status,
        int $actorUserId
    ): bool;

    public function setDeleted(int $classId, bool $deleted, int $actorUserId): void;
}
