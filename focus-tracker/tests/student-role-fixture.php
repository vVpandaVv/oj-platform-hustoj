<?php
declare(strict_types=1);

/** Native-role API doubles for HTTP tests only; never shipped in the plugin ZIP. */
final class Focus_Student_Fixture_Roles {
    public string $role_key = 'wp_user_roles';
    public array $roles = array();
    public function __construct() { $this->for_site(); }
    public function for_site($site_id = null): void { $this->roles = (array) get_option($this->role_key, array()); }
}
final class Focus_Student_Fixture_Role {
    public array $capabilities;
    public function __construct(private string $name) { $this->capabilities = wp_roles()->roles[$name]['capabilities']; }
    public function add_cap($key, $grant = true): void { $this->write($key, (bool) $grant); }
    public function remove_cap($key): void { $this->write($key, null); }
    private function write(string $key, ?bool $grant): void {
        $roles = (array) get_option('wp_user_roles', array());
        if ($grant === null) { unset($roles[$this->name]['capabilities'][$key]); }
        else { $roles[$this->name]['capabilities'][$key] = $grant; }
        update_option('wp_user_roles', $roles, false);
        wp_roles()->for_site();
        $this->capabilities = wp_roles()->roles[$this->name]['capabilities'];
    }
}
final class Focus_Student_Fixture_User {
    public array $allcaps = array();
    public string $display_name;
    public function __construct(public int $ID, public array $roles, public array $caps) {
        $this->display_name = '계정 ' . $ID;
        $this->get_role_caps();
    }
    public function get_role_caps(): array {
        $merged = array();
        foreach ($this->roles as $role) {
            $merged = array_merge($merged, wp_roles()->roles[$role]['capabilities'] ?? array(), array($role => true));
        }
        return $this->allcaps = array_merge($merged, $this->caps);
    }
}
function wp_roles(): Focus_Student_Fixture_Roles {
    return $GLOBALS['focus_student_roles'] ??= new Focus_Student_Fixture_Roles();
}
function get_role(string $key): ?Focus_Student_Fixture_Role {
    return isset(wp_roles()->roles[$key]) ? new Focus_Student_Fixture_Role($key) : null;
}
function wp_get_current_user(): Focus_Student_Fixture_User {
    return $GLOBALS['focus_student_user'] ??= student_role_fixture_user(get_current_user_id());
}
function student_role_fixture_user(int $id): Focus_Student_Fixture_User {
    $data = $GLOBALS['focus_student_config']['users'][(string) $id] ?? array();
    return new Focus_Student_Fixture_User($id, (array) ($data['roles'] ?? array()), (array) ($data['caps'] ?? array()));
}
function student_role_fixture_enabled(): bool { return ! empty($GLOBALS['focus_student_config']['enabled']); }
function student_role_fixture_can(string $key): bool {
    return get_current_user_id() > 0 && ! empty(wp_get_current_user()->allcaps[$key]);
}
function student_role_fixture_load(bool $apply = true): void {
    $config = get_option('focus_tracker_fixture_student_roles', array());
    $GLOBALS['focus_student_config'] = is_array($config) ? $config : array();
    if (! student_role_fixture_enabled()) { return; }
    $GLOBALS['focus_test_user_id'] = (int) ($config['actorId'] ?? 1);
    unset($GLOBALS['focus_student_roles'], $GLOBALS['focus_student_user']);
    wp_roles();
    // This is the production init callback, after the accepted runtime connects.
    \FocusTracker\Support\Student_Participation_Defaults::register();
    if ($apply) { \FocusTracker\Support\Student_Participation_Defaults::maybe_apply(); }
}
function student_role_fixture_reset(): void {
    foreach (array('focus_tracker_fixture_student_roles', 'focus_tracker_student_participation_defaults', 'wp_user_roles') as $key) { delete_option($key); }
    unset($GLOBALS['focus_student_config'], $GLOBALS['focus_student_roles'], $GLOBALS['focus_student_user']);
}
function student_role_fixture_config(array $input): array {
    if (! empty($input['reset'])) {
        student_role_fixture_reset();
        $teacher = array('read' => true);
        foreach (array(\FocusTracker\Support\Capabilities::VIEW, \FocusTracker\Support\Capabilities::MANAGE) as $key) {
            $teacher[\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress($key)] = true;
        }
        update_option('wp_user_roles', array(
            'subscriber' => array('name' => '학습자', 'capabilities' => array('read' => true, 'level_0' => true)),
            'teacher' => array('name' => '교사', 'capabilities' => $teacher),
        ), false);
        update_option('focus_tracker_fixture_student_roles', array('enabled' => true, 'actorId' => 1, 'users' => array(
            1 => array('roles' => array('subscriber'), 'caps' => array()),
            3 => array('roles' => array('teacher'), 'caps' => array()),
        )), false);
    }
    $config = (array) get_option('focus_tracker_fixture_student_roles', array());
    foreach (array('enabled', 'actorId') as $key) { if (array_key_exists($key, $input)) { $config[$key] = $input[$key]; } }
    foreach ((array) ($input['users'] ?? array()) as $id => $user) { $config['users'][$id] = $user; }
    if (! empty($input['newStudentId'])) { $config['users'][(int) $input['newStudentId']] = array('roles' => array('subscriber'), 'caps' => array()); }
    update_option('focus_tracker_fixture_student_roles', $config, false);
    student_role_fixture_load(! empty($input['applyDefaults']));
    if (array_key_exists('roleTracking', $input)) {
        $key = \CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress(\FocusTracker\Support\Capabilities::PARTICIPATE);
        if ($input['roleTracking'] === null) { get_role('subscriber')->remove_cap($key); }
        else { get_role('subscriber')->add_cap($key, (bool) $input['roleTracking']); }
        wp_get_current_user()->get_role_caps();
    }
    return student_role_fixture_snapshot();
}
function student_role_fixture_snapshot(): array {
    $caps = array();
    foreach (wp_roles()->roles as $key => $role) { $caps[$key] = $role['capabilities']; }
    return array('actorId' => get_current_user_id(), 'roles' => wp_get_current_user()->roles,
        'roleCaps' => $caps, 'userCaps' => wp_get_current_user()->caps,
        'participateCapability' => \CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress(\FocusTracker\Support\Capabilities::PARTICIPATE),
        'canTrack' => \FocusTracker\Support\Capabilities::can_participate(),
        'migration' => get_option('focus_tracker_student_participation_defaults', null));
}
