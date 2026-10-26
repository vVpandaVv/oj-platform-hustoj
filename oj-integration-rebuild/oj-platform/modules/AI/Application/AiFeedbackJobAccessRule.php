<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessContext;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessRule;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;

final class AiFeedbackJobAccessRule implements JobAccessRule
{
    public function __construct(private readonly Authorization $permissions)
    {
    }

    public function policyKeys(): array
    {
        return ['ai_feedback.submission.generate'];
    }

    public function policyVersion(): string
    {
        return '1';
    }

    public function canView(int $userId, JobAccessContext $job): bool
    {
        return $job->createdByUserId() === $userId
            || $this->permissions->can($userId, 'oj_manage_ai_feedback');
    }

    public function canControl(int $userId, JobAccessContext $job): bool
    {
        return $this->canView($userId, $job);
    }
}
