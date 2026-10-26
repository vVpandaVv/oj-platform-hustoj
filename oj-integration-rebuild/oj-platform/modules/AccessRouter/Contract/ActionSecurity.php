<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;

interface ActionSecurity
{
    public function hiddenFields(string $actionKey): string;

    public function verify(string $actionKey, HttpRequest $request): bool;
}
