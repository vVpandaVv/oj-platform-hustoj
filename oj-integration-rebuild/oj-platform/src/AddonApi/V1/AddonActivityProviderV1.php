<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

interface AddonActivityProviderV1
{
    public function search(AddonActivityQueryV1 $query): AddonActivityPageV1;
}
