<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1\Research;

interface ResearchLearningDataV1
{
    /** Original OJ level result; the research plugin must not replace its algorithm. */
    public function levelSnapshot(int $userId): array;
    public function problemHashes(int $actorUserId, array $problemIds): array;
    public function submissions(int $userId, array $problemIds, string $from, string $until): array;
    public function guides(): array;
    public function problems(int $actor, array $ids): array;
}
