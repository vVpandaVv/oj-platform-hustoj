<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Contract;

interface GroupSubmissionAccess
{
    public function restrictsOwnSubmissionDetails(int $userId): bool;

    public function canViewMemberSubmission(
        int $actorUserId,
        string $targetUserLogin
    ): bool;
}
