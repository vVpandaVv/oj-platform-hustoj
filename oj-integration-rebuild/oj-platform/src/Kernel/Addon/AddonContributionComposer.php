<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PublicUi\Access\ConsoleRouteAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDefinition;

/**
 * Merges every accepted addon's contributions into the real platform
 * registries. Invoked once, between AcceptedAddonCatalog::commitAndFreeze()
 * and RegistrySet::validateAndFreeze() (see AddonLifecycleManager), so this
 * is the only place addon-derived RouteDefinition/MenuDefinition/
 * CapabilityDefinition objects are ever registered. When zero addons are
 * accepted this is a no-op — core registry counts are never touched.
 *
 * @internal
 */
final class AddonContributionComposer
{
    /**
     * @param DefinitionRegistry<FeatureDefinition> $features
     */
    public function __construct(
        private readonly DefinitionRegistry $features,
        private readonly AddonCapabilityContributor $capabilities,
        private readonly AddonRouteContributor $routes,
        private readonly AddonMenuContributor $menus,
        private readonly AddonAiTaskContributor $aiTasks,
        private readonly ConsoleRouteAccessRuleRegistry $consoleRouteAccessRules,
        private readonly Authorization $permissions,
        private readonly ?AddonDocumentExtensions $documentExtensions = null,
        private readonly ?AddonPrivacyInventory $privacyInventory = null
    ) {
    }

    public function compose(AcceptedAddonCatalog $catalog): void
    {
        $accepted = $catalog->accepted();
        if ($accepted === []) {
            return;
        }

        $this->documentExtensions?->replace($catalog);
        $this->privacyInventory?->replace($catalog);

        $this->features->register(new FeatureDefinition(
            AddonCapabilityKeys::FEATURE_KEY,
            '애드온 콘텐츠',
            true,
            true,
            []
        ));

        foreach ($accepted as $entry) {
            $manifest = $entry->manifest();
            if (! $manifest instanceof AddonManifest) {
                continue;
            }
            $this->capabilities->contribute($manifest);
            $this->routes->contribute($manifest);
            $this->menus->contribute($manifest);
            $this->aiTasks->contribute($manifest);
        }

        $consoleCapabilities = $this->routes->consoleCapabilities();
        if ($consoleCapabilities !== []) {
            $this->consoleRouteAccessRules->register(
                AddonCapabilityKeys::MODULE_OWNER,
                new AddonConsoleRouteAccessRule($consoleCapabilities, $this->permissions)
            );
        }
    }
}
