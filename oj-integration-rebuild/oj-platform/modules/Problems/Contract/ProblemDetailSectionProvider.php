<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

interface ProblemDetailSectionProvider
{
    public function key(): string;

    public function provide(
        int $problemId,
        int $userId,
        int $limit = 5
    ): ?ProblemDetailSection;
}
