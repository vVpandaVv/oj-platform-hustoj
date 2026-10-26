<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface SubmissionContextProfileProvider
{
    public function supportsContext(
        int $contestId,
        ?int $classId,
        ?int $groupId
    ): bool;

    public function blocklyProfile(
        int $contestId,
        ?int $classId,
        ?int $groupId
    ): ?string;
}
