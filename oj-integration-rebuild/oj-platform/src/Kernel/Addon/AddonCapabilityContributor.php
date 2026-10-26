<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Addon;

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Domain\CapabilityScope;

/** @internal */
final class AddonCapabilityContributor
{
    /**
     * @param DefinitionRegistry<CapabilityDefinition> $capabilities
     */
    public function __construct(
        private readonly DefinitionRegistry $capabilities
    ) {
    }

    public function contribute(AddonManifest $manifest): void
    {
        foreach ($manifest->capabilities() as $capability) {
            $this->capabilities->register(new CapabilityDefinition(
                AddonCapabilityKeys::capabilityKey($capability->key()),
                new ModuleId(AddonCapabilityKeys::MODULE_OWNER),
                AddonCapabilityKeys::FEATURE_KEY,
                CapabilityScope::Global,
                $capability->label(),
                sprintf(
                    '%s 애드온이 선언한 권한 (대상: %s)',
                    $manifest->diagnosticLabel(),
                    $capability->audience()
                )
            ));
        }
    }
}
