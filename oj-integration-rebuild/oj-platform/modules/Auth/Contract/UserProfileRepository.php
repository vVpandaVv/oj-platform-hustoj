<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationRequirements;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;

interface UserProfileRepository
{
    public function find(int $userId): ?UserProfile;

    public function create(UserProfile $profile): void;

    public function save(UserProfile $profile, ?int $changedByUserId = null): void;

    public function recordLogin(int $userId): void;

    public function saveRegistrationRequirements(
        int $userId,
        RegistrationRequirements $requirements
    ): void;

    public function registrationRequirements(
        int $userId
    ): ?RegistrationRequirements;

    /**
     * @return list<UserProfile>
     */
    public function findPage(int $limit, int $offset = 0): array;

    /**
     * @return list<UserProfile>
     */
    public function findByStatus(AccessStatus $status, int $limit, int $offset = 0): array;

    /**
     * @param list<int>|null $userIds null이면 id 제한 없음
     * @return list<UserProfile>
     */
    public function findFiltered(
        ?array $userIds,
        ?AccessStatus $status,
        int $limit,
        int $offset = 0
    ): array;

    /**
     * @param list<int>|null $userIds null이면 id 제한 없음
     */
    public function countFiltered(?array $userIds, ?AccessStatus $status): int;
}
