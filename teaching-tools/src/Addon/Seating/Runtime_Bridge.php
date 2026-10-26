<?php
declare(strict_types=1);

namespace TeachingTools\Addon\Seating;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;

/**
 * Holds the AddonRuntimeContextV1 handed to this addon's onReady() callback
 * for the lifetime of the current request. Every WordPress request reboots
 * the platform (and re-runs onReady) from scratch, so a static property is
 * enough — no cross-request persistence is needed or attempted here.
 */
final class Runtime_Bridge {
    private static ?AddonRuntimeContextV1 $runtime = null;

    public static function connect(AddonRuntimeContextV1 $runtime): void {
        if ('accepted' === $runtime->snapshot()->status()) {
            self::$runtime = $runtime;
        }
    }

    public static function contexts(): ?AddonContextGatewayV1 {
        return self::$runtime?->contexts();
    }

    public static function roster_gateway(): ?AddonAssignmentRosterGatewayV1 {
        $contexts = self::contexts();

        return $contexts instanceof AddonAssignmentRosterGatewayV1 ? $contexts : null;
    }

    public static function integrations(): ?AddonIntegrationGatewayV1 {
        return self::$runtime instanceof AddonIntegrationRuntimeContextV1
            ? self::$runtime->integrations()
            : null;
    }
}
