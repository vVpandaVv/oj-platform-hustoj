<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;

/**
 * Turns the asset bundles a Console/Public addon page references into
 * ready-to-embed <link>/<script> markup. Addon pages bypass WordPress's
 * wp_head/wp_footer entirely (see AddonRouteHandler), so bundles can never
 * be enqueued-and-printed through the normal hook-timed flow — this is
 * invoked directly, once per served addon page request.
 *
 * Styles and scripts are rendered separately (not as one combined blob) so
 * the caller can place styles before the page body (avoids a flash of
 * unstyled content) and scripts after it (matching wp_footer semantics) —
 * a script printed before its page's markup executes while that markup is
 * still unparsed, so any DOM query it runs at load time finds nothing.
 *
 * @internal
 */
interface AddonAssetRenderer
{
    /**
     * @param list<AddonAssetBundleDefinitionV1> $bundles the addon's full declared bundle list
     * @param list<string> $bundleKeys the specific bundle keys the served page references, in order
     */
    public function renderStyles(string $pluginFile, array $bundles, array $bundleKeys): string;

    /**
     * @param list<AddonAssetBundleDefinitionV1> $bundles the addon's full declared bundle list
     * @param list<string> $bundleKeys the specific bundle keys the served page references, in order
     */
    public function renderScripts(string $pluginFile, array $bundles, array $bundleKeys): string;
}
