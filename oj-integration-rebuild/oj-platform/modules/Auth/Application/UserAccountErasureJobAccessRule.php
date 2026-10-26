<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessContext;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessRule;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;

final class UserAccountErasureJobAccessRule implements JobAccessRule
{
    public function __construct(private readonly Authorization $permissions)
    {
    }

    public function policyKeys(): array
    {
        return ['auth.user_account_erasure'];
    }

    public function policyVersion(): string
    {
        return '1';
    }

    public function canView(int $userId, JobAccessContext $job): bool
    {
        return $job->createdByUserId() === $userId
            || $this->permissions->can($userId, 'oj_view_deleted_users');
    }

    public function canControl(int $userId, JobAccessContext $job): bool
    {
        return $job->createdByUserId() === $userId
            || $this->permissions->can($userId, 'oj_view_deleted_users');
    }
}
