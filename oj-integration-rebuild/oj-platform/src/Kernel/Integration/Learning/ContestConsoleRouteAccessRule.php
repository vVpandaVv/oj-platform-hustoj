<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\ConsoleRouteAccessRule;

final class ContestConsoleRouteAccessRule implements ConsoleRouteAccessRule
{
    public function __construct(private readonly ContestService $contests)
    {
    }

    public function supports(RouteMatch $route): bool
    {
        $name = $route->route()->key();

        return $name === 'console.learning.index'
            || str_starts_with($name, 'console.contests.');
    }

    public function allows(int $userId, RouteMatch $route): bool
    {
        $name = $route->route()->key();
        if ($name === 'console.learning.index') {
            return $this->contests->managedBy($userId) !== [];
        }
        if ($name === 'console.contests.new') {
            return false;
        }
        if (in_array(
            $name,
            ['console.contests.index', 'console.contests.deleted_submissions'],
            true
        )) {
            return $this->contests->managedBy($userId) !== [];
        }
        $contestId = (int) ($route->parameters()['contest_id'] ?? 0);

        return $this->contests->canAccessConsoleRoute(
            $userId,
            $contestId,
            $name
        );
    }
}
