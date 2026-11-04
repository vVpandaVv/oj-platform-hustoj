<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

/** Serialize one complete counter/expiry/index operation across requests. */
interface AttemptLimiterStorageLock
{
    public function synchronized(callable $operation): mixed;
}
