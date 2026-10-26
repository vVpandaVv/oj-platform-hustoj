<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use CodingPanda\OjPlatform\Modules\Jobs\Domain\JobDetails;

interface RecentJobQuery
{
    /** @return list<JobDetails> */
    public function recent(int $limit = 32): array;
}
