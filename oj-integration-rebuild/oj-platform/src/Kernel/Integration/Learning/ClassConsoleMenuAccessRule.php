<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleMenuAccessRule;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;

final class ClassConsoleMenuAccessRule implements ConsoleMenuAccessRule
{
    public function __construct(private readonly ClassService $classes)
    {
    }

    public function supports(MenuDefinition $menu): bool
    {
        return in_array(
            $menu->key(),
            ['classes.index', 'classes.deleted'],
            true
        );
    }

    public function allows(int $userId, MenuDefinition $menu): bool
    {
        return $this->classes->managedBy($userId) !== [];
    }
}
