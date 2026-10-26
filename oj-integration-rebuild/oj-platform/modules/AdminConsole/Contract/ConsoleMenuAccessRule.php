<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Contract;

interface ConsoleMenuAccessRule
{
    public function supports(MenuDefinition $menu): bool;

    public function allows(int $userId, MenuDefinition $menu): bool;
}
