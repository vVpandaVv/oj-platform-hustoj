<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole\Contract;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;

interface ConsoleResponder
{
    public function respond(
        string $title,
        string $content,
        string $currentMenu,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse;
}
