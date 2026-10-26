<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonPageHandlerV1
{
    public function handle(AddonPageRequestV1 $request): AddonPageResponseV1;
}
