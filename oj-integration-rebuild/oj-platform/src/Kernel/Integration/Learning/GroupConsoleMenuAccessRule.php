<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;

final class GroupConsoleMenuAccessRule implements ConsoleMenuAccessRule
{
    public function __construct(private readonly GroupService $groups)
    {
    }

    public function supports(MenuDefinition $menu): bool
    {
        return in_array(
            $menu->key(),
            ['classes.groups', 'classes.groups_deleted'],
            true
        );
    }

    public function allows(int $userId, MenuDefinition $menu): bool
    {
        return $this->groups->managedBy($userId) !== [];
    }
}
