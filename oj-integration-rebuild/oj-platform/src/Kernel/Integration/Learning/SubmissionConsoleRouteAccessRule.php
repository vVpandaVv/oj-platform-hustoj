<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\ConsoleRouteAccessRule;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionQueryService;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;

/**
 * Lets a staff member without a global submission capability (a class
 * teacher, contest manager, or group delegate) enter the generic console
 * submissions screens as long as they manage at least one class, contest,
 * or group — the same three sources SubmissionStaffScopeRegistry draws its
 * per-submission scope checks from. The detail route re-checks the specific
 * submission through the same SubmissionVisibilityPolicy the page itself
 * uses, so this never grants more than the page would already allow.
 */
final class SubmissionConsoleRouteAccessRule implements ConsoleRouteAccessRule
{
    public function __construct(
        private readonly ClassService $classes,
        private readonly ContestService $contests,
        private readonly GroupService $groups,
        private readonly SubmissionQueryService $submissions
    ) {
    }

    public function supports(RouteMatch $route): bool
    {
        return in_array($route->route()->key(), [
            'console.submissions.index',
            'console.submissions.detail',
        ], true);
    }

    public function allows(int $userId, RouteMatch $route): bool
    {
        if (
            $this->classes->managedBy($userId) === []
            && $this->contests->managedBy($userId) === []
            && $this->groups->managedBy($userId) === []
        ) {
            return false;
        }

        if ($route->route()->key() === 'console.submissions.index') {
            return true;
        }

        $submissionId = (int) ($route->parameters()['submission_id'] ?? 0);
        if ($submissionId < 1) {
            return false;
        }

        return $this->submissions->view(
            $userId,
            '',
            new SubmissionId($submissionId)
        ) !== null;
    }
}
