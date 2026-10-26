<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Ranking\Contract;

interface JudgeUserActivityProvider
{
    /**
     * Every HUSTOJ user with at least one solved problem, from the `users`
     * table's own `submit`/`solved` counters — not a scan of `solution`.
     *
     * @return list<JudgeUserActivity>
     */
    public function activeUsers(): array;
}
