<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;

interface JobResultJournal
{
    public function find(ClaimedJobItem $claim): ?JobHandlerResult;

    public function save(
        ClaimedJobItem $claim,
        JobHandlerResult $result
    ): void;
}
