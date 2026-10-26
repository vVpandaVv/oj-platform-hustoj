<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

interface JobDispatcher
{
    /**
     * @param list<JobTarget> $targets
     * @param array<string, mixed> $inputSummary
     * @param array<string, mixed> $targetSummary
     */
    public function enqueue(
        string $jobType,
        string $executionMode,
        int $actorUserId,
        array $targets,
        array $inputSummary,
        array $targetSummary,
        string $accessPolicyKey,
        string $accessPolicyVersion = '1',
        string $inputVersion = '1'
    ): JobEnqueueResult;
}
