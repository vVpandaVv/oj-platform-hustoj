<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;

interface ProblemContextAccessProvider
{
    public function scope(): string;

    public function canView(
        int $userId,
        int $problemId,
        ProblemAccessContext $context
    ): bool;
}
