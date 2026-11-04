<?php
declare(strict_types=1);
namespace OjMicrobit\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use OjMicrobit\Core\Plugin;

final class LearnerCapabilities
{
    private const OPTION = 'oj_microbit_owned_learner_cap';
    public static function register(): void
    {
        add_filter('oj_platform_unprivileged_addon_capabilities', static function (array $keys): array {
            if (class_exists(AddonCapabilityKeyV1::class)) { $keys[] = AddonCapabilityKeyV1::wordpress('oj_microbit_learn'); }
            return array_values(array_unique($keys));
        });
        add_action('init', [self::class, 'apply'], 3);
    }
    public static function apply(): void
    {
        if (!class_exists(AddonCapabilityKeyV1::class) || Plugin::runtime()?->snapshot()->status() !== 'accepted') { return; }
        $state = get_option(self::OPTION, false);
        if (is_array($state) && ($state['status'] ?? '') === 'complete') { return; }
        $role = get_role('subscriber');
        if (!$role) { return; }
        $key = AddonCapabilityKeyV1::wordpress('oj_microbit_learn');
        if (!is_array($state)) {
            $present = array_key_exists($key, $role->capabilities);
            $state = ['status' => $present ? 'complete' : 'pending', 'owned' => !$present];
            if (!add_option(self::OPTION, $state, '', false) || $present) { return; }
        }
        if (($state['status'] ?? '') !== 'pending' || ($state['owned'] ?? false) !== true) { return; }
        if (!array_key_exists($key, $role->capabilities)) { $role->add_cap($key, true); }
        wp_cache_delete(wp_roles()->role_key, 'options');
        $roles = get_option(wp_roles()->role_key, []);
        if (($roles['subscriber']['capabilities'][$key] ?? null) !== true) { return; }
        $state['status'] = 'complete'; update_option(self::OPTION, $state, false);
    }
    public static function removeOwnedGrant(): bool
    {
        $state = get_option(self::OPTION, false);
        if (!is_array($state) || ($state['owned'] ?? false) !== true) { return true; }
        $role = get_role('subscriber');
        $key = class_exists(AddonCapabilityKeyV1::class) ? AddonCapabilityKeyV1::wordpress('oj_microbit_learn') : 'oj_addon_oj_microbit_learn';
        if ($role) {
            wp_cache_delete(wp_roles()->role_key, 'options');
            $roles = get_option(wp_roles()->role_key, []);
            if (($role->capabilities[$key] ?? null) === true || ($roles['subscriber']['capabilities'][$key] ?? null) === true) { $role->remove_cap($key); }
            wp_cache_delete(wp_roles()->role_key, 'options');
            $roles = get_option(wp_roles()->role_key, []);
            if (($roles['subscriber']['capabilities'][$key] ?? null) === true) { return false; }
        }
        delete_option(self::OPTION);
        return get_option(self::OPTION, false) === false;
    }
}
