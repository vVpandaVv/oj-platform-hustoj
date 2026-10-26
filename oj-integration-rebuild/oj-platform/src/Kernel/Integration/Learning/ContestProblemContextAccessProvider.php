<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemContextAccessProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;

final class ContestProblemContextAccessProvider implements ProblemContextAccessProvider
{
    public function __construct(
        private readonly ContestSubmissionAccess $contests
    ) {
    }

    public function scope(): string
    {
        return 'contest';
    }

    public function canView(
        int $userId,
        int $problemId,
        ProblemAccessContext $context
    ): bool {
        $contestId = $context->contestId();

        return $contestId !== null
            && $this->contests->canViewProblem(
                $userId,
                $contestId,
                $problemId,
                $context->contestProblemNumber()
            );
    }
}
