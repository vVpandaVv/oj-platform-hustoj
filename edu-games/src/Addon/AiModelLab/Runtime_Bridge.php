<?php
declare(strict_types=1);

namespace EduGames\Addon\AiModelLab;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;

/**
 * Holds the AddonRuntimeContextV1 handed to this addon's onReady() callback
 * for the lifetime of the current request. Every WordPress request reboots
 * the platform (and re-runs onReady) from scratch, so a static property is
 * enough — no cross-request persistence is needed or attempted here.
 *
 * Mirrors teaching-tools' Addon\Seating\Runtime_Bridge pattern exactly.
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
}
