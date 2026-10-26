<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessContext;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessRule;

final class GroupBulkMemberImportJobAccessRule implements JobAccessRule
{
    public function __construct(private readonly GroupService $groups)
    {
    }

    public function policyKeys(): array
    {
        return ['groups.member_csv_import'];
    }

    public function policyVersion(): string
    {
        return '1';
    }

    public function canView(int $userId, JobAccessContext $job): bool
    {
        $groupId = $job->targetSummary()['group_id'] ?? null;

        return is_int($groupId)
            && $this->groups->canManageGroup($userId, $groupId);
    }

    public function canControl(int $userId, JobAccessContext $job): bool
    {
        return $this->canView($userId, $job);
    }
}
