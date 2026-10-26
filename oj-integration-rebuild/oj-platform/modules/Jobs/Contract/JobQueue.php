<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobExecutionPlan;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobItemPlan;

interface JobQueue
{
    /**
     * @param list<JobItemPlan> $items
     */
    public function enqueue(
        JobExecutionPlan $plan,
        array $items
    ): JobEnqueueResult;

    public function claim(
        string $workerId,
        int $leaseSeconds
    ): ?ClaimedJobItem;

    public function finish(
        ClaimedJobItem $claim,
        JobHandlerResult $result
    ): void;
}
