<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Contract;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;

interface PageResponder
{
    public function guest(
        string $title,
        string $content,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse;

    public function authenticated(
        string $title,
        string $content,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse;
}
