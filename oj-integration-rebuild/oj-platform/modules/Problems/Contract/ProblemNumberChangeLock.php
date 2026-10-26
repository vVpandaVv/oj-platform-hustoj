<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

interface ProblemNumberChangeLock
{
    /**
     * @param list<int> $problemIds
     */
    public function withExclusiveLock(array $problemIds, callable $operation): mixed;

    public function hasActiveChange(): bool;

    public function isLocked(ProblemId $problemId): bool;
}
