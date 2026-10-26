<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\OjPrimm\Core\Autoloader::register();

$GLOBALS['refresh_actor'] = 19;
$GLOBALS['refresh_cached_grant'] = true;
$GLOBALS['refresh_database_grant'] = false;
$GLOBALS['refresh_cached_roles_grant'] = true;
$GLOBALS['refresh_filters'] = [];
$GLOBALS['refresh_changes'] = [];
function get_current_user_id(): int { return $GLOBALS['refresh_actor']; }
function current_user_can(string $capability): bool { return $GLOBALS['refresh_cached_grant']; }
function clean_user_cache(int $id): void { $GLOBALS['refresh_changes'][] = ['user', $id]; }
function wp_cache_delete(mixed $key, string $group): void { $GLOBALS['refresh_changes'][] = [$group, $key]; }
function add_filter(string $name, callable $callback, int $priority = 10): void { $GLOBALS['refresh_filters'][$name] = $callback; }
function remove_filter(string $name, callable $callback, int $priority = 10): void { unset($GLOBALS['refresh_filters'][$name]); }
function maybe_unserialize(string $value): mixed { return unserialize($value, ['allowed_classes' => false]); }
$wpdb = new class {
    public string $options = 'test_options'; public string $last_error = ''; public bool $fail = false;
    public function prepare(string $sql, mixed ...$values): string { return $sql; }
    public function get_var(string $sql): ?string {
        $GLOBALS['refresh_changes'][] = ['database', 'roles'];
        return $this->fail ? null : serialize(['subscriber' => ['capabilities' => ['oj_addon_oj_primm_learn' => $GLOBALS['refresh_database_grant']]]]);
    }
};
function wp_roles(): object
{
    return new class {
        public string $role_key = 'test_user_roles';
        public function for_site(): void {
            $GLOBALS['refresh_changes'][] = ['roles', 'reload'];
            $filter = $GLOBALS['refresh_filters']['pre_option_' . $this->role_key] ?? null;
            if ($filter !== null) { $GLOBALS['refresh_cached_roles_grant'] = ($filter())['subscriber']['capabilities']['oj_addon_oj_primm_learn']; }
        }
    };
}
function wp_set_current_user(int $id): void
{
    // WordPress reuses the current WP_User if the same ID is passed again.
    if ($id === $GLOBALS['refresh_actor']) { return; }
    $GLOBALS['refresh_actor'] = $id;
    $GLOBALS['refresh_cached_grant'] = $id > 0 && $GLOBALS['refresh_cached_roles_grant'];
    $GLOBALS['refresh_changes'][] = ['actor', $id];
}
$gateway = new \OjPrimm\Support\PlatformGateway(static fn () => null);
if (! current_user_can('oj_addon_oj_primm_teach')) { throw new RuntimeException('Invalid cache fixture'); }
$gateway->refreshActor();
$checks = [get_current_user_id() === 19, ! current_user_can('oj_addon_oj_primm_teach'),
    in_array(['user_meta', 19], $GLOBALS['refresh_changes'], true),
    in_array(['database', 'roles'], $GLOBALS['refresh_changes'], true),
    in_array(['roles', 'reload'], $GLOBALS['refresh_changes'], true),
    in_array(['actor', 0], $GLOBALS['refresh_changes'], true),
    ! in_array(['options', 'alloptions'], $GLOBALS['refresh_changes'], true),
    ! in_array(['options', 'notoptions'], $GLOBALS['refresh_changes'], true),
    $GLOBALS['refresh_filters'] === []];
if (in_array(false, $checks, true)) { throw new RuntimeException('Long AI calls must refresh roles and the current WP_User before committing.'); }
$wpdb->fail = true;
try { $gateway->refreshActor(); throw new RuntimeException('Missing roles must fail closed.'); }
catch (DomainException) { $checks[] = true; }
echo 'PRIMM actor refresh checks passed: ' . count($checks) . "\n";
