<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDraft;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemRankingEntry;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemSearch;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemStatistics;

interface ProblemGateway
{
    /**
     * @return list<JudgeProblem>
     */
    public function search(ProblemSearch $search): array;

    public function count(ProblemSearch $search): int;

    public function find(ProblemId $problemId): ?JudgeProblem;

    /**
     * @param list<ProblemId> $problemIds
     * @return list<JudgeProblem>
     */
    public function findMany(array $problemIds): array;

    public function nextId(): ProblemId;

    public function createNext(ProblemDraft $draft): ProblemId;

    public function create(ProblemId $problemId, ProblemDraft $draft): void;

    public function update(ProblemId $problemId, ProblemDraft $draft): void;

    public function archive(ProblemId $problemId, bool $archived): void;

    public function statistics(ProblemId $problemId): ProblemStatistics;

    /**
     * @return list<ProblemRankingEntry>
     */
    public function ranking(ProblemId $problemId, int $limit = 100): array;

    /**
     * @return list<string>
     */
    public function availableColumns(): array;
}
