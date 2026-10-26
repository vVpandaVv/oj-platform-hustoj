<?php
declare(strict_types=1);

namespace LearningMaterials\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

/**
 * Single source of truth for this addon's manifest capability keys. OJ
 * Platform stores the corresponding WordPress capability through the public
 * AddonCapabilityKeyV1 mapper.
 */
final class Capabilities {
    public const MANAGE = 'learning_materials_manage';
    public const VIEW = 'learning_materials_view';

    public static function can_manage(): bool {
        return is_user_logged_in()
            && class_exists(AddonCapabilityKeyV1::class)
            && current_user_can(AddonCapabilityKeyV1::wordpress(self::MANAGE));
    }

    public static function can_view(): bool {
        return is_user_logged_in()
            && class_exists(AddonCapabilityKeyV1::class)
            && current_user_can(AddonCapabilityKeyV1::wordpress(self::VIEW));
    }
}
