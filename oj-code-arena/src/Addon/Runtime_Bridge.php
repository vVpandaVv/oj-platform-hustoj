<?php
declare(strict_types=1);

namespace OjCodeArena\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;

/**
 * Keeps the runtime context handed to onReady() for this request, so REST
 * controllers can reach the class/group Context Gateway.
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

    public static function learning_gateway(): ?AddonLearningContextGatewayV1 {
        $contexts = self::contexts();
        return $contexts instanceof AddonLearningContextGatewayV1 ? $contexts : null;
    }
}
