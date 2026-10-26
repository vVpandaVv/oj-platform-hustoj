<?php
declare(strict_types=1);

namespace FocusTracker\Support;

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use FocusTracker\Addon\Runtime_Bridge;
use Throwable;

/**
 * OJ Platform registers learners as WordPress subscribers. A role grant covers
 * existing and future learners without changing individual user capabilities.
 */
final class Student_Participation_Defaults {
    private const OPTION = 'focus_tracker_student_participation_defaults';

    public static function register(): void {
        // The platform synchronizes its roles at init priority 2 and preserves
        // addon capabilities. Complete this before problem-page configuration.
        add_action('init', array(__CLASS__, 'maybe_apply'), 3);
        add_filter('oj_platform_unprivileged_addon_capabilities', array(__CLASS__, 'unprivileged_capabilities'));
    }

    /** This participation gate grants no account-management privilege. */
    public static function unprivileged_capabilities(array $keys): array {
        if (class_exists(AddonCapabilityKeyV1::class)) {
            $keys[] = AddonCapabilityKeyV1::wordpress(Capabilities::PARTICIPATE);
        }
        return array_values(array_unique($keys));
    }

    public static function maybe_apply(): bool {
        try {
            $state = get_option(self::OPTION, false);
            if (self::complete($state)) { return true; }
            if (! class_exists(AddonCapabilityKeyV1::class) || Runtime_Bridge::contexts() === null
                || ! function_exists('wp_roles') || ! function_exists('get_role')) { return false; }

            $roles = wp_roles();
            if (! is_object($roles) || ! is_string($roles->role_key ?? null) || ! method_exists($roles, 'for_site')) { return false; }
            $key = AddonCapabilityKeyV1::wordpress(Capabilities::PARTICIPATE);
            $stored = self::stored_role($roles);
            if ($stored === null) { return false; }

            if (! is_array($state) || ($state['schemaVersion'] ?? null) !== 1) {
                $present = array_key_exists($key, $stored);
                $state = array('schemaVersion' => 1, 'status' => $present ? 'complete' : 'pending', 'owned' => ! $present);
                // Write ownership before the role change so a retry or uninstall
                // can distinguish this default from a pre-existing manual grant.
                if (! add_option(self::OPTION, $state, '', false)) {
                    return self::complete(get_option(self::OPTION, false));
                }
                if (self::complete($state)) { return true; }
            }

            if (($state['status'] ?? '') !== 'pending' || ($state['owned'] ?? null) !== true) { return false; }
            if (array_key_exists($key, $stored)) {
                // In particular, never replace an explicit role-level denial.
                $state['status'] = 'complete';
                if (! $stored[$key]) { $state['owned'] = false; }
                $roles->for_site();
                self::refresh_current_user();
                return self::save($state);
            }

            $roles->for_site();
            $role = get_role('subscriber');
            if (! is_object($role) || ! method_exists($role, 'add_cap')) { return false; }
            $role->add_cap($key, true);
            $verified = self::stored_role($roles);
            // WP_Role::add_cap() returns void even when update_option() fails.
            // Reload persisted roles before authorizing this request.
            $roles->for_site();
            self::refresh_current_user();
            if ($verified === null || ($verified[$key] ?? null) !== true) { return false; }

            $state['status'] = 'complete';
            return self::save($state);
        } catch (Throwable $error) {
            // An unavailable dependency or option store must never bypass the
            // ordinary capability check or prevent the rest of the site loading.
            return false;
        }
    }

    /** Return false to stop deactivation if its persistent role change failed. */
    public static function deactivate(): bool {
        try {
            $state = get_option(self::OPTION, false);
            if (! is_array($state) || ($state['schemaVersion'] ?? null) !== 1 || ($state['owned'] ?? null) !== true) { return true; }
            if (! class_exists(AddonCapabilityKeyV1::class) || ! function_exists('wp_roles') || ! function_exists('get_role')) { return false; }
            $roles = wp_roles();
            if (! is_object($roles) || ! is_string($roles->role_key ?? null) || ! method_exists($roles, 'for_site')) { return false; }
            $key = AddonCapabilityKeyV1::wordpress(Capabilities::PARTICIPATE);
            $stored = self::stored_role($roles);
            if ($stored === null) { return false; }
            if (($stored[$key] ?? null) !== true) {
                // An administrator already revoked it. Reactivation must keep
                // that choice rather than interpreting it as our cleanup.
                return self::save(array('schemaVersion' => 1, 'status' => 'complete', 'owned' => false));
            }

            $state['status'] = 'pending';
            if (! self::save($state)) { return false; }
            $roles->for_site();
            $role = get_role('subscriber');
            if (! is_object($role) || ! method_exists($role, 'remove_cap')) { return false; }
            $role->remove_cap($key);
            $verified = self::stored_role($roles);
            $roles->for_site();
            self::refresh_current_user();
            return $verified !== null && ! array_key_exists($key, $verified);
        } catch (Throwable $error) { return false; }
    }

    private static function stored_role(object $roles): ?array {
        // The roles option is normally autoloaded. Re-read durable state after a
        // write instead of trusting the already mutated WP_Roles object.
        wp_cache_delete($roles->role_key, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        $stored = get_option($roles->role_key, null);
        return is_array($stored) && is_array($stored['subscriber']['capabilities'] ?? null)
            ? $stored['subscriber']['capabilities'] : null;
    }

    private static function refresh_current_user(): void {
        if (! function_exists('wp_get_current_user')) { return; }
        $user = wp_get_current_user();
        if (is_object($user) && in_array('subscriber', (array) ($user->roles ?? array()), true)
            && method_exists($user, 'get_role_caps')) { $user->get_role_caps(); }
    }

    private static function complete(mixed $state): bool {
        return is_array($state) && ($state['schemaVersion'] ?? null) === 1 && ($state['status'] ?? '') === 'complete';
    }

    private static function save(array $state): bool {
        update_option(self::OPTION, $state, false);
        wp_cache_delete(self::OPTION, 'options');
        return get_option(self::OPTION, false) === $state;
    }
}
