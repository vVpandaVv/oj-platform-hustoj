<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Migration\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessContext;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessRule;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;

final class MigrationJobAccessRule implements JobAccessRule
{
    public function __construct(private readonly Authorization $permissions)
    {
    }

    public function policyKeys(): array
    {
        return ['migration.execute'];
    }

    public function policyVersion(): string
    {
        return '1';
    }

    public function canView(int $userId, JobAccessContext $job): bool
    {
        return $this->isAllowed($userId, $job);
    }

    public function canControl(int $userId, JobAccessContext $job): bool
    {
        return $this->isAllowed($userId, $job);
    }

    private function isAllowed(int $userId, JobAccessContext $job): bool
    {
        $summary = $job->targetSummary();
        $hash = $summary['plan_hash'] ?? null;
        $stage = $summary['stage'] ?? null;
        $wave = $summary['wave'] ?? null;

        return $this->permissions->can($userId, 'oj_manage_migration')
            && is_string($hash)
            && preg_match('/^[a-f0-9]{64}$/', $hash) === 1
            && (is_int($stage) || (is_string($stage) && ctype_digit($stage)))
            && is_string($wave)
            && preg_match('/^[a-z][a-z0-9_]*$/', $wave) === 1;
    }
}

