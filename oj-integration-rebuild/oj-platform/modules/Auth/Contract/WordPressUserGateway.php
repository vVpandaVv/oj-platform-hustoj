<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;

interface WordPressUserGateway
{
    public function currentUser(): ?UserIdentity;

    public function findById(int $userId): ?UserIdentity;

    public function findByLogin(string $userLogin): ?UserIdentity;

    public function findByEmail(string $email): ?UserIdentity;

    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity;

    public function establishSession(int $userId, bool $remember): void;

    public function createUser(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        string $role
    ): UserIdentity;

    /** Updates public identity fields without changing the administrator-managed nickname. */
    public function updateUser(
        int $userId,
        string $email,
        string $displayName
    ): UserIdentity;

    /**
     * @param list<string> $roles
     */
    public function setRoles(int $userId, array $roles): void;

    public function setPassword(int $userId, string $password): void;

    public function invalidateSessions(int $userId): void;

    public function requestPasswordReset(string $userLogin): void;

    public function resetPasswordWithKey(
        string $userLogin,
        string $resetKey,
        string $newPassword
    ): void;

    public function deleteUser(int $userId): void;

    /**
     * @param list<int> $userIds
     * @return list<UserIdentity>
     */
    public function findMany(array $userIds): array;
}
