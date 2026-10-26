<?php
declare(strict_types=1);

namespace CodingPanda\OjResearch\Contract;

interface ResearchLearningGateway
{
    /** Existing level service output, without research-specific recalculation. @return array<string,mixed> */
    public function levelSnapshot(int $userId): array;

    /** @return array<int,string> Problem content hashes frozen before intervention. */
    public function problemHashes(int $actorUserId, array $problemIds): array;

    /** @return list<array<string,mixed>> */
    public function submissions(int $userId, array $problemIds, string $from, string $until): array;
}
