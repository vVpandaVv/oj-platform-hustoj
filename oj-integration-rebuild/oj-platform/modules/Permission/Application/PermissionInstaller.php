<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionSetup;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleSyncReport;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleSynchronizer;

final class PermissionInstaller implements PermissionSetup
{
    private const ADDON_BRIDGE_OWNER = 'addon_bridge';

    /**
     * @param DefinitionRegistry<CapabilityDefinition> $capabilities
     * @param DefinitionRegistry<RoleDefinition> $roles
     */
    public function __construct(
        private readonly DefinitionRegistry $capabilities,
        private readonly DefinitionRegistry $roles,
        private readonly RoleSynchronizer $synchronizer
    ) {
    }

    public function synchronizeRoles(): RoleSyncReport
    {
        $administratorCapabilities = array_map(
            static fn (CapabilityDefinition $capability): string => $capability->key(),
            array_values(array_filter(
                $this->capabilities->all(),
                static fn (CapabilityDefinition $capability): bool =>
                    $capability->owner()->value() !== self::ADDON_BRIDGE_OWNER
            ))
        );
        sort($administratorCapabilities);

        return $this->synchronizer->synchronize(
            array_values($this->roles->all()),
            $administratorCapabilities
        );
    }
}
