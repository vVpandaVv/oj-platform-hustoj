<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\PerformanceModePolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionAccessLevel;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;

final class SubmissionVisibilityPolicy
{
    public function __construct(
        private readonly Authorization $permissions,
        private readonly PerformanceModePolicy $performanceMode,
        private readonly SubmissionStaffScopeRegistry $staffScopes
    ) {
    }

    public function decide(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionSummary $submission
    ): SubmissionAccessLevel {
        if ($submission->belongsTo($actorUserLogin)) {
            return $this->performanceMode->restrictsOwnSubmissionDetails(
                $actorUserId
            )
                ? SubmissionAccessLevel::ResultOnly
                : SubmissionAccessLevel::Full;
        }

        $global = $this->permissions->can(
            $actorUserId,
            'oj_manage_submissions'
        );
        $context = $this->staffScopes->canView($actorUserId, $submission);
        if ($context) {
            return SubmissionAccessLevel::Full;
        }
        if (! $global) {
            return SubmissionAccessLevel::Denied;
        }
        if (
            $this->permissions->can($actorUserId, 'oj_view_submission_detail')
            && $this->permissions->can($actorUserId, 'oj_view_submission_code')
        ) {
            return SubmissionAccessLevel::Full;
        }

        return SubmissionAccessLevel::ResultOnly;
    }

    public function canListAll(int $actorUserId): bool
    {
        return $this->permissions->can($actorUserId, 'oj_manage_submissions');
    }
}
