<?php
declare(strict_types=1);

namespace CodingPanda\OjResearch;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;

final class Module implements ModuleContract
{
    public function __construct(private readonly DefinitionRegistry $schemas, private readonly array $configuration) {}
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(new ModuleId('research'), ModuleType::Optional, ['groups', 'ai_feedback', 'platform_db']);
    }
    public function register(): void
    {
        foreach ($this->configuration as $definition) { $this->schemas->register(SchemaTableDefinition::fromArray($definition)); }
    }
    public function connect(): void {}
}
