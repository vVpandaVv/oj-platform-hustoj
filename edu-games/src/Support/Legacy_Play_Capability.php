<?php
declare(strict_types=1);

namespace EduGames\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;

final class Legacy_Play_Capability {
    private const DECLARED_KEY = 'hustoj_edu_games_play';

    public static function register(AddonRuntimeContextV1 $runtime): void {
        $snapshot = $runtime->snapshot();
        if ($snapshot->addonId() !== 'edu-games' || $snapshot->status() !== 'accepted') {
            return;
        }

        add_filter('oj_platform_unprivileged_addon_capabilities', array(__CLASS__, 'unprivileged'));
    }

    /** Target classification only: never grant, revoke or alias an access permission. */
    public static function unprivileged(array $capabilities): array {
        if (! class_exists(AddonCapabilityKeyV1::class) || ! array_is_list($capabilities)) {
            return $capabilities;
        }
        $legacyPlay = AddonCapabilityKeyV1::wordpress(self::DECLARED_KEY);
        if (! in_array($legacyPlay, $capabilities, true)) {
            $capabilities[] = $legacyPlay;
        }

        return $capabilities;
    }
}
