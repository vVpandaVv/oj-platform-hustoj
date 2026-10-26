<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Application;

use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;

final class ClassStaffPolicy
{
    public function __construct(
        private readonly ClassRepository $classes,
        private readonly Authorization $permissions
    ) {
    }

    public function canCreate(int $userId): bool
    {
        return $this->permissions->can($userId, 'oj_create_classes');
    }

    public function canManage(int $userId, LearningClass $class): bool
    {
        return $this->isGlobalManager($userId)
            || $class->ownerUserId() === $userId
            || $this->classes->isActiveInstructor($class->id(), $userId);
    }

    public function canChangeSettings(
        int $userId,
        LearningClass $class
    ): bool {
        return $this->isGlobalManager($userId)
            || $class->ownerUserId() === $userId;
    }

    public function canArchive(int $userId, LearningClass $class): bool
    {
        return $this->canChangeSettings($userId, $class);
    }

    public function isGlobalManager(int $userId): bool
    {
        return $this->permissions->isSuperAdmin($userId)
            || $this->permissions->can($userId, 'oj_manage_classes');
    }
}
