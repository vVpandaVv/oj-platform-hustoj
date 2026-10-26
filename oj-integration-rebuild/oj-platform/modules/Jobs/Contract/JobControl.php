<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobControlResult;

interface JobControl
{
    public function requestCancellation(
        int $jobId,
        int $actorUserId
    ): JobControlResult;

    public function retryFailed(
        int $jobId,
        int $actorUserId
    ): JobControlResult;
}
