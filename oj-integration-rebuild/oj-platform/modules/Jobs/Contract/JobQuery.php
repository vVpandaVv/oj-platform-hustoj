<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

interface JobQuery
{
    public function find(int $jobId): ?JobView;
}
