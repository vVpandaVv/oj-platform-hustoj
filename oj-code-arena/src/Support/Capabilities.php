<?php
declare(strict_types=1);

namespace OjCodeArena\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

/** Manifest capability keys and the checks used by pages and REST routes. */
final class Capabilities {
    public const PLAY = 'oj_code_arena_play';
    public const TEACH = 'oj_code_arena_teach';
    public const SETTINGS = 'oj_code_arena_settings';

    public static function can_play(): bool {
        return is_user_logged_in()
            && (self::has(self::PLAY) || self::can_teach());
    }

    public static function can_teach(): bool {
        return is_user_logged_in()
            && (self::has(self::TEACH) || current_user_can('manage_options'));
    }

    public static function is_operator(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Site settings (console settings page, its save/.env handlers, the
     * status check and the load test): site admins, or educators given
     * the "코드 아레나 설정" capability in /oj-console/roles.
     */
    public static function can_manage_settings(): bool {
        return is_user_logged_in()
            && (is_super_admin() || self::has(self::SETTINGS));
    }

    private static function has(string $key): bool {
        return class_exists(AddonCapabilityKeyV1::class) && current_user_can(AddonCapabilityKeyV1::wordpress($key));
    }
}
