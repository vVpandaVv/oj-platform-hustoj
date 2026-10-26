<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

interface NotFoundResponder
{
    public function notFound(HttpRequest $request): HttpResponse;
}
