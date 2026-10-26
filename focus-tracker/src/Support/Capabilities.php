<?php
declare(strict_types=1);

namespace FocusTracker\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

/**
 * Single source of truth for this addon's manifest capability keys, mirroring
 * teaching-tools' Support\Capabilities. OJ Platform maps each declared key to
 * a real WordPress capability once the manifest is accepted.
 */
final class Capabilities {
    public const VIEW = 'focus_tracker_view';
    public const MANAGE = 'focus_tracker_manage';
    public const PARTICIPATE = 'focus_tracker_participate';
    public const VIEW_SELF = 'focus_tracker_view_self';
    public const SETTINGS = 'focus_tracker_settings';

    public static function can_view(): bool {
        return self::current_user_can(self::VIEW);
    }

    public static function can_manage(): bool {
        return self::current_user_can(self::MANAGE);
    }

    public static function can_participate(): bool {
        return self::current_user_can(self::PARTICIPATE);
    }

    public static function can_view_self(): bool {
        return self::current_user_can(self::VIEW_SELF);
    }

    /**
     * Site settings (console 설정 page and its admin-post save handler):
     * site administrators always pass; teachers need the addon capability,
     * granted per role in /oj-console/roles.
     */
    public static function can_manage_settings(): bool {
        if (! is_user_logged_in()) {
            return false;
        }

        return is_super_admin() || self::current_user_can(self::SETTINGS);
    }

    private static function current_user_can(string $declared_key): bool {
        return is_user_logged_in()
            && class_exists(AddonCapabilityKeyV1::class)
            && current_user_can(AddonCapabilityKeyV1::wordpress($declared_key));
    }
}
