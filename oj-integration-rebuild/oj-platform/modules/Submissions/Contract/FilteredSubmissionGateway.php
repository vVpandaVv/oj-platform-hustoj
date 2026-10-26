<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Contract;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

interface FilteredSubmissionGateway
{
    /** @param list<SubmissionId> $ids @return array<int, SubmissionSummary> */
    public function findFilteredSummaries(array $ids, SubmissionProjectionQuery $query): array;
}
