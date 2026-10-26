<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Application;

use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;

final class BlocklySettingsService
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly BlocklyProfileCatalog $profiles
    ) {
    }

    public function save(
        bool $enabled,
        string $defaultProfile,
        int $maximumWorkspaceKilobytes,
        int $expectedRevision
    ): SettingsSnapshot {
        $this->profiles->assertProfile($defaultProfile);

        return $this->settings->save(
            'blockly',
            [
                'enabled' => $enabled,
                'default_profile' => $defaultProfile,
                'maximum_workspace_kb' => max(
                    64,
                    min(1024, $maximumWorkspaceKilobytes)
                ),
                'engine_version' => BlocklyProfileCatalog::ENGINE_VERSION,
                'registry_version' => BlocklyProfileCatalog::REGISTRY_VERSION,
                'generator_version' => BlocklyProfileCatalog::GENERATOR_VERSION,
            ],
            $expectedRevision
        );
    }
}
