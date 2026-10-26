<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Application;

use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblem;

interface ContestJudgeRepository
{
    public function find(int $contestId): ?Contest;

    /** @return list<Contest> */
    public function all(): array;

    /** @return list<ContestProblem> */
    public function problems(int $contestId): array;

    public function save(
        ContestSaveCommand $command,
        string $ownerLogin
    ): int;

    public function setArchived(int $contestId, bool $archived): void;

    public function remove(int $contestId): void;
}
