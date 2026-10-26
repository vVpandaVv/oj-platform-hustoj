<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings;

use CodingPanda\OjPlatform\Kernel\Module\Module as ModuleContract;
use CodingPanda\OjPlatform\Kernel\Module\ModuleDefinition;
use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Module\ModuleType;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureDefinition;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingDefinition;

final class Module implements ModuleContract
{
    /**
     * @param DefinitionRegistry<FeatureDefinition> $features
     * @param DefinitionRegistry<SettingDefinition> $settings
     * @param list<array<string, mixed>> $featureConfiguration
     * @param list<array<string, mixed>> $settingConfiguration
     */
    public function __construct(
        private readonly DefinitionRegistry $features,
        private readonly DefinitionRegistry $settings,
        private readonly array $featureConfiguration,
        private readonly array $settingConfiguration,
        private readonly ?DefinitionRegistry $schemas = null,
        private readonly array $schemaConfiguration = []
    ) {
    }

    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition(new ModuleId('settings'), ModuleType::Required);
    }

    public function register(): void
    {
        foreach ($this->featureConfiguration as $definition) {
            $this->features->register(FeatureDefinition::fromArray($definition));
        }

        foreach ($this->settingConfiguration as $definition) {
            $this->settings->register(SettingDefinition::fromArray($definition));
        }
        foreach ($this->schemaConfiguration as $definition) {
            $this->schemas?->register(SchemaTableDefinition::fromArray($definition));
        }
    }

    public function connect(): void
    {
    }
}
