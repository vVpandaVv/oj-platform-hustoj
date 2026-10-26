<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

interface JobWakeup
{
    /** Signal durable work without changing the enqueue outcome. */
    public function wake(): void;
}
