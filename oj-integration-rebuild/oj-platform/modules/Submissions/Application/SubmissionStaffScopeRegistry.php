<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionStaffScope;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

final class SubmissionStaffScopeRegistry
{
    /**
     * @var list<SubmissionStaffScope>
     */
    private array $scopes = [];

    public function register(SubmissionStaffScope $scope): void
    {
        $this->scopes[] = $scope;
    }

    public function canView(
        int $actorUserId,
        SubmissionSummary $submission
    ): bool {
        foreach ($this->scopes as $scope) {
            if ($scope->canView($actorUserId, $submission)) {
                return true;
            }
        }

        return false;
    }
}
