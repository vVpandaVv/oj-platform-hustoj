<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;

final class ProblemConsoleMenuAccessRule implements ConsoleMenuAccessRule
{
    public function __construct(
        private readonly ProblemAssignmentRepository $assignments
    ) {
    }

    public function supports(MenuDefinition $menu): bool
    {
        return $menu->key() === 'problems.index';
    }

    public function allows(int $userId, MenuDefinition $menu): bool
    {
        unset($menu);

        return $this->assignments->problemIdsForUser($userId) !== [];
    }
}
