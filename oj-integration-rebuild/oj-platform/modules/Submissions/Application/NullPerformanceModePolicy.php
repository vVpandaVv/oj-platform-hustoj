<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;

final class NullPerformanceModePolicy implements PerformanceModePolicy
{
    public function restrictsOwnSubmissionDetails(int $userId): bool
    {
        unset($userId);

        return false;
    }
}
