<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

interface JobQueueReadiness
{
    public function hasReadyItems(): bool;
}
