<?php
declare(strict_types=1);

namespace CodingPanda\OjResearch\Contract;

interface ResearchRepository
{
    public function transaction(callable $work): mixed;
    public function create(array $study): int;
    public function study(int $id, bool $lock = false): ?array;
    public function studies(): array;
    public function updateStudy(array $study, int $expectedRevision): void;
    public function participants(int $studyId): array;
    public function addParticipant(array $participant): int;
    public function updateParticipant(int $id, array $changes): void;
    public function activeForUser(int $userId): ?array;
    public function append(int $studyId, int $participantId, string $key, string $kind, array $data, string $at): void;
    public function records(int $studyId): array;
}
