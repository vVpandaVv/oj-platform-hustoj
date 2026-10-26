<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionManagedScopeProvider as SubmissionManagedScopeProviderContract;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionManagedScope;

/**
 * Resolves the class/contest/group IDs a staff member manages into a scoped
 * value so Submissions can search without depending on the
 * Classes/Contests/Groups Application layers directly. Submissions is
 * composed before Classes/Contests/Groups exist, so this is built empty and
 * attach()ed once they're available — same bridge pattern as
 * LearningGroupChoiceBridge.
 */
final class SubmissionManagedScopeProvider implements SubmissionManagedScopeProviderContract
{
    private ?ClassService $classes = null;
    private ?ContestService $contests = null;
    private ?GroupService $groups = null;

    public function attach(
        ClassService $classes,
        ContestService $contests,
        GroupService $groups
    ): void {
        $this->classes = $classes;
        $this->contests = $contests;
        $this->groups = $groups;
    }

    public function scopeFor(int $userId): SubmissionManagedScope
    {
        if (
            $this->classes === null
            || $this->contests === null
            || $this->groups === null
        ) {
            return new SubmissionManagedScope();
        }

        return new SubmissionManagedScope(
            array_map(
                static fn ($class): int => $class->id(),
                $this->classes->managedBy($userId)
            ),
            array_map(
                static fn ($group): int => $group->id(),
                $this->groups->managedBy($userId)
            ),
            array_map(
                static fn ($contest): int => $contest->id(),
                $this->contests->managedBy($userId)
            )
        );
    }
}
