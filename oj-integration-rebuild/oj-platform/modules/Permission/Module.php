<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Permission;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionPresetDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;

final class Module implements ModuleContract
{
    /**
     * @param DefinitionRegistry<CapabilityDefinition> $capabilities
     * @param DefinitionRegistry<RoleDefinition> $roles
     * @param DefinitionRegistry<PermissionPresetDefinition> $presets
     * @param array{capabilities: list<array<string, mixed>>, roles: list<array<string, mixed>>, presets: list<array<string, mixed>>} $configuration
     */
    public function __construct(
        private readonly DefinitionRegistry $capabilities,
        private readonly DefinitionRegistry $roles,
        private readonly DefinitionRegistry $presets,
        private readonly array $configuration
    ) {
    }

    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(new ModuleId('permission'), ModuleType::Required);
    }

    public function register(): void
    {
        foreach ($this->configuration['capabilities'] as $definition) {
            $this->capabilities->register(CapabilityDefinition::fromArray($definition));
        }

        foreach ($this->configuration['roles'] as $definition) {
            $this->roles->register(RoleDefinition::fromArray($definition));
        }

        foreach ($this->configuration['presets'] as $definition) {
            $this->presets->register(PermissionPresetDefinition::fromArray($definition));
        }
    }

    public function connect(): void
    {
    }
}
