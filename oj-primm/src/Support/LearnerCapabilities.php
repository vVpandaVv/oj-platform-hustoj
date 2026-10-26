<?php
declare(strict_types=1);
namespace OjPrimm\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use OjPrimm\Core\Plugin;

final class LearnerCapabilities
{
    private const OPTION = 'oj_primm_owned_learner_cap';
    public static function register(): void
    {
        add_filter('oj_platform_unprivileged_addon_capabilities', [self::class, 'unprivileged']);
        add_action('init', [self::class, 'apply'], 3);
    }
    public static function unprivileged(array $keys): array
    {
        if (class_exists(AddonCapabilityKeyV1::class)) { $keys[] = AddonCapabilityKeyV1::wordpress('oj_primm_learn'); }
        return array_values(array_unique($keys));
    }
    public static function apply(): void
    {
        if (! class_exists(AddonCapabilityKeyV1::class) || Plugin::runtime()?->snapshot()->status() !== 'accepted') { return; }
        $state = get_option(self::OPTION, false);
        if (is_array($state) && ($state['status'] ?? '') === 'complete') { return; }
        $role = get_role('subscriber');
        if (! is_object($role)) { return; }
        $key = AddonCapabilityKeyV1::wordpress('oj_primm_learn');
        if (! is_array($state)) {
            $present = array_key_exists($key, $role->capabilities);
            $state = ['status' => $present ? 'complete' : 'pending', 'owned' => ! $present];
            // Record ownership before mutation and preserve manual grants/denials.
            if (! add_option(self::OPTION, $state, '', false) || $present) { return; }
        }
        if (($state['status'] ?? '') !== 'pending' || ($state['owned'] ?? false) !== true) { return; }
        if (! array_key_exists($key, $role->capabilities)) { $role->add_cap($key, true); }
        $persisted = self::persistedRole();
        $roles = wp_roles();
        $roles->for_site();
        if (($persisted[$key] ?? null) !== true) { return; }
        $state['status'] = 'complete';
        update_option(self::OPTION, $state, false);
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            if (is_object($user) && in_array('subscriber', (array) $user->roles, true)) { $user->get_role_caps(); }
        }
    }
    public static function removeOwnedGrant(): bool
    {
        $state = get_option(self::OPTION, false);
        if (! is_array($state) || ($state['owned'] ?? false) !== true) { return true; }
        $role = get_role('subscriber');
        if (! is_object($role)) { return false; }
        $key = class_exists(AddonCapabilityKeyV1::class) ? AddonCapabilityKeyV1::wordpress('oj_primm_learn') : 'oj_addon_oj_primm_learn';
        if (($role->capabilities[$key] ?? null) === true) { $role->remove_cap($key); }
        $persisted = self::persistedRole();
        wp_roles()->for_site();
        if (($persisted[$key] ?? null) === true) { return false; }
        delete_option(self::OPTION);
        return true;
    }

    private static function persistedRole(): array
    {
        $roles = wp_roles();
        wp_cache_delete($roles->role_key, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        $stored = get_option($roles->role_key, null);
        return is_array($stored) && is_array($stored['subscriber']['capabilities'] ?? null)
            ? $stored['subscriber']['capabilities'] : [];
    }
}
