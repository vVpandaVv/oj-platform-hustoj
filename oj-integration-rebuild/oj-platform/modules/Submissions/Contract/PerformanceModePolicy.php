<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface PerformanceModePolicy
{
    public function restrictsOwnSubmissionDetails(int $userId): bool;
}
