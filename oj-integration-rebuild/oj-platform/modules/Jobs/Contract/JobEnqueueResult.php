<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Contract;

use InvalidArgumentException;

final class JobEnqueueResult
{
    public function __construct(
        private readonly int $jobId,
        private readonly bool $created
    ) {
        if ($this->jobId < 1) {
            throw new InvalidArgumentException('Jobs 식별자가 올바르지 않습니다.');
        }
    }

    public function jobId(): int
    {
        return $this->jobId;
    }

    public function wasCreated(): bool
    {
        return $this->created;
    }
}
