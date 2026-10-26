<?php
declare(strict_types=1);

namespace LearningMaterials\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;

/**
 * Holds the AddonRuntimeContextV1 handed to this addon's onReady callback,
 * so the rest of the plugin can reach the platform's group Context gateway
 * without threading it through every constructor. Reconnected on every
 * request (WordPress reboots the whole plugin stack each time), so no
 * cross-request state is assumed.
 */
final class Runtime_Bridge {
    private static ?AddonRuntimeContextV1 $runtime = null;

    public static function connect(AddonRuntimeContextV1 $runtime): void {
        if ('accepted' === $runtime->snapshot()->status()) {
            self::$runtime = $runtime;
        }
    }

    public static function context_gateway(): ?AddonContextGatewayV1 {
        return self::$runtime?->contexts();
    }
}
