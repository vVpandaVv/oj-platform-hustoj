<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AccessDecision;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;

interface RouteAccessPolicy
{
    public function decide(RouteMatch $route, HttpRequest $request): AccessDecision;
}
