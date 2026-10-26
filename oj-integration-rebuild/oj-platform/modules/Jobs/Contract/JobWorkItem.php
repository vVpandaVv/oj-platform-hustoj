<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

final class JobWorkItem
{
    public function __construct(
        private readonly ClaimedJobItem $claim,
        private readonly mixed $payload
    ) {
    }

    public function claim(): ClaimedJobItem
    {
        return $this->claim;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }
}
