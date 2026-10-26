<?php
declare(strict_types=1);

namespace EduGames\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

/**
 * Single source of truth for this addon's manifest capability keys. OJ
 * Platform stores the corresponding WordPress capability through the public
 * AddonCapabilityKeyV1 mapper.
 */
final class Capabilities {
    public const PLAY = 'edu_games_play';
    public const MANAGE = 'edu_games_manage';

    public static function can_play(): bool {
        return is_user_logged_in()
            && class_exists(AddonCapabilityKeyV1::class)
            && current_user_can(AddonCapabilityKeyV1::wordpress(self::PLAY));
    }

    public static function can_manage(): bool {
        return class_exists(AddonCapabilityKeyV1::class)
            && current_user_can(AddonCapabilityKeyV1::wordpress(self::MANAGE));
    }

    /**
     * Who may preview a closed/hidden game (bypassing Game_Visibility).
     * Accepts either the addon-declared manage capability (the intended,
     * explicitly-assigned path) or WordPress's own manage_options, so a
     * site administrator always retains preview access even before an
     * operator has assigned the addon capability to any role.
     */
    public static function can_preview_closed(): bool {
        return self::can_manage() || current_user_can('manage_options');
    }
}
