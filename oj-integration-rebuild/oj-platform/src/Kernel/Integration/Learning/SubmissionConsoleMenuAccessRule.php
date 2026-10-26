<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;

/**
 * Shows the "제출 목록" console menu item for staff who manage at least one
 * class, contest, or group, even without a global submission capability —
 * mirrors SubmissionConsoleRouteAccessRule's route-level check.
 */
final class SubmissionConsoleMenuAccessRule implements ConsoleMenuAccessRule
{
    public function __construct(
        private readonly ClassService $classes,
        private readonly ContestService $contests,
        private readonly GroupService $groups
    ) {
    }

    public function supports(MenuDefinition $menu): bool
    {
        return $menu->key() === 'submissions.index';
    }

    public function allows(int $userId, MenuDefinition $menu): bool
    {
        unset($menu);

        return $this->classes->managedBy($userId) !== []
            || $this->contests->managedBy($userId) !== []
            || $this->groups->managedBy($userId) !== [];
    }
}
