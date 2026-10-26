<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Contract;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;

interface ConsoleRouteAccessRule
{
    public function supports(RouteMatch $route): bool;

    public function allows(int $userId, RouteMatch $route): bool;
}
