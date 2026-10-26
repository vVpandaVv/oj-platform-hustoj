<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

interface ActionResponse
{
    public function redirect(
        string $path,
        UiMessage $message,
        int $userId = 0
    ): HttpResponse;
}
