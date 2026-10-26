<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AccessRouter;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;

final class Module implements ModuleContract
{
    /**
     * @param DefinitionRegistry<RouteDefinition> $routes
     * @param DefinitionRegistry<ActionDefinition> $actions
     * @param list<array<string, mixed>> $routeConfiguration
     * @param list<array<string, mixed>> $actionConfiguration
     */
    public function __construct(
        private readonly DefinitionRegistry $routes,
        private readonly DefinitionRegistry $actions,
        private readonly array $routeConfiguration,
        private readonly array $actionConfiguration
    ) {
    }

    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(
            new ModuleId('access_router'),
            ModuleType::Required,
            ['permission', 'settings', 'jobs']
        );
    }

    public function register(): void
    {
        foreach ($this->routeConfiguration as $definition) {
            $this->routes->register(RouteDefinition::fromArray($definition));
        }

        foreach ($this->actionConfiguration as $definition) {
            $this->actions->register(ActionDefinition::fromArray($definition));
        }
    }

    public function connect(): void
    {
    }
}
