<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter\Contract;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;

interface ResponseEmitter
{
    public function emit(HttpResponse $response, bool $headRequest = false): void;
}
