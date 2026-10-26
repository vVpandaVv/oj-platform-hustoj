<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Contract;

interface ContestParticipantTransition
{
    public function transitionParticipantStatus(int $contestId, int $userId, ?string $expectedStatus, string $status, int $actorUserId): bool;
}
