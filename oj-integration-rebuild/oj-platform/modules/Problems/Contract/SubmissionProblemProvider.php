<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;

interface SubmissionProblemProvider
{
    public function findVisible(
        int $userId,
        int $problemId,
        ?ProblemAccessContext $context = null
    ): ?SubmissionProblem;

    /**
     * @param list<int> $problemIds
     * @return array<int, SubmissionProblem>
     */
    public function findVisibleMany(
        int $userId,
        array $problemIds,
        ?ProblemAccessContext $context = null
    ): array;

    /**
     * @return list<SubmissionProblem>
     */
    public function listVisible(string $keyword = '', int $limit = 100): array;

    public function codePolicy(
        int $userId,
        int $problemId,
        ?ProblemAccessContext $context = null
    ): SubmissionCodePolicy;
}
