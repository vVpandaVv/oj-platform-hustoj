<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Ranking\Contract;

interface RankingRefreshCache
{
    public function acquireRefresh(string $key): bool;
    public function releaseRefresh(string $key): void;
    /** @return list<array<string,mixed>>|null */
    public function stale(string $key): ?array;
}
