<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemSearch;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;

final class ProblemCatalogService
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ProblemAssignmentRepository $assignments,
        private readonly ProblemAccessPolicy $access,
        private readonly Authorization $permissions
    ) {
    }

    /**
     * @return list<JudgeProblem>
     */
    public function publicPage(string $keyword, int $limit, int $offset): array
    {
        return $this->searchList(
            new ProblemSearch(
                $keyword,
                ProblemVisibility::Published,
                $limit,
                $offset
            )
        );
    }

    private function searchList(ProblemSearch $search): array
    {
        return $this->problems instanceof \CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemListGateway
            ? $this->problems->searchList($search) : $this->problems->search($search);
    }

    public function publicCount(string $keyword): int
    {
        return $this->problems->count(
            new ProblemSearch($keyword, ProblemVisibility::Published, 1)
        );
    }

    /**
     * @return list<JudgeProblem>
     */
    public function managedPage(
        int $userId,
        string $keyword,
        ?ProblemVisibility $visibility,
        int $limit,
        int $offset,
        ?bool $specialJudge = null
    ): array {
        $problemIds = $this->permissions->can($userId, 'oj_manage_problems')
            ? null
            : $this->assignments->problemIdsForUser($userId);

        return $this->searchList(
            new ProblemSearch(
                $keyword,
                $visibility,
                $limit,
                $offset,
                $problemIds,
                $specialJudge
            )
        );
    }

    public function managedCount(
        int $userId,
        string $keyword,
        ?ProblemVisibility $visibility,
        ?bool $specialJudge = null
    ): int {
        $problemIds = $this->permissions->can($userId, 'oj_manage_problems')
            ? null
            : $this->assignments->problemIdsForUser($userId);

        return $this->problems->count(
            new ProblemSearch(
                $keyword,
                $visibility,
                1,
                0,
                $problemIds,
                $specialJudge
            )
        );
    }

    public function findVisible(
        int $userId,
        ProblemId $problemId,
        ?ProblemAccessContext $context = null
    ): ?JudgeProblem
    {
        $problem = $this->problems->find($problemId);
        if (
            $problem === null
            || ! $this->access->canView(
                $userId,
                $problemId,
                $problem->visibility(),
                $context
            )
        ) {
            return null;
        }

        return $problem;
    }

    /**
     * @param list<ProblemId> $problemIds
     * @return array<int, JudgeProblem>
     */
    public function findVisibleMany(
        int $userId,
        array $problemIds,
        ?ProblemAccessContext $context = null
    ): array
    {
        $visible = [];
        foreach ($this->problems->findMany($problemIds) as $problem) {
            if ($this->access->canView(
                $userId,
                $problem->id(),
                $problem->visibility(),
                $context
            )) {
                $visible[$problem->id()->value()] = $problem;
            }
        }

        return $visible;
    }
    /** @param list<ProblemId> $problemIds @return array<int, JudgeProblem> */
    public function findManagedMany(int $userId, array $problemIds): array
    {
        $managed = [];
        foreach ($this->problems->findMany($problemIds) as $problem) {
            if ($this->access->canEdit($userId, $problem->id())) {
                $managed[$problem->id()->value()] = $problem;
            }
        }
        return $managed;
    }

    public function findManaged(int $userId, ProblemId $problemId): ?JudgeProblem
    {
        $problem = $this->problems->find($problemId);
        if ($problem === null || ! $this->access->canEdit($userId, $problemId)) {
            return null;
        }

        return $problem;
    }

    public function metadata(ProblemId $problemId): ?ProblemMetadata
    {
        return $this->metadata->find($problemId);
    }

    /**
     * @param list<ProblemId> $problemIds
     * @return array<int, ProblemMetadata>
     */
    public function metadataMany(array $problemIds): array
    {
        return $this->metadata->findMany($problemIds);
    }
}
