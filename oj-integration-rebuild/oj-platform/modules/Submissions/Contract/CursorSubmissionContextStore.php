<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

interface CursorSubmissionContextStore
{
    /** @return list<int> Descending submission IDs strictly below the cursor. */
    public function submissionIdsBefore(?int $classId, ?int $groupId, int $limit, ?int $beforeId): array;
}
