<?php
declare(strict_types=1);

// Isolated WordPress role/user contract model, with durable SQLite options.
// It deliberately does not use the integration fixture's permissive cap stub.
if (PHP_SAPI !== 'cli') { exit(1); }
define('FOCUS_TRACKER_PATH', dirname(__DIR__) . '/');
require_once FOCUS_TRACKER_PATH . 'src/Core/Autoloader.php';
\FocusTracker\Core\Autoloader::register();
$platform = dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform';
require_once $platform . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use FocusTracker\Addon\Runtime_Bridge;
use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Student_Participation_Defaults as Defaults;

final class Student_Defaults_Test_Db {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public PDO $pdo;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $this->pdo->exec('CREATE TABLE wp_options (option_name TEXT PRIMARY KEY, option_value TEXT NOT NULL)');
    }
    public function query(string $sql): int|false { return $this->pdo->exec($sql); }
    public function prepare(string $sql, ...$values): string {
        $i = 0;
        return preg_replace_callback('/%s/', function () use ($values, &$i): string { return $this->pdo->quote((string) $values[$i++]); }, $sql);
    }
}
$wpdb = new Student_Defaults_Test_Db();
$GLOBALS['student_fail_add'] = '';
$GLOBALS['student_fail_update'] = '';
function get_option(string $key, mixed $default = false): mixed {
    global $wpdb;
    $query = $wpdb->pdo->prepare('SELECT option_value FROM wp_options WHERE option_name = ?');
    $query->execute(array($key));
    $value = $query->fetchColumn();
    return $value === false ? $default : unserialize($value, array('allowed_classes' => false));
}
function add_option(string $key, mixed $value = '', string $deprecated = '', bool $autoload = true): bool {
    global $wpdb;
    if ($GLOBALS['student_fail_add'] === $key) { $GLOBALS['student_fail_add'] = ''; return false; }
    $query = $wpdb->pdo->prepare('INSERT INTO wp_options VALUES (?, ?) ON CONFLICT DO NOTHING');
    $query->execute(array($key, serialize($value)));
    return $query->rowCount() === 1;
}
function update_option(string $key, mixed $value, mixed $autoload = null): bool {
    global $wpdb;
    if ($GLOBALS['student_fail_update'] === $key) { $GLOBALS['student_fail_update'] = ''; return false; }
    $query = $wpdb->pdo->prepare('INSERT INTO wp_options VALUES (?, ?) ON CONFLICT DO UPDATE SET option_value = excluded.option_value');
    $query->execute(array($key, serialize($value)));
    return true;
}
function delete_option(string $key): bool {
    global $wpdb;
    $query = $wpdb->pdo->prepare('DELETE FROM wp_options WHERE option_name = ?');
    return $query->execute(array($key));
}
function wp_cache_delete(string $key, string $group = ''): bool { return true; }
function add_action(string $hook, callable $callback, int $priority = 10, int $accepted = 1): void { $GLOBALS['student_actions'][$hook][$priority][] = $callback; }
function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted = 1): void { $GLOBALS['student_filters'][$hook][] = $callback; }
function wp_clear_scheduled_hook(string $hook): void {}
function apply_filters(string $hook, mixed $value): mixed {
    foreach ($GLOBALS['student_filters'][$hook] ?? array() as $callback) { $value = $callback($value); }
    return $value;
}
function is_super_admin(?int $user_id = null): bool { return false; }
function get_user_by(string $field, int $value): object|false {
    return $field === 'id' && wp_get_current_user()->ID === $value ? wp_get_current_user() : false;
}

final class Student_Defaults_Test_Role {
    public function __construct(public string $name, public array $capabilities) {}
    public function add_cap(string $key, bool $grant = true): void {
        ++$GLOBALS['student_add_calls'];
        $this->capabilities[$key] = $grant;
        $roles = wp_roles();
        $roles->roles[$this->name]['capabilities'][$key] = $grant;
        update_option($roles->role_key, $roles->roles);
    }
    public function remove_cap(string $key): void {
        unset($this->capabilities[$key]);
        $roles = wp_roles();
        unset($roles->roles[$this->name]['capabilities'][$key]);
        update_option($roles->role_key, $roles->roles);
    }
}
final class Student_Defaults_Test_Roles {
    public string $role_key = 'wp_user_roles';
    public array $roles = array();
    public array $objects = array();
    public function __construct() { $this->for_site(); }
    public function for_site(mixed $site_id = null): void {
        $this->roles = get_option($this->role_key, array());
        $this->objects = array();
        foreach ($this->roles as $key => $row) { $this->objects[$key] = new Student_Defaults_Test_Role($key, $row['capabilities']); }
    }
}
function wp_roles(): Student_Defaults_Test_Roles { return $GLOBALS['student_roles']; }
function get_role(string $key): ?Student_Defaults_Test_Role { return wp_roles()->objects[$key] ?? null; }
final class Student_Defaults_Test_User {
    public array $allcaps = array();
    public int $refreshes = 0;
    public function __construct(public int $ID, public array $roles, public array $caps = array()) { $this->get_role_caps(); }
    public function get_role_caps(): array {
        ++$this->refreshes;
        $this->allcaps = array();
        foreach ($this->roles as $role) { $this->allcaps = array_merge($this->allcaps, get_role($role)?->capabilities ?? array()); }
        $this->allcaps = array_merge($this->allcaps, array_fill_keys($this->roles, true), $this->caps);
        return $this->allcaps;
    }
}
function wp_get_current_user(): Student_Defaults_Test_User { return $GLOBALS['student_current']; }
function current_user_can(string $key): bool { return wp_get_current_user()->ID > 0 && ! empty(wp_get_current_user()->allcaps[$key]); }
function is_user_logged_in(): bool { return wp_get_current_user()->ID > 0; }

$checks = 0;
$option = 'focus_tracker_student_participation_defaults';
$cap = Api\AddonCapabilityKeyV1::wordpress(Capabilities::PARTICIPATE);
function student_expect(mixed $expected, mixed $actual, string $why): void {
    global $checks;
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($why . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function student_reset(array $caps = array('read' => true), bool $present = true): void {
    global $wpdb;
    $wpdb->query('DELETE FROM wp_options');
    $roles = array('administrator' => array('capabilities' => array('read' => true, 'manage_options' => true)),
        'oj_class_manager' => array('capabilities' => array('read' => true, 'oj_access_console' => true, 'oj_manage_classes' => true)));
    if ($present) { $roles['subscriber'] = array('capabilities' => $caps); }
    update_option('wp_user_roles', $roles);
    $GLOBALS['student_roles'] = new Student_Defaults_Test_Roles();
    $GLOBALS['student_current'] = new Student_Defaults_Test_User(10, array('subscriber'));
    $GLOBALS['student_add_calls'] = 0;
    $GLOBALS['student_fail_add'] = '';
    $GLOBALS['student_fail_update'] = '';
}

Defaults::register();
student_expect(array(Defaults::class, 'maybe_apply'), $GLOBALS['student_actions']['init'][3][0], 'Migration is registered after the platform init-2 role synchronization');
student_expect(array(Defaults::class, 'unprivileged_capabilities'), $GLOBALS['student_filters']['oj_platform_unprivileged_addon_capabilities'][0], 'Participation is declared through the platform unprivileged-cap contract');
student_expect(array('oj_addon_other_readonly', $cap), Defaults::unprivileged_capabilities(array('oj_addon_other_readonly', $cap)), 'The filter preserves other contracts and adds only the mapped participation key once');
student_reset();
student_expect(false, Defaults::maybe_apply(), 'An addon without an accepted runtime cannot grant rights');
student_expect(false, get_option($option, false), 'An unavailable platform never completes the migration');
student_expect(false, Capabilities::can_participate(), 'No permission fallback bypasses the existing capability check');

$runtime = new class implements Api\AddonRuntimeContextV1 {
    public function snapshot(): Api\AddonRuntimeSnapshotV1 { return new Api\AddonRuntimeSnapshotV1('focus-tracker', '1.0.0-test', '>=2.3.22 <3.0.0', '2.3.22', '^1.4', '1.4.0', 'accepted', '', 'student-defaults', array(), 'student-defaults'); }
    public function contexts(): Api\AddonContextGatewayV1 {
        return new class implements Api\AddonContextGatewayV1 {
            public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $q): Api\AddonContextPageV1 { throw new LogicException('Not needed'); }
            public function authorize(Api\AddonContextAccessRequestV1 $q): Api\AddonContextAccessDecisionV1 { throw new LogicException('Not needed'); }
            public function snapshot(Api\AddonContextSnapshotRequestV1 $q): Api\AddonContextSnapshotResultV1 { throw new LogicException('Not needed'); }
        };
    }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('Not needed'); }
};
Runtime_Bridge::connect($runtime);
student_reset(array('read' => true), false);
student_expect(false, Defaults::maybe_apply(), 'A missing subscriber role is retried instead of invented');
student_expect(false, get_option($option, false), 'A missing role never records migration completion');
$roles = get_option('wp_user_roles');
$roles['subscriber'] = array('capabilities' => array('read' => true));
update_option('wp_user_roles', $roles);
wp_roles()->for_site();
student_expect(true, Defaults::maybe_apply(), 'A later-created subscriber role gets its one-time default');
student_expect(true, Capabilities::can_participate(), 'The already loaded learner is refreshed in the same request');
student_expect(true, get_option($option)['owned'], 'Only a newly introduced role grant records plugin ownership');
$identity = new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity(10, 'student', 'student@example.test', '학생', array('subscriber'));
$detector = new \CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressPrivilegedAccountDetector();
student_expect(false, $detector->isPrivileged($identity), 'The actual platform detector keeps an automatic participant a normal learner');
wp_get_current_user()->caps['oj_manage_users'] = true;
wp_get_current_user()->get_role_caps();
student_expect(true, $detector->isPrivileged($identity), 'The participation declaration cannot exempt a core management capability');
unset(wp_get_current_user()->caps['oj_manage_users']);
wp_get_current_user()->caps['oj_addon_other_sensitive'] = true;
wp_get_current_user()->get_role_caps();
student_expect(true, $detector->isPrivileged($identity), 'Undeclared addon capabilities retain privileged-account protection');
unset(wp_get_current_user()->caps['oj_addon_other_sensitive']);
wp_get_current_user()->get_role_caps();
student_expect(false, Capabilities::can_view_self(), 'The participation migration does not grant unrelated learner capabilities');
student_expect(false, Capabilities::can_manage(), 'The migration never grants educator access');
student_expect(false, isset(get_role('oj_class_manager')->capabilities[$cap]), 'Teacher-only roles are unchanged');
student_expect(false, isset(get_role('administrator')->capabilities[$cap]), 'Administrator role capabilities are unchanged');
student_expect(1, $GLOBALS['student_add_calls'], 'Existing learners need one role write instead of per-user writes');
student_expect(true, Defaults::maybe_apply(), 'A completed migration is idempotent');
student_expect(1, $GLOBALS['student_add_calls'], 'Repeated requests do not rewrite roles');
$GLOBALS['student_current'] = new Student_Defaults_Test_User(11, array('subscriber'));
student_expect(true, Capabilities::can_participate(), 'A newly created learner inherits the same default without another migration');
$GLOBALS['student_current'] = new Student_Defaults_Test_User(12, array('subscriber'), array($cap => false));
student_expect(false, Capabilities::can_participate(), 'An individual learner denial overrides role inheritance');
$GLOBALS['student_current'] = new Student_Defaults_Test_User(13, array('oj_class_manager'));
student_expect(false, Capabilities::can_participate(), 'A teacher without the learner role gets no new participation right');
$GLOBALS['student_current'] = new Student_Defaults_Test_User(0, array('subscriber'));
student_expect(false, Capabilities::can_participate(), 'Guest requests cannot participate even if a role model carries a grant');
$GLOBALS['student_current'] = new Student_Defaults_Test_User(10, array('subscriber'));
get_role('subscriber')->remove_cap($cap);
wp_roles()->for_site();
wp_get_current_user()->get_role_caps();
student_expect(true, Defaults::maybe_apply(), 'An administrator role-level revocation does not restart migration');
student_expect(false, Capabilities::can_participate(), 'A completed default never overrides a later matrix revocation');
student_expect(true, Defaults::deactivate(), 'Deactivation respects an already revoked default');
student_expect(false, get_option($option)['owned'], 'An administrative revocation relinquishes ownership');
student_expect(true, Defaults::maybe_apply(), 'Reactivation preserves the completed administrative decision');
student_expect(false, Capabilities::can_participate(), 'Reactivation does not restore an administratively removed grant');

student_reset(array('read' => true, $cap => false));
student_expect(true, Defaults::maybe_apply(), 'A pre-existing explicit role denial is a completed policy decision');
student_expect(false, get_role('subscriber')->capabilities[$cap], 'A role denial is not replaced with a default grant');
student_expect(false, get_option($option)['owned'], 'A pre-existing denial is never plugin-owned');
student_expect(0, $GLOBALS['student_add_calls'], 'Explicit role denials produce no grant write');
student_reset(array('read' => true, $cap => true));
student_expect(true, Defaults::maybe_apply(), 'An existing manual grant is preserved');
student_expect(false, get_option($option)['owned'], 'Manual role grants are not claimed by the plugin');
student_expect(true, Defaults::deactivate(), 'Manual role grants survive deactivation');
student_expect(true, get_role('subscriber')->capabilities[$cap], 'Deactivation does not revoke a manual grant');

student_reset();
$GLOBALS['student_fail_add'] = $option;
student_expect(false, Defaults::maybe_apply(), 'Migration does not grant before its ownership record is durable');
student_expect(false, isset(get_role('subscriber')->capabilities[$cap]), 'An ownership-write failure does not mutate roles');
student_expect(true, Defaults::maybe_apply(), 'An initial option-store failure retries safely');
student_reset();
$GLOBALS['student_fail_update'] = 'wp_user_roles';
student_expect(false, Defaults::maybe_apply(), 'A void-returning role API cannot hide a durable storage failure');
student_expect('pending', get_option($option)['status'], 'Failed grants remain pending for retry');
student_expect(false, Capabilities::can_participate(), 'A failed role write does not authorize the current cached learner');
student_expect(false, isset(get_role('subscriber')->capabilities[$cap]), 'Failed in-memory role changes are refreshed from storage');
student_expect(true, Defaults::maybe_apply(), 'A durable role write succeeds on the next request');
student_expect(true, Capabilities::can_participate(), 'Successful retry refreshes the same learner');
student_reset();
$GLOBALS['student_fail_update'] = $option;
student_expect(false, Defaults::maybe_apply(), 'A failed completion marker is not reported as complete');
student_expect('pending', get_option($option)['status'], 'Ownership survives completion-write failure');
student_expect(true, Capabilities::can_participate(), 'A durably saved grant is usable despite a retryable marker failure');
student_expect(true, Defaults::maybe_apply(), 'A completion marker retries without granting twice');
student_expect(1, $GLOBALS['student_add_calls'], 'A marker retry does not write the same role again');
student_expect(true, get_option($option)['owned'], 'A marker retry preserves ownership of the original default');

student_expect(true, Defaults::deactivate(), 'An owned live default is revoked safely on deactivation');
student_expect(false, isset(get_role('subscriber')->capabilities[$cap]), 'An inactive addon leaves no automatic participant grant behind');
student_expect('pending', get_option($option)['status'], 'Only automatic cleanup records a reactivation restore');
student_expect(false, Capabilities::can_participate(), 'The current learner cache reflects deactivation');
student_expect(true, Defaults::maybe_apply(), 'Reactivation restores the role grant removed by the addon itself');
student_expect(true, Capabilities::can_participate(), 'The reactivated learner inherits the restored default');
$GLOBALS['student_fail_update'] = $option;
student_expect(false, Defaults::deactivate(), 'Deactivation stops if durable restore intent cannot be saved');
student_expect(true, get_role('subscriber')->capabilities[$cap], 'A failed deactivation marker leaves the existing role grant intact');
$GLOBALS['student_fail_update'] = 'wp_user_roles';
student_expect(false, Defaults::deactivate(), 'Deactivation stops when role revocation cannot be persisted');
student_expect(true, get_role('subscriber')->capabilities[$cap], 'A failed role revocation restores the persisted current capabilities');
student_expect(true, Defaults::maybe_apply(), 'A stopped deactivation can return to a complete active default');
get_role('subscriber')->add_cap($cap, false);
wp_roles()->for_site();
student_expect(true, Defaults::deactivate(), 'A new explicit denial is preserved during deactivation');
student_expect('complete', get_option($option)['status'], 'An explicit administrative denial never records a restoration intent');
student_expect(false, get_option($option)['owned'], 'An explicit administrative denial clears automatic ownership');
student_expect(true, Defaults::maybe_apply(), 'A denied grant remains completed across reactivation');
student_expect(false, get_role('subscriber')->capabilities[$cap], 'An explicit denial survives reactivation');

// Execute the real standalone uninstall entry, including its ownership rules.
define('WP_UNINSTALL_PLUGIN', true);
require dirname(__DIR__) . '/uninstall.php';
student_expect(false, get_option($option, false), 'Uninstall removes the migration record');
student_expect(false, get_role('subscriber')->capabilities[$cap], 'Uninstall preserves an explicit role denial');
student_reset(array('read' => true, $cap => true));
Defaults::maybe_apply();
require dirname(__DIR__) . '/uninstall.php';
student_expect(true, get_role('subscriber')->capabilities[$cap], 'Uninstall preserves a pre-existing manual role grant');
student_reset();
Defaults::maybe_apply();
require dirname(__DIR__) . '/uninstall.php';
student_expect(false, isset(get_role('subscriber')->capabilities[$cap]), 'Uninstall removes only its own automatic role grant');
student_expect(array('read' => true, 'manage_options' => true), get_role('administrator')->capabilities, 'Uninstall leaves other role capabilities unchanged');

echo "Student participation defaults: {$checks} SQL-backed role checks passed\n";
