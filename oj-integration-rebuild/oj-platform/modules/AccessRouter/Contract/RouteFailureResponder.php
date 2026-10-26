<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

interface RouteFailureResponder
{
    public function unexpected(HttpRequest $request): HttpResponse;
}
