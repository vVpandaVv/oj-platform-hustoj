<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\ConsoleRouteAccessRule;
use DomainException;

final class GroupConsoleRouteAccessRule implements ConsoleRouteAccessRule
{
    public function __construct(private readonly GroupService $groups)
    {
    }

    public function supports(RouteMatch $route): bool
    {
        $name = $route->route()->key();

        return $name === 'console.learning.index'
            || str_starts_with($name, 'console.groups.');
    }

    public function allows(int $userId, RouteMatch $route): bool
    {
        $name = $route->route()->key();
        if ($name === 'console.learning.index') {
            return $this->groups->managedBy($userId) !== [];
        }
        if ($name === 'console.groups.new') {
            return false;
        }
        if (in_array($name, ['console.groups.index', 'console.groups.deleted'], true)) {
            return $this->groups->managedBy($userId) !== [];
        }
        $groupId = (int) ($route->parameters()['group_id'] ?? 0);
        try {
            $group = $this->groups->findManaged($groupId, $userId);

            return $name !== 'console.groups.delete'
                || $this->groups->canArchive($userId, $group);
        } catch (DomainException) {
            return false;
        }
    }
}
