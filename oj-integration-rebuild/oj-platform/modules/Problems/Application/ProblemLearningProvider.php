<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemMetadataRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemSearch;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use JsonException;

final class ProblemLearningProvider implements LearningProblemProvider
{
    /**
     * @var list<LearningProblem>|null
     */
    private ?array $publishedCache = null;

    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemMetadataRepository $metadata,
        private readonly ProblemAccessPolicy $access
    ) {
    }

    public function findForUser(
        int $userId,
        int $problemId
    ): ?LearningProblem {
        if ($userId < 1 || $problemId < 1) {
            return null;
        }
        $problem = $this->problems->find(new ProblemId($problemId));
        if (
            $problem === null
            || ! $this->access->canView(
                $userId,
                $problem->id(),
                $problem->visibility()
            )
        ) {
            return null;
        }

        return $this->project(
            $problem,
            $this->metadata->find($problem->id())
        );
    }

    public function findManyForUser(int $userId, array $problemIds): array
    {
        if ($userId < 1) {
            return [];
        }
        $problemIds = array_values(array_unique(array_filter(
            array_map('intval', $problemIds),
            static fn (int $problemId): bool => $problemId > 0
        )));
        if ($problemIds === []) {
            return [];
        }
        $visible = [];
        foreach ($this->problems->findMany(array_map(
            static fn (int $problemId): ProblemId =>
                new ProblemId($problemId),
            $problemIds
        )) as $problem) {
            if ($this->access->canView(
                $userId,
                $problem->id(),
                $problem->visibility()
            )) {
                $visible[] = $problem;
            }
        }
        $projected = [];
        foreach ($this->projectMany($visible) as $problem) {
            $projected[$problem->problemId()] = $problem;
        }

        return $projected;
    }

    public function catalogForUser(int $userId, int $limit = 500): array
    {
        if ($userId < 1) {
            return [];
        }
        $visible = [];
        foreach ($this->page(ProblemVisibility::Published, $limit) as $problem) {
            if ($this->access->canView(
                $userId,
                $problem->id(),
                $problem->visibility()
            )) {
                $visible[] = $problem;
            }
        }

        return $this->projectMany($visible);
    }

    public function recentForUser(int $userId, int $limit = 5): array
    {
        if ($userId < 1) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $total = $this->problems->count(
            new ProblemSearch('', ProblemVisibility::Published, 1, 0)
        );
        if ($total < 1) {
            return [];
        }
        $batchSize = min(100, $total);
        $rows = $this->problems->search(new ProblemSearch(
            '',
            ProblemVisibility::Published,
            $batchSize,
            max(0, $total - $batchSize)
        ));
        $visible = [];
        foreach ($rows as $problem) {
            if ($this->access->canView(
                $userId,
                $problem->id(),
                $problem->visibility()
            )) {
                $visible[] = $problem;
            }
        }

        return $this->projectMany(array_reverse(array_slice($visible, -$limit)));
    }

    public function publicCatalog(int $limit = 500): array
    {
        return array_slice(
            $this->publishedCatalog(),
            0,
            max(1, min(100000, $limit))
        );
    }

    public function catalogRevision(): string
    {
        $parts = [];
        foreach ($this->publishedCatalog() as $problem) {
            $parts[] = $problem->problemId() . ':' . $problem->contentHash();
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @return list<LearningProblem>
     */
    private function publishedCatalog(): array
    {
        if ($this->publishedCache === null) {
            $this->publishedCache = $this->projectMany(
                $this->page(ProblemVisibility::Published, 100000)
            );
        }

        return $this->publishedCache;
    }

    /**
     * @return list<JudgeProblem>
     */
    private function page(
        ProblemVisibility $visibility,
        int $limit
    ): array {
        $remaining = max(1, min(100000, $limit));
        $offset = 0;
        $result = [];
        while ($remaining > 0) {
            $size = min(100, $remaining);
            $rows = $this->problems->search(
                new ProblemSearch('', $visibility, $size, $offset)
            );
            foreach ($rows as $problem) {
                if ($problem instanceof JudgeProblem) {
                    $result[] = $problem;
                }
            }
            $count = count($rows);
            if ($count < $size) {
                break;
            }
            $remaining -= $count;
            $offset += $count;
        }

        return $result;
    }

    /**
     * @param list<JudgeProblem> $problems
     * @return list<LearningProblem>
     */
    private function projectMany(array $problems): array
    {
        if ($problems === []) {
            return [];
        }
        $metadata = [];
        foreach (array_chunk($problems, 500) as $chunk) {
            foreach ($this->metadata->findMany(array_map(
                static fn (JudgeProblem $problem): ProblemId =>
                    $problem->id(),
                $chunk
            )) as $problemId => $item) {
                $metadata[$problemId] = $item;
            }
        }

        return array_map(
            fn (JudgeProblem $problem): LearningProblem => $this->project(
                $problem,
                $metadata[$problem->id()->value()] ?? null
            ),
            $problems
        );
    }

    private function project(
        JudgeProblem $problem,
        ?ProblemMetadata $metadata
    ): LearningProblem {
        return new LearningProblem(
            $problem->id()->value(),
            $problem->title(),
            $problem->description(),
            $problem->input(),
            $problem->output(),
            $problem->hint(),
            $metadata?->difficulty()?->value(),
            $metadata?->topicKeys() ?? [],
            $problem->accepted(),
            $problem->submitted(),
            $problem->visibility() === ProblemVisibility::Published,
            $this->hash($problem, $metadata)
        );
    }

    private function hash(
        JudgeProblem $problem,
        ?ProblemMetadata $metadata
    ): string {
        try {
            $value = json_encode([
                'problem_id' => $problem->id()->value(),
                'title' => $problem->title(),
                'description' => $problem->description(),
                'input' => $problem->input(),
                'output' => $problem->output(),
                'hint' => $problem->hint(),
                'visibility' => $problem->visibility()->value,
                'difficulty' => $metadata?->difficulty()?->value(),
                'topics' => $metadata?->topicKeys() ?? [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            $value = $problem->id()->value() . ':' . $problem->title();
        }

        return hash('sha256', $value);
    }
}
