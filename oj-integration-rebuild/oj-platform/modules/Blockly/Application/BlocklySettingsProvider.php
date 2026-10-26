<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Application;

use CodingPanda\OjPlatform\Modules\Blockly\Domain\BlocklySettings;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use Throwable;

final class BlocklySettingsProvider
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly FeatureFlags $features
    ) {
    }

    public function current(): BlocklySettings
    {
        $value = $this->settings->read('blockly', [])->value();
        $value = is_array($value) ? $value : [];
        $featureEnabled = false;
        try {
            $featureEnabled = $this->features->isEnabled('blockly');
        } catch (Throwable) {
        }
        $profile = (string) ($value['default_profile'] ?? 'standard');
        if (! in_array($profile, ['intro', 'standard', 'extended'], true)) {
            $profile = 'standard';
        }
        $maximumKilobytes = max(
            64,
            min(1024, (int) ($value['maximum_workspace_kb'] ?? 512))
        );

        return new BlocklySettings(
            $featureEnabled && (bool) ($value['enabled'] ?? true),
            $profile,
            $maximumKilobytes * 1024
        );
    }
}
