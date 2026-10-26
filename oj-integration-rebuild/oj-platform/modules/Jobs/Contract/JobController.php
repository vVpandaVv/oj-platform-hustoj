<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobControlResult;

interface JobController
{
    public function cancel(int $actorUserId, int $jobId): JobControlResult;

    public function retryFailed(
        int $actorUserId,
        int $jobId
    ): JobControlResult;
}
