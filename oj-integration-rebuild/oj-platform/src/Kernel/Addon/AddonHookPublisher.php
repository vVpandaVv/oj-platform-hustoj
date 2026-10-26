<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;

interface AddonHookPublisher
{
    public function publishRegistration(AddonRegistrationGatewayV1 $gateway): void;
}
