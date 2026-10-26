<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AdminConsole;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\MenuDefinition;

final class Module implements ModuleContract
{
    /**
     * @param DefinitionRegistry<MenuDefinition> $menus
     * @param list<array<string, mixed>> $configuration
     */
    public function __construct(
        private readonly DefinitionRegistry $menus,
        private readonly array $configuration
    ) {
    }

    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(
            new ModuleId('admin_console'),
            ModuleType::Required,
            ['permission', 'settings', 'access_router'],
            ['auth']
        );
    }

    public function register(): void
    {
        foreach ($this->configuration as $definition) {
            $this->menus->register(MenuDefinition::fromArray($definition));
        }
    }

    public function connect(): void
    {
    }
}
