<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonRegistrationGatewayV1;

final class WordPressAddonHookPublisher implements AddonHookPublisher
{
    public function publishRegistration(AddonRegistrationGatewayV1 $gateway): void
    {
        if (function_exists('do_action')) {
            do_action('oj_platform_register_addons', $gateway);
        }
    }
}
