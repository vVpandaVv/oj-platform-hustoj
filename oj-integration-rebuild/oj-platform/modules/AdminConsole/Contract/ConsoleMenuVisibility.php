<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\Modules\AdminConsole\Contract;

interface ConsoleMenuVisibility
{
    public function canAccessMenu(int $userId, string $menuKey): bool;
}
