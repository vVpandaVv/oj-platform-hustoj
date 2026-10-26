<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

use InvalidArgumentException;

final class AddonCodeExecutionQueryV1
{
    public function __construct(private readonly int $actorUserId, private readonly string $ticket)
    {
        if ($actorUserId < 1 || preg_match('/^[1-9][0-9]{0,18}\.ojv[a-f0-9]{12}_[a-f0-9]{16}\.[a-f0-9]{64}$/D', $ticket) !== 1) {
            throw new InvalidArgumentException('Invalid execution query.');
        }
    }
    public function actorUserId(): int { return $this->actorUserId; }
    public function ticket(): string { return $this->ticket; }
}
