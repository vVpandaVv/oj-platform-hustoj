<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\AddonApi\V1;

/**
 * Addon API 1.3: in-page navigation under one console menu.
 *
 * Kept apart from AddonManifestBuilderV1 so test doubles and older
 * platforms that implement only the 1.2 builder keep working. Addons
 * feature-detect it:
 *
 *     if ($builder instanceof AddonConsoleLocalNavBuilderV1) {
 *         $builder->consoleLocalNav(new AddonConsoleLocalNavItemV1(...));
 *     }
 */
interface AddonConsoleLocalNavBuilderV1
{
    public function consoleLocalNav(AddonConsoleLocalNavItemV1 $definition): void;
}
