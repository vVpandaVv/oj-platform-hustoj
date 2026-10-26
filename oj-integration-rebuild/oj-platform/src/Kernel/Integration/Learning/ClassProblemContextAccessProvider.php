<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemContextAccessProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;

final class ClassProblemContextAccessProvider implements ProblemContextAccessProvider
{
    public function __construct(
        private readonly ClassSubmissionAccess $classes
    ) {
    }

    public function scope(): string
    {
        return 'class';
    }

    public function canView(
        int $userId,
        int $problemId,
        ProblemAccessContext $context
    ): bool {
        $classId = $context->classId();

        return $classId !== null
            && $this->classes->canViewProblem($userId, $classId, $problemId);
    }
}
