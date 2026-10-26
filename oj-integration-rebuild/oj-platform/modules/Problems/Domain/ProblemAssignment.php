<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

final class ProblemAssignment
{
    /**
     * @param array<int, CollaboratorRole> $activeCollaborators
     */
    public function __construct(
        private readonly ProblemId $problemId,
        private readonly ?int $ownerUserId,
        private readonly array $activeCollaborators
    ) {
    }

    public function problemId(): ProblemId
    {
        return $this->problemId;
    }

    public function ownerUserId(): ?int
    {
        return $this->ownerUserId;
    }

    public function isOwner(int $userId): bool
    {
        return $userId > 0 && $this->ownerUserId === $userId;
    }

    public function collaboratorRole(int $userId): ?CollaboratorRole
    {
        return $this->activeCollaborators[$userId] ?? null;
    }

    /**
     * @return array<int, CollaboratorRole>
     */
    public function collaborators(): array
    {
        return $this->activeCollaborators;
    }
}
