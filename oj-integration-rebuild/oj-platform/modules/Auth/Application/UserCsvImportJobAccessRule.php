<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessRule;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessContext;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;

final class UserCsvImportJobAccessRule implements JobAccessRule
{
    public function __construct(
        private readonly Authorization $permissions
    ) {
    }

    public function policyKeys(): array
    {
        return ['auth.user_csv_import'];
    }

    public function policyVersion(): string
    {
        return '1';
    }

    public function canView(int $userId, JobAccessContext $job): bool
    {
        unset($job);

        return $this->permissions->can($userId, 'oj_create_users');
    }

    public function canControl(int $userId, JobAccessContext $job): bool
    {
        return $this->canView($userId, $job);
    }
}
