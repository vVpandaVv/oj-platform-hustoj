<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Contract;

interface ContestHomeRepository
{
    /** @return list<\CodingPanda\OjPlatform\Modules\Contests\Domain\Contest> */
    public function homeCandidates(string $now, ?int $beforeId, int $limit): array;
}
