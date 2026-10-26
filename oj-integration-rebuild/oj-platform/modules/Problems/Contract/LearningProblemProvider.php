<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

interface LearningProblemProvider
{
    public function findForUser(
        int $userId,
        int $problemId
    ): ?LearningProblem;

    /**
     * @param list<int> $problemIds
     * @return array<int, LearningProblem>
     */
    public function findManyForUser(int $userId, array $problemIds): array;

    /**
     * @return list<LearningProblem>
     */
    public function catalogForUser(int $userId, int $limit = 500): array;

    /**
     * @return list<LearningProblem>
     */
    public function recentForUser(int $userId, int $limit = 5): array;

    /**
     * @return list<LearningProblem>
     */
    public function publicCatalog(int $limit = 500): array;

    public function catalogRevision(): string;
}
