<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDefinition;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;

final class Module implements ModuleContract
{
    /**
     * @param DefinitionRegistry<JobDefinition> $jobs
     * @param DefinitionRegistry<SchemaTableDefinition> $schemas
     * @param list<array<string, mixed>> $jobConfiguration
     * @param list<array<string, mixed>> $schemaConfiguration
     */
    public function __construct(
        private readonly DefinitionRegistry $jobs,
        private readonly DefinitionRegistry $schemas,
        private readonly array $jobConfiguration,
        private readonly array $schemaConfiguration
    ) {
    }

    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(
            new ModuleId('jobs'),
            ModuleType::Required,
            ['platform_db', 'settings']
        );
    }

    public function register(): void
    {
        foreach ($this->jobConfiguration as $definition) {
            $this->jobs->register(JobDefinition::fromArray($definition));
        }

        foreach ($this->schemaConfiguration as $definition) {
            $this->schemas->register(SchemaTableDefinition::fromArray($definition));
        }
    }

    public function connect(): void
    {
    }
}
