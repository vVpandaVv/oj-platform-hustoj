<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Ranking\Contract;

interface RankingSnapshotCache
{
    /**
     * @return list<array<string, mixed>>|null
     */
    public function get(string $key): ?array;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function put(string $key, array $rows, int $ttlSeconds): void;
}
