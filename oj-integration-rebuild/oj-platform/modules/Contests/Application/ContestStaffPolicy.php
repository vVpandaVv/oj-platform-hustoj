<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Application;

use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestSettings;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;

final class ContestStaffPolicy
{
    public function __construct(
        private readonly ContestPlatformRepository $contests,
        private readonly Authorization $permissions
    ) {
    }

    public function canCreate(int $userId): bool
    {
        return $this->permissions->can($userId, 'oj_create_contests');
    }

    public function canOpenConsole(
        int $userId,
        int $contestId,
        ContestSettings $settings
    ): bool {
        return $this->isOwnerOrGlobal($userId, $settings)
            || $this->role($contestId, $userId) !== null;
    }

    public function canConfigure(
        int $userId,
        int $contestId,
        ContestSettings $settings
    ): bool {
        return $this->isOwnerOrGlobal($userId, $settings)
            || $this->role($contestId, $userId) === 'manager';
    }

    public function canChangeManagers(
        int $userId,
        ContestSettings $settings
    ): bool {
        return $this->isOwnerOrGlobal($userId, $settings);
    }

    public function canManageParticipants(
        int $userId,
        int $contestId,
        Contest $contest,
        ContestSettings $settings,
        string $now
    ): bool {
        if ($this->canConfigure($userId, $contestId, $settings)) {
            return true;
        }

        return $this->role($contestId, $userId) === 'proctor'
            && $contest->stage($now) === 'running';
    }

    public function canViewReports(
        int $userId,
        int $contestId,
        Contest $contest,
        ContestSettings $settings,
        string $now
    ): bool {
        if ($this->canConfigure($userId, $contestId, $settings)) {
            return true;
        }
        $stage = $contest->stage($now);
        $role = $this->role($contestId, $userId);

        return ($role === 'proctor' && $stage === 'running')
            || ($role === 'reviewer' && $stage === 'ended');
    }

    public function canExport(
        int $userId,
        int $contestId,
        Contest $contest,
        ContestSettings $settings,
        string $now
    ): bool {
        if ($this->canConfigure($userId, $contestId, $settings)) {
            return true;
        }

        return $this->role($contestId, $userId) === 'reviewer'
            && $contest->stage($now) === 'ended';
    }

    public function canArchive(
        int $userId,
        ContestSettings $settings
    ): bool {
        return $this->isOwnerOrGlobal($userId, $settings);
    }

    public function isOwnerOrGlobal(
        int $userId,
        ContestSettings $settings
    ): bool {
        return $this->isGlobalManager($userId)
            || $settings->ownerUserId() === $userId;
    }

    public function role(int $contestId, int $userId): ?string
    {
        return $this->contests->managerRoles($contestId)[$userId] ?? null;
    }

    public function isGlobalManager(int $userId): bool
    {
        return $this->permissions->isSuperAdmin($userId)
            || $this->permissions->can($userId, 'oj_manage_contests');
    }
}
