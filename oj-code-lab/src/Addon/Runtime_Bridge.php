<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonEditorRuntimeContextV1;

/**
 * Holds the AddonRuntimeContextV1 handed to this addon's onReady() callback
 * for the lifetime of the current request, mirroring focus-tracker's
 * Addon\Runtime_Bridge. Used by Context_Resolver to resolve class/group
 * rosters and authorize a teacher against a specific context before
 * returning any student's notebook.
 */
final class Runtime_Bridge {
    private static ?AddonRuntimeContextV1 $runtime = null;

    public static function connect(AddonRuntimeContextV1 $runtime): void {
        self::$runtime = 'accepted' === $runtime->snapshot()->status() ? $runtime : null;
    }

    public static function contexts(): ?AddonContextGatewayV1 {
        return self::$runtime?->contexts();
    }

    /** Current account's submission editor settings, through the optional public API. */
    public static function editor_config(): array {
        if (!(self::$runtime instanceof AddonEditorRuntimeContextV1)) {
            return array('available' => false);
        }
        $editor = self::$runtime->editor()->current();
        return array(
            'available' => $editor->isAvailable(),
            'theme' => $editor->theme(),
            'fontSize' => $editor->fontSize(),
            'coreUrl' => $editor->coreUrl(),
            'baseUrl' => $editor->baseUrl(),
        );
    }

    public static function roster_gateway(): ?AddonAssignmentRosterGatewayV1 {
        $contexts = self::contexts();

        return $contexts instanceof AddonAssignmentRosterGatewayV1 ? $contexts : null;
    }
}
