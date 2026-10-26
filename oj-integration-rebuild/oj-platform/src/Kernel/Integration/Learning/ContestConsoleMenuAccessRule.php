<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;

final class ContestConsoleMenuAccessRule implements ConsoleMenuAccessRule
{
    public function __construct(private readonly ContestService $contests)
    {
    }

    public function supports(MenuDefinition $menu): bool
    {
        return in_array(
            $menu->key(),
            ['contests.index', 'contests.deleted_submissions'],
            true
        );
    }

    public function allows(int $userId, MenuDefinition $menu): bool
    {
        return $this->contests->managedBy($userId) !== [];
    }
}
