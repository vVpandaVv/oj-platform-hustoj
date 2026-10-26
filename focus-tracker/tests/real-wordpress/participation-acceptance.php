<?php
declare(strict_types=1);

/** Native, disposable-site acceptance. No test-bootstrap, fake gateways or SQL adapter. */
use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressErrorReporter;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressHookRegistrar;
use CodingPanda\OjPlatform\Modules\Auth\Application\RegistrationInput;
use CodingPanda\OjPlatform\Modules\Auth\Application\RegistrationService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAdministrationService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressPrivilegedAccountDetector;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserApiGateway;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupSaveCommand;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress\WordPressAddonCapabilityAssignmentGateway;
use CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService;
use FocusTracker\Addon\Context_Resolver;
use FocusTracker\Addon\Runtime_Bridge;
use FocusTracker\Database\Check_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Student_Participation_Defaults;

if (PHP_SAPI !== 'cli') { throw new RuntimeException('Run from PHP CLI or WP-CLI on a disposable site.'); }
$acceptanceConfigPath = getenv('FOCUS_TRACKER_REAL_WP_CONFIG');
if (! is_string($acceptanceConfigPath) || ! is_file($acceptanceConfigPath)) {
    throw new RuntimeException('FOCUS_TRACKER_REAL_WP_CONFIG must name the disposable-site config.json.');
}
$acceptanceConfig = json_decode((string) file_get_contents($acceptanceConfigPath), true, 512, JSON_THROW_ON_ERROR);
$acceptancePhase = $args[0] ?? ($_SERVER['argv'][1] ?? 'verify');
$acceptancePhases = array('prepare', 'verify', 'lifecycle-deactivate', 'lifecycle-activate', 'lifecycle-restored', 'lifecycle-denied', 'lifecycle-revoked', 'lifecycle-final');
if (! in_array($acceptancePhase, $acceptancePhases, true)) { throw new RuntimeException('Unknown native acceptance phase.'); }
if (! defined('ABSPATH')) {
    $acceptanceWpLoad = rtrim((string) ($acceptanceConfig['wpPath'] ?? ''), '/\\') . '/wp-load.php';
    if (! is_file($acceptanceWpLoad)) { throw new RuntimeException('The configured wpPath has no wp-load.php.'); }
    $_SERVER['HTTP_HOST'] = parse_url((string) $acceptanceConfig['baseUrl'], PHP_URL_HOST) . ':' . parse_url((string) $acceptanceConfig['baseUrl'], PHP_URL_PORT);
    $_SERVER['REQUEST_URI'] = '/';
    require $acceptanceWpLoad;
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$acceptanceAssertions = array();
function native_participation_assert(bool $condition, string $message): void {
    global $acceptanceAssertions, $wpdb;
    if (! $condition) { throw new RuntimeException($message . ($wpdb->last_error !== '' ? ' / DB: ' . $wpdb->last_error : '')); }
    $acceptanceAssertions[] = $message;
}
function native_participation_write(string $path, array $value): void {
    if (file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write the private local acceptance artifact.');
    }
}
function native_participation_user(int $id): WP_User {
    wp_set_current_user(0);
    $user = wp_set_current_user($id);
    $user->get_role_caps();
    return $user;
}
function native_participation_rest(string $method, string $path, array $body = array(), ?string $nonce = null): WP_REST_Response {
    $request = new WP_REST_Request($method, '/focus-tracker/v1' . $path);
    $nonce ??= wp_create_nonce('wp_rest');
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-WP-Nonce', $nonce);
    $request->set_query_params(array('_wpnonce' => $nonce));
    $request->set_body((string) wp_json_encode($body));
    return rest_do_request($request);
}
function native_participation_ok(WP_REST_Response $response, string $label): array {
    $body = $response->get_data();
    native_participation_assert($response->get_status() >= 200 && $response->get_status() < 300 && is_array($body), $label . ' (HTTP ' . $response->get_status() . ')');
    return $body;
}
function native_participation_packet(array $fixture, int $seq, ?int $at = null): array {
    $at ??= Clock::now_ms() - 2;
    return array('user_id' => $fixture['students'][0]['userId'], 'problem_id' => $fixture['problem']['id'],
        'session_uid' => $fixture['runId'] . '-ordinary-tab', 'session_started_at' => $fixture['sourceStartedAtMs'],
        'events' => array(array('seq' => $seq, 'type' => 'heartbeat', 'occurred_at' => $at,
            'meta' => array('visible' => true, 'focused' => true))));
}
function native_participation_wait(int $boundaryMs): void {
    while (($remaining = $boundaryMs - Clock::now_ms()) > 0) { usleep(min(100000, $remaining * 1000)); }
}

native_participation_assert(in_array(wp_get_environment_type(), array('local', 'development', 'staging'), true), 'The site explicitly identifies itself as a disposable non-production environment');
native_participation_assert(in_array(parse_url(home_url(), PHP_URL_HOST), array('127.0.0.1', 'localhost', '::1'), true), 'The acceptance site uses loopback');
native_participation_assert($wpdb instanceof wpdb && ! str_contains(strtolower(get_class($wpdb)), 'sqlite'), 'Native WordPress wpdb is active without a SQLite adapter');
native_participation_assert(str_contains(str_replace('\\', '/', (string) (new ReflectionFunction('wp_insert_user'))->getFileName()), '/wp-includes/user.php'), 'Account APIs come from the native WordPress core');
$acceptanceDatabaseVersion = (string) $wpdb->get_var('SELECT VERSION()');
native_participation_assert($acceptanceDatabaseVersion !== '' && is_array($wpdb->get_results('SHOW ENGINES', ARRAY_A)), 'The database answers native MySQL/MariaDB inspection');
native_participation_assert(defined('OJ_PLATFORM_VERSION') && version_compare(OJ_PLATFORM_VERSION, '2.3.22', '>='), 'The required real platform version is active');
$acceptanceEnvironment = PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE, OJ_PLATFORM_VERSION, '6.6', '8.3');
$acceptanceContainer = (new CompositionRoot($acceptanceEnvironment, new WordPressHookRegistrar(), new WordPressErrorReporter()))->build();
$acceptanceContainer->get(Kernel::class)->boot();
$acceptanceProfiles = $acceptanceContainer->get(UserProfileRepository::class);
$acceptanceGroups = $acceptanceContainer->get(GroupService::class);
$acceptanceOutput = rtrim((string) ($acceptanceConfig['outputDir'] ?? dirname($acceptanceConfigPath) . '/artifacts'), '/\\');
if (! is_dir($acceptanceOutput) && ! mkdir($acceptanceOutput, 0700, true) && ! is_dir($acceptanceOutput)) { throw new RuntimeException('Could not create private acceptance output directory.'); }
$acceptanceFixturePath = $acceptanceOutput . '/participation-fixture.json';
$acceptanceAdmin = get_user_by('login', (string) $acceptanceConfig['admin']['username']);
native_participation_assert($acceptanceAdmin instanceof WP_User && user_can($acceptanceAdmin, 'manage_options'), 'The configured fixture owner is a native WordPress administrator');
native_participation_user($acceptanceAdmin->ID);
if ($acceptanceProfiles->find($acceptanceAdmin->ID) === null) {
    $acceptanceProfiles->create(new UserProfile($acceptanceAdmin->ID, null, '1990-01-01', AccessStatus::Active, false));
}

if (str_starts_with($acceptancePhase, 'lifecycle-')) {
    native_participation_assert(is_file($acceptanceFixturePath), 'The native participation fixture exists for lifecycle verification');
    $fixture = json_decode((string) file_get_contents($acceptanceFixturePath), true, 512, JSON_THROW_ON_ERROR);
    native_participation_assert(($fixture['verifiedByNativePhp'] ?? false) === true, 'The ordinary participation acceptance completed before lifecycle changes');
    $capability = AddonCapabilityKeyV1::wordpress('focus_tracker_participate');
    $plugin = 'focus-tracker/focus-tracker.php';
    $previous = $fixture['lifecycle']['phase'] ?? null;
    $expectedPrevious = array('lifecycle-activate' => 'lifecycle-deactivate', 'lifecycle-restored' => 'lifecycle-activate',
        'lifecycle-denied' => 'lifecycle-restored', 'lifecycle-revoked' => 'lifecycle-denied', 'lifecycle-final' => 'lifecycle-revoked');
    if (isset($expectedPrevious[$acceptancePhase])) {
        native_participation_assert($previous === $expectedPrevious[$acceptancePhase], 'Lifecycle phases run in the required fresh-request order');
    }
    if ($acceptancePhase === 'lifecycle-deactivate') {
        native_participation_assert(is_plugin_active($plugin) && (get_role('subscriber')->capabilities[$capability] ?? null) === true, 'The native owned grant is active before core deactivation');
        $fixture['lifecycle'] = array('originalState' => get_option('focus_tracker_student_participation_defaults', false),
            'originalRoleGrant' => get_role('subscriber')->capabilities[$capability]);
        deactivate_plugins($plugin, false, false);
        native_participation_assert(! is_plugin_active($plugin), 'WordPress core deactivate_plugins removes the addon from the active plugin list');
        native_participation_assert(! array_key_exists($capability, get_role('subscriber')->capabilities), 'The actual deactivation hook durably removes only the owned learner grant');
        native_participation_assert(current_user_can(AddonCapabilityKeyV1::wordpress('focus_tracker_manage')), 'The manually configured teacher role grant survives deactivation');
        native_participation_assert((new WP_User($fixture['deniedStudent']['userId']))->caps[$capability] === false, 'The individual learner denial survives core deactivation');
    } elseif ($acceptancePhase === 'lifecycle-activate') {
        native_participation_assert(! is_plugin_active($plugin), 'The next native request starts with Focus Tracker inactive');
        $activated = activate_plugin($plugin, '', false, false);
        native_participation_assert(! is_wp_error($activated) && is_plugin_active($plugin), 'WordPress core activate_plugin activates the actual addon');
        native_participation_assert(! array_key_exists($capability, get_role('subscriber')->capabilities), 'Activation itself does not bypass the next-request accepted-runtime initialization');
    } elseif ($acceptancePhase === 'lifecycle-restored') {
        native_participation_assert(is_plugin_active($plugin) && (get_role('subscriber')->capabilities[$capability] ?? null) === true, 'A fresh native init restores the owned learner grant after reactivation');
        native_participation_user($fixture['students'][0]['userId']);
        native_participation_assert(Capabilities::can_participate(), 'The existing learner again participates after real plugin reactivation');
        native_participation_user($acceptanceAdmin->ID);
        get_role('subscriber')->add_cap($capability, false);
        native_participation_assert(Student_Participation_Defaults::maybe_apply() && get_role('subscriber')->capabilities[$capability] === false, 'An administrator’s later explicit false is not overwritten');
        deactivate_plugins($plugin, false, false);
        native_participation_assert(get_role('subscriber')->capabilities[$capability] === false, 'Core deactivation preserves the later explicit role denial');
        native_participation_assert(! is_wp_error(activate_plugin($plugin, '', false, false)), 'The denied-role scenario reactivates through WordPress core');
    } elseif ($acceptancePhase === 'lifecycle-denied') {
        native_participation_assert(get_role('subscriber')->capabilities[$capability] === false, 'A fresh native init preserves an administrator’s explicit false after reactivation');
        native_participation_user($fixture['students'][0]['userId']);
        native_participation_assert(! Capabilities::can_participate(), 'The role denial really blocks the ordinary learner through native current_user_can');
        native_participation_user($acceptanceAdmin->ID);
        get_role('subscriber')->remove_cap($capability);
        native_participation_assert(Student_Participation_Defaults::maybe_apply() && ! array_key_exists($capability, get_role('subscriber')->capabilities), 'A later administrator role-capability removal is not regranted');
        deactivate_plugins($plugin, false, false);
        native_participation_assert(! is_wp_error(activate_plugin($plugin, '', false, false)), 'The revoked-role scenario reactivates through WordPress core');
    } elseif ($acceptancePhase === 'lifecycle-revoked') {
        native_participation_assert(! array_key_exists($capability, get_role('subscriber')->capabilities), 'A fresh native init preserves the administrator’s later role-capability removal');
        native_participation_user($fixture['students'][0]['userId']);
        native_participation_assert(! Capabilities::can_participate(), 'The persisted administrator revocation blocks native learner participation');
        native_participation_user($acceptanceAdmin->ID);
        // Restore only the deliberate fixture mutations before browser acceptance.
        // This is test-site cleanup, not production regrant behavior.
        get_role('subscriber')->add_cap($capability, $fixture['lifecycle']['originalRoleGrant']);
        update_option('focus_tracker_student_participation_defaults', $fixture['lifecycle']['originalState'], false);
        wp_roles()->for_site();
    } elseif ($acceptancePhase === 'lifecycle-final') {
        native_participation_assert(is_plugin_active($plugin) && Runtime_Bridge::contexts() !== null, 'The browser fixture is back in a native accepted active state');
        native_participation_user($fixture['students'][0]['userId']);
        native_participation_assert(Capabilities::can_participate(), 'The existing student’s normal participation setting is restored');
        native_participation_user($fixture['deniedStudent']['userId']);
        native_participation_assert(! Capabilities::can_participate(), 'The independent individual false denial remains preserved after the full lifecycle');
        native_participation_user($acceptanceAdmin->ID);
        native_participation_assert(Capabilities::can_manage() && Capabilities::can_view(), 'Teacher management and reporting permissions remain intact for browser acceptance');
        $fixture['lifecycleCompleted'] = true;
    }
    $fixture['lifecycle']['phase'] = $acceptancePhase;
    native_participation_write($acceptanceFixturePath, $fixture);
} elseif ($acceptancePhase === 'prepare') {
    native_participation_assert(! is_plugin_active('focus-tracker/focus-tracker.php'), 'Existing-account fixtures are created before Focus Tracker activation');
    native_participation_assert(! is_file($acceptanceFixturePath), 'This prepare run will not overwrite an existing fixture');
    $capability = AddonCapabilityKeyV1::wordpress('focus_tracker_participate');
    native_participation_assert(! array_key_exists($capability, get_role('subscriber')->capabilities), 'The pre-upgrade learner role has no participation grant');
    native_participation_assert(get_option('focus_tracker_student_participation_defaults', false) === false, 'The pre-upgrade one-time marker is absent');
    $runId = 'ftnative' . bin2hex(random_bytes(5));
    $students = array();
    foreach (array('existing', 'denied') as $kind) {
        $username = $runId . '_' . $kind;
        $password = wp_generate_password(28, true, false);
        $id = wp_insert_user(array('user_login' => $username, 'user_pass' => $password,
            'user_email' => $username . '@example.test', 'display_name' => 'Native acceptance ' . $kind, 'role' => 'subscriber'));
        native_participation_assert(is_int($id) && $id > 0, 'A native pre-upgrade ' . $kind . ' account is created');
        $acceptanceProfiles->create(new UserProfile($id, null, '2000-01-01', AccessStatus::Active, false));
        $student = new WP_User($id);
        if ($kind === 'denied') { $student->add_cap($capability, false); }
        native_participation_assert(! user_can($id, $capability), 'The pre-upgrade ' . $kind . ' account cannot yet participate');
        $students[$kind] = array('username' => $username, 'password' => $password, 'userId' => $id, 'kind' => $kind);
    }
    $groupId = $acceptanceGroups->save($acceptanceAdmin->ID, new GroupSaveCommand(0, 'Native Focus acceptance', $runId,
        'Disposable native WordPress/MySQL acceptance fixture', 'private', 'manual', true, null, 'normal', null));
    native_participation_assert($groupId > 0, 'The actual platform GroupService creates a private group');
    foreach ($students as $student) { $acceptanceGroups->membership($acceptanceAdmin->ID, $groupId, 'add', $student['userId']); }
    native_participation_assert(in_array($students['existing']['userId'], $acceptanceGroups->activeLearnerUserIds($groupId), true), 'Actual platform membership places the existing learner in the group');
    $fixture = array('baseUrl' => rtrim((string) $acceptanceConfig['baseUrl'], '/'), 'runId' => $runId,
        'teacher' => array('username' => $acceptanceConfig['admin']['username'], 'password' => $acceptanceConfig['admin']['password'], 'userId' => $acceptanceAdmin->ID),
        'students' => array($students['existing']), 'deniedStudent' => $students['denied'],
        'group' => array('id' => $groupId, 'contextId' => 'group:' . $groupId, 'label' => 'Native Focus acceptance'),
        'artifactsDir' => $acceptanceOutput, 'preparedBeforeFocusActivation' => true);
    native_participation_write($acceptanceFixturePath, $fixture);
} else {
    native_participation_assert(is_file($acceptanceFixturePath), 'The pre-activation fixture exists');
    $fixture = json_decode((string) file_get_contents($acceptanceFixturePath), true, 512, JSON_THROW_ON_ERROR);
    native_participation_assert(is_plugin_active('focus-tracker/focus-tracker.php') && did_action('init') > 0, 'A new native WordPress request activated Focus Tracker and completed init');
    native_participation_assert(Runtime_Bridge::contexts() !== null && Runtime_Bridge::learning_checks() !== null, 'The real platform accepted the addon and connected its runtime gateways');
    native_participation_assert(Installer::ready(), 'The native MySQL addon schema passes its production readiness checks');
    $capability = AddonCapabilityKeyV1::wordpress(Capabilities::PARTICIPATE);
    $state = get_option('focus_tracker_student_participation_defaults', false);
    native_participation_assert(is_array($state) && ($state['status'] ?? null) === 'complete' && ($state['owned'] ?? null) === true, 'Native init durably completed the one-time automatic default');
    $existing = native_participation_user($fixture['students'][0]['userId']);
    native_participation_assert(Capabilities::can_participate() && current_user_can($capability), 'The account that existed before activation inherits the automatic role grant');
    native_participation_assert(! array_key_exists($capability, $existing->caps), 'The existing learner has no individual participation grant');
    native_participation_assert(! (new WordPressPrivilegedAccountDetector())->isPrivileged((new WordPressUserApiGateway())->findById($existing->ID)), 'The real platform account policy keeps the opted-in learner unprivileged');
    native_participation_user($fixture['deniedStudent']['userId']);
    native_participation_assert(! current_user_can($capability), 'An existing individual false denial overrides the new role grant in native WordPress');
    native_participation_assert(native_participation_rest('POST', '/tracking/state', array('problem_id' => 1))->get_status() === 403, 'The denied learner cannot obtain a tracking lease');
    native_participation_user($acceptanceAdmin->ID);

    // Real registration, using only a temporary policy on this disposable database.
    // Approve the resulting pending account through the native administration service.
    $settings = $acceptanceContainer->get(SettingsService::class);
    $priorRegistration = get_option('oj_platform_registration_settings', false);
    try {
        $registration = $settings->read('registration');
        $settings->save('registration', array('enabled' => true, 'approval_mode' => 'manual', 'default_role' => 'subscriber',
            'required_agreement_types' => array(), 'guardian_consent_enabled' => false), $registration->revision());
        $username = $fixture['runId'] . '_registered_' . bin2hex(random_bytes(2));
        $password = wp_generate_password(28, true, false);
        $result = $acceptanceContainer->get(RegistrationService::class)->register(new RegistrationInput($username,
            $username . '@example.test', $password, 'Native registered student', null, '2000-01-01', null, array(), '127.0.0.1', 'Focus native acceptance'));
        native_participation_assert($result->accessStatus() === AccessStatus::Pending, 'The actual platform registration service honors manual approval');
        $acceptanceContainer->get(UserAdministrationService::class)->approve($result->userId(), $acceptanceAdmin->ID);
        native_participation_assert($acceptanceProfiles->find($result->userId())?->accessStatus() === AccessStatus::Active, 'The actual administration service approves the new student');
        $fixture['students'] = array_values(array_filter($fixture['students'], static fn (array $student): bool => $student['kind'] !== 'new'));
        $fixture['students'][] = array('username' => $username, 'password' => $password, 'userId' => $result->userId(), 'kind' => 'new');
        native_participation_write($acceptanceFixturePath, $fixture);
    } finally {
        if ($priorRegistration === false) { delete_option('oj_platform_registration_settings'); }
        else { update_option('oj_platform_registration_settings', $priorRegistration, false); }
    }
    $newStudent = end($fixture['students']);
    $newUser = native_participation_user($newStudent['userId']);
    native_participation_assert(current_user_can($capability) && ! array_key_exists($capability, $newUser->caps), 'The newly registered student inherits participation without an individual grant');
    native_participation_assert($acceptanceProfiles->find($newStudent['userId'])?->mustChangePassword() === false, 'The registered account can reach ordinary problem pages without a password-change detour');
    native_participation_user($acceptanceAdmin->ID);
    $acceptanceGroups->membership($acceptanceAdmin->ID, $fixture['group']['id'], 'add', $newStudent['userId']);

    // A precomputed WP_User::allcaps cache is refreshed in the same native request.
    $priorState = get_option('focus_tracker_student_participation_defaults', false);
    try {
        get_role('subscriber')->remove_cap($capability);
        delete_option('focus_tracker_student_participation_defaults');
        wp_roles()->for_site();
        $cachedUser = native_participation_user($existing->ID);
        native_participation_assert(! current_user_can($capability), 'The cache scenario starts from a native WP_User without the new grant');
        native_participation_assert(Student_Participation_Defaults::maybe_apply(), 'The production default initializer succeeds against native role storage');
        native_participation_assert($cachedUser === wp_get_current_user() && current_user_can($capability), 'The same preinitialized WP_User gains the durable role grant without changing the current user');
        native_participation_assert(has_action('init', array(Student_Participation_Defaults::class, 'maybe_apply')) === 3, 'The automatic initializer is registered after platform role synchronization');
    } finally {
        get_role('subscriber')->add_cap($capability, true);
        update_option('focus_tracker_student_participation_defaults', $priorState, false);
        wp_roles()->for_site();
        native_participation_user($acceptanceAdmin->ID);
    }

    $problemFixturePath = $acceptanceOutput . '/problem-fixture.json';
    native_participation_assert(is_file($problemFixturePath), 'The actual platform problem fixture has been prepared');
    $problemFixture = json_decode((string) file_get_contents($problemFixturePath), true, 512, JSON_THROW_ON_ERROR);
    $fixture['problem'] = $problemFixture['problem'];
    native_participation_assert(is_int($fixture['problem']['id'] ?? null) && $fixture['problem']['id'] > 0, 'A real problem ID is available');
    $contexts = Context_Resolver::list_teaching_contexts($acceptanceAdmin->ID);
    native_participation_assert(count(array_filter($contexts, static fn (array $context): bool => $context['type'] === 'group' && $context['id'] === $fixture['group']['contextId'])) === 1, 'The accepted runtime sees the teacher’s actual group');
    // Teacher feature permissions are an explicit site setup, separate from
    // the automatic learner default. Use the same native gateway as the matrix.
    $assignments = new WordPressAddonCapabilityAssignmentGateway();
    foreach (array(Capabilities::VIEW, Capabilities::MANAGE, Capabilities::SETTINGS) as $key) {
        $assignments->grantToRole('administrator', AddonCapabilityKeyV1::wordpress($key));
    }
    native_participation_user($acceptanceAdmin->ID);
    native_participation_assert(Capabilities::can_manage() && Capabilities::can_view(), 'Explicit teacher role configuration enables activity management and reports');
    $start = time() + 2;
    $end = $start + 7;
    $created = native_participation_ok(native_participation_rest('POST', '/activities', array('name' => 'Native timed acceptance',
        'context_type' => 'group', 'context_id' => $fixture['group']['contextId'], 'problem_ids' => array($fixture['problem']['id']),
        'starts_at' => gmdate('c', $start), 'ends_at' => gmdate('c', $end))), 'The production REST API creates the teacher’s timed activity');
    $activityId = (int) ($created['id'] ?? 0);
    native_participation_assert($activityId > 0, 'The schedule is persisted with a real activity ID');
    $fixture['sourceStartedAtMs'] = Clock::now_ms() - 5;
    native_participation_user($existing->ID);
    $gateBody = array('user_id' => $existing->ID, 'problem_id' => $fixture['problem']['id'], 'context_type' => 'general');
    $before = native_participation_ok(native_participation_rest('POST', '/tracking/state', $gateBody), 'The native gate is available before the scheduled start');
    native_participation_assert($before['active'] === false, 'An ordinary problem page is not authorized before the activity starts');
    $preEvent = native_participation_ok(native_participation_rest('POST', '/events', native_participation_packet($fixture, 1)), 'A premature event is handled with an explicit policy acknowledgement');
    native_participation_assert($preEvent['stored'] === 0 && $preEvent['policyDropped'] === 1, 'No observation is stored before the permitted time');
    native_participation_assert(native_participation_rest('POST', '/tracking/state', $gateBody, 'invalid-native-nonce')->get_status() === 403, 'The native REST permission path rejects an invalid nonce');
    native_participation_wait($start * 1000 + 100);
    $during = native_participation_ok(native_participation_rest('POST', '/tracking/state', $gateBody), 'The native gate is available inside the time window');
    native_participation_assert($during['active'] === true && in_array($activityId, $during['activityIds'], true), 'The same ordinary page context gains authorization at the real scheduled start');
    $inWindowPacket = native_participation_packet($fixture, 2);
    $ingest = native_participation_ok(native_participation_rest('POST', '/events', $inWindowPacket), 'The first ordinary in-window page signal is committed');
    native_participation_assert($ingest['stored'] > 0 && in_array($activityId, Participation_Repository::activity_ids($ingest['participation_id']), true), 'The first signal automatically creates and links participation without a student join API');
    $repeat = native_participation_ok(native_participation_rest('POST', '/events', $inWindowPacket), 'A repeated sequence is handled safely');
    native_participation_assert($repeat['stored'] === 0, 'A repeated physical sequence creates no second stored observation');
    $question = native_participation_ok(native_participation_rest('POST', '/checks/next', array('user_id' => $existing->ID, 'problem_id' => $fixture['problem']['id'],
        'draft' => array('source' => "if value < 7:\n    pass", 'language_id' => 6))), 'The native question endpoint accepts an in-window draft');
    native_participation_assert($question['status'] === 'ready' && isset($question['check']['id']), 'The real runtime and verified generator issue a question without a submission');
    native_participation_assert(! str_contains((string) wp_json_encode($question), 'correctAnswer') && ! str_contains((string) wp_json_encode($question), 'proof'), 'The public native REST response hides the answer and verification proof');
    $rawBeforeEnd = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::events_table() . ' e JOIN ' . Installer::participations_table() . ' p ON p.id=e.participation_id WHERE p.user_id=%d', $existing->ID));
    native_participation_wait($end * 1000 + 100);
    $after = native_participation_ok(native_participation_rest('POST', '/tracking/state', $gateBody), 'The native gate is available after the scheduled end');
    native_participation_assert($after['active'] === false, 'Authorization ends at the actual wall-clock activity boundary');
    $postEvent = native_participation_ok(native_participation_rest('POST', '/events', native_participation_packet($fixture, 3)), 'A post-end event receives an explicit policy acknowledgement');
    native_participation_assert($postEvent['stored'] === 0 && $postEvent['policyDropped'] === 1, 'No new observation is stored after the activity ends');
    native_participation_assert($rawBeforeEnd === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::events_table() . ' e JOIN ' . Installer::participations_table() . ' p ON p.id=e.participation_id WHERE p.user_id=%d', $existing->ID)), 'The native event table has no post-end growth');
    $lateResponse = native_participation_ok(native_participation_rest('POST', '/checks/' . $question['check']['id'] . '/response', array('response_uid' => $fixture['runId'] . '-late', 'action' => 'unknown')), 'A pending question is retired after the activity ends');
    native_participation_assert($lateResponse['status'] === 'invalidated', 'The natural schedule end is recorded as cancellation rather than an incorrect or unanswered result');
    $storedQuestion = Check_Repository::find($question['check']['id'], $existing->ID);
    native_participation_assert($storedQuestion['status'] === 'invalidated' && $storedQuestion['answer_id'] === null, 'The native database stores no graded answer for the ended activity');
    native_participation_user($acceptanceAdmin->ID);
    native_participation_ok(native_participation_rest('DELETE', '/activities/' . $activityId), 'The REST API removes the finished backend-test schedule from the browser fixture');
    $fixture['backendActivityId'] = $activityId;
    $fixture['verifiedByNativePhp'] = true;
    unset($fixture['sourceStartedAtMs']);
    native_participation_write($acceptanceFixturePath, $fixture);
}

$report = array('phase' => $acceptancePhase, 'passed' => true, 'assertionCount' => count($acceptanceAssertions),
    'assertions' => $acceptanceAssertions, 'environment' => array('wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION,
        'database' => $acceptanceDatabaseVersion, 'platform' => OJ_PLATFORM_VERSION,
        'focusTracker' => defined('FOCUS_TRACKER_VERSION') ? FOCUS_TRACKER_VERSION : null),
    'boundary' => 'Native WordPress/MySQL services and internal REST dispatch; browser cookie authentication and automatic browser timers are verified separately.');
native_participation_write($acceptanceOutput . '/participation-' . $acceptancePhase . '-report.json', $report);
echo json_encode(array('phase' => $acceptancePhase, 'passed' => true, 'assertions' => count($acceptanceAssertions)), JSON_THROW_ON_ERROR) . "\n";
