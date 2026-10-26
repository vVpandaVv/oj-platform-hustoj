<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PlatformDb;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\TargetTableDefinition;

final class Module implements ModuleContract
{
    /**
     * @param DefinitionRegistry<TargetTableDefinition> $targets
     * @param list<array<string, mixed>> $configuration
     */
    public function __construct(
        private readonly DefinitionRegistry $targets,
        private readonly array $configuration
    ) {
    }

    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(
            new ModuleId('platform_db'),
            ModuleType::Required,
            ['settings']
        );
    }

    public function register(): void
    {
        foreach ($this->configuration as $definition) {
            $this->targets->register(TargetTableDefinition::fromArray($definition));
        }
    }

    public function connect(): void
    {
    }
}
