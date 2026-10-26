<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;

final class JobAccessPolicy
{
    public function __construct(
        private readonly JobAccessRuleRegistry $rules
    ) {
    }

    public function canView(int $userId, JobDetails $job): bool
    {
        if ($userId < 1) {
            return false;
        }
        $rule = $this->rules->find(
            $job->accessPolicyKey(),
            $job->accessPolicyVersion()
        );

        return $rule !== null && $rule->canView($userId, $job);
    }

    public function canControl(int $userId, JobDetails $job): bool
    {
        if ($userId < 1) {
            return false;
        }
        $rule = $this->rules->find(
            $job->accessPolicyKey(),
            $job->accessPolicyVersion()
        );

        return $rule !== null && $rule->canControl($userId, $job);
    }
}
