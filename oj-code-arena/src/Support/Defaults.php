<?php
declare(strict_types=1);

namespace OjCodeArena\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;

/**
 * First-run defaults so the plugin works right after activation:
 *  - learners (every role that can log in) may play,
 *  - roles that manage OJ classes or groups (and administrators) may run games,
 *  - free rooms are on.
 * Runs once; afterwards the operator's own choices in the OJ console win.
 */
final class Defaults {
    public const OPTION = 'oj_code_arena_defaults_applied';
    private const TEACHER_CAPS = array('oj_manage_classes', 'oj_create_classes', 'oj_create_groups', 'oj_manage_own_groups', 'manage_options');

    public static function apply(): void {
        if (get_option(self::OPTION)) {
            return;
        }
        if (class_exists(AddonCapabilityKeyV1::class) && function_exists('wp_roles')) {
            $play = AddonCapabilityKeyV1::wordpress(Capabilities::PLAY);
            $teach = AddonCapabilityKeyV1::wordpress(Capabilities::TEACH);
            // Leave everything alone if an operator already assigned either one.
            if (! self::assigned($play) && ! self::assigned($teach)) {
                foreach (wp_roles()->roles as $key => $role) {
                    $caps = is_array($role['capabilities'] ?? null) ? $role['capabilities'] : array();
                    $wp_role = get_role((string) $key);
                    if (! $wp_role) {
                        continue;
                    }
                    if (! empty($caps['read'])) {
                        $wp_role->add_cap($play, true);
                    }
                    foreach (self::TEACHER_CAPS as $cap) {
                        if (! empty($caps[$cap])) {
                            $wp_role->add_cap($teach, true);
                            break;
                        }
                    }
                }
            }
        }
        $s = Settings::all();
        if (! get_option(Settings::OPTION)) {
            $s['free_enabled'] = true;
            update_option(Settings::OPTION, $s, false);
        }
        Settings::ensure_secret();
        update_option(self::OPTION, gmdate('c'), false);
    }

    private static function assigned(string $cap): bool {
        foreach (wp_roles()->roles as $role) {
            if (! empty($role['capabilities'][$cap])) {
                return true;
            }
        }
        return false;
    }
}
