<?php
declare(strict_types=1);

namespace TeachingTools\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

/**
 * Single source of truth for this addon's manifest capability keys. OJ
 * Platform stores the corresponding WordPress capability through the public
 * AddonCapabilityKeyV1 mapper.
 */
final class Capabilities {
    public const HOST = 'teaching_tools_host';
    public const JOIN = 'teaching_tools_join';
    public const SETTINGS = 'teaching_tools_settings';

    public static function can_host(): bool {
        return is_user_logged_in()
            && class_exists(AddonCapabilityKeyV1::class)
            && current_user_can(AddonCapabilityKeyV1::wordpress(self::HOST));
    }

    public static function can_join(): bool {
        return is_user_logged_in()
            && class_exists(AddonCapabilityKeyV1::class)
            && current_user_can(AddonCapabilityKeyV1::wordpress(self::JOIN));
    }

    public static function can_manage_settings(): bool {
        return is_user_logged_in()
            && (
                is_super_admin()
                || (
                    class_exists(AddonCapabilityKeyV1::class)
                    && current_user_can(AddonCapabilityKeyV1::wordpress(self::SETTINGS))
                )
            );
    }
}
