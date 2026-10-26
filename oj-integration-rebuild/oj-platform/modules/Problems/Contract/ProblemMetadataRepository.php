<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;

interface ProblemMetadataRepository
{
    public function find(ProblemId $problemId): ?ProblemMetadata;

    /**
     * @param list<ProblemId> $problemIds
     * @return array<int, ProblemMetadata>
     */
    public function findMany(array $problemIds): array;

    public function save(ProblemMetadata $metadata): void;

    public function move(ProblemId $from, ProblemId $to): void;
}
