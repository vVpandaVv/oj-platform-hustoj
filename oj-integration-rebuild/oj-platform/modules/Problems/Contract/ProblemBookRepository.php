<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Contract;

use CodingPanda\OjPlatform\Modules\Problems\Domain\PersonalProblemBookItem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBook;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemBookVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

interface ProblemBookRepository
{
    /**
     * @return list<ProblemBook>
     */
    public function publicBooks(): array;

    /**
     * @return list<ProblemBook>
     */
    public function managedBooks(): array;

    public function find(int $bookId): ?ProblemBook;

    /**
     * @param list<ProblemId> $problemIds
     */
    public function save(
        ?int $bookId,
        ?int $parentId,
        string $name,
        string $slug,
        string $description,
        ProblemBookVisibility $visibility,
        int $sortOrder,
        array $problemIds,
        int $actorUserId
    ): ProblemBook;

    public function delete(int $bookId): void;

    /**
     * @return list<PersonalProblemBookItem>
     */
    public function personalItems(int $userId): array;

    public function hasPersonalItem(int $userId, ProblemId $problemId): bool;

    public function addPersonalItem(
        int $userId,
        ProblemId $problemId,
        string $note
    ): void;

    public function removePersonalItem(
        int $userId,
        ProblemId $problemId
    ): void;
}
