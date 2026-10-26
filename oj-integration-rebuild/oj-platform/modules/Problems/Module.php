<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;

final class Module implements ModuleContract
{
    /**
     * @param DefinitionRegistry<SchemaTableDefinition> $schemas
     * @param list<array<string, mixed>> $configuration
     */
    public function __construct(
        private readonly DefinitionRegistry $schemas,
        private readonly array $configuration
    ) {
    }

    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(
            new ModuleId('problems'),
            ModuleType::Optional,
            ['auth', 'platform_db', 'judge_db', 'jobs']
        );
    }

    public function register(): void
    {
        foreach ($this->configuration as $definition) {
            $this->schemas->register(SchemaTableDefinition::fromArray($definition));
        }
    }

    public function connect(): void
    {
    }
}
