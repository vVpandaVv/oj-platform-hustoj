<?php
declare(strict_types=1);

namespace FocusTracker\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1;

/**
 * Holds the AddonRuntimeContextV1 handed to this addon's onReady() callback
 * for the lifetime of the current request, mirroring teaching-tools'
 * Addon\Seating\Runtime_Bridge. Used by REST controllers and console page
 * handlers to resolve class/group rosters and to authorize a teacher against
 * a specific context before returning any student's tracking data.
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

    public static function learning_checks(): ?AddonLearningCheckGatewayV1 {
        return self::$runtime instanceof AddonLearningCheckRuntimeContextV1 ? self::$runtime->learningChecks() : null;
    }
}
