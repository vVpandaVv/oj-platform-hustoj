<?php
declare(strict_types=1);

/** Real WordPress/MariaDB upgrade and categorized-capture acceptance. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$finished = false;
register_shutdown_function(static function () use (&$finished): void { if (! $finished) { fwrite(STDERR, "Native category acceptance did not complete.\n"); exit(1); } });

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\Kernel\Boot\Kernel;
use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressErrorReporter;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressHookRegistrar;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupSaveCommand;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Event_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Privacy\Personal_Data;
use FocusTracker\Reporting\Statistics_Service;
use FocusTracker\Scoring\Activity_Score_Service;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;
use FocusTracker\Tracking\Page_Context;

$assertions = array();
$failedAssertion = null;
function category_native_assert(bool $condition, string $message): void {
    global $assertions, $failedAssertion;
    if (! $condition) { $failedAssertion = $message; throw new RuntimeException($message); }
    $assertions[] = $message;
}
function category_native_write(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) { throw new RuntimeException('Could not save the private native acceptance artifact.'); }
}
function category_native_user(int $id): WP_User { wp_set_current_user(0); $user = wp_set_current_user($id); $user->get_role_caps(); return $user; }
function category_native_rest(string $method, string $path, array $data = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/focus-tracker/v1' . $path);
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    $request->set_query_params(array('_wpnonce' => wp_create_nonce('wp_rest')));
    if ($method === 'GET') { $request->set_query_params($data + array('_wpnonce' => wp_create_nonce('wp_rest'))); }
    else { $request->set_header('Content-Type', 'application/json'); $request->set_body((string) wp_json_encode($data)); }
    return rest_do_request($request);
}
function category_native_ok(WP_REST_Response $response, string $label): array {
    category_native_assert($response->get_status() >= 200 && $response->get_status() < 300, $label);
    $data = $response->get_data(); category_native_assert(is_array($data), $label . ' returns structured data'); return $data;
}
function category_native_snapshot(array $fixture, ?array $columns = null): array {
    global $wpdb;
    $tables = array('activities' => Installer::activities_table(), 'participations' => Installer::participations_table(),
        'events' => Installer::events_table(), 'scores' => Installer::scores_table(), 'links' => Installer::activity_links_table());
    $where = array('activities' => 'id = ' . (int) $fixture['legacy']['activityId'], 'participations' => 'id = ' . (int) $fixture['legacy']['participationId'],
        'events' => 'participation_id = ' . (int) $fixture['legacy']['participationId'], 'scores' => 'activity_id = ' . (int) $fixture['legacy']['activityId'],
        'links' => 'participation_id = ' . (int) $fixture['legacy']['participationId']);
    $result = array('columns' => array(), 'hashes' => array());
    foreach ($tables as $kind => $table) {
        $fields = $columns[$kind] ?? array_column($wpdb->get_results('SHOW COLUMNS FROM ' . $table, ARRAY_A), 'Field');
        $rows = $wpdb->get_results('SELECT `' . implode('`,`', $fields) . '` FROM ' . $table . ' WHERE ' . $where[$kind] . ' ORDER BY ' . ($kind === 'links' ? 'participation_id, activity_id' : 'id'), ARRAY_A);
        category_native_assert(is_array($rows) && $wpdb->last_error === '', 'Native baseline rows are readable: ' . $kind);
        $result['columns'][$kind] = $fields;
        $result['hashes'][$kind] = hash('sha256', (string) wp_json_encode($rows));
    }
    return $result;
}
function category_native_bin(array $report, string $category, ?string $subtype = null): array {
    foreach ($report['pageCategories'] as $row) { if ($row['category'] === $category && $row['subtype'] === $subtype) { return $row; } }
    throw new RuntimeException('Native report is missing a page category.');
}
function category_native_capture(array $fixture, int $user, string $path, int $from, int $seconds, string $suffix): array {
    category_native_user($user);
    $page = Page_Context::issue($user, $path, array('group_id' => (string) $fixture['group']['id']), $from - 1000);
    category_native_assert(is_array($page), 'The real page classifier signs ' . $suffix);
    $end = $from + $seconds * 1000;
    $body = array('user_id' => $user, 'problem_id' => $page['problemId'], 'page_context' => $page['token'], 'context_type' => 'group', 'context_id' => $fixture['group']['contextId'],
        'session_uid' => $fixture['runId'] . '-' . $suffix, 'session_started_at' => $from, 'is_final' => true,
        'events' => array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $from, 'meta' => array('visible' => true, 'focused' => true)),
            array('seq' => 2, 'type' => 'heartbeat', 'occurred_at' => $from + 15000, 'meta' => array('visible' => true, 'focused' => true)),
            array('seq' => 3, 'type' => 'session_end', 'occurred_at' => $end)));
    $stored = category_native_ok(category_native_rest('POST', '/events', $body), 'The actual REST ingest commits ' . $suffix);
    category_native_assert($stored['stored'] > 0, 'The native database receives observations for ' . $suffix);
    $row = Participation_Repository::find((int) $stored['participation_id']);
    category_native_assert($row['problem_id'] === null && $row['page_category'] === $page['category'] && $row['page_context_version'] === 1, 'Authenticated nullable page metadata persists for ' . $suffix);
    return $row;
}

try {
    $phase = $argv[1] ?? 'baseline';
    category_native_assert(in_array($phase, array('baseline', 'verify'), true), 'The requested native phase is valid');
    $configPath = $argv[2] ?? dirname(__DIR__, 3) . '/_notes/tmp/focus-tracker-real-wp/config.json';
    $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
    category_native_assert(($config['baseUrl'] ?? $config['home'] ?? '') === 'http://127.0.0.1:33918' && ($config['dbHost'] ?? '') === '127.0.0.1:33917'
        && ($config['dbName'] ?? '') === 'focus_acceptance_categories_20261028', 'Only the dedicated category acceptance instance may be changed');
    $_SERVER['HTTP_HOST'] = '127.0.0.1:33918'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['SERVER_PORT'] = '33918';
    $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    require rtrim($config['wpPath'], '/\\') . '/wp-load.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    category_native_assert(wp_get_environment_type() === 'local' && $wpdb instanceof wpdb && ! str_contains(strtolower(get_class($wpdb)), 'sqlite'), 'Native WordPress and native MySQL are active');
    $output = rtrim($config['outputDir'], '/\\'); if (! is_dir($output)) { mkdir($output, 0700, true); }
    $fixturePath = $output . '/page-categories-fixture.json';
    if ($phase === 'baseline') {
        $activated = array();
        foreach (array('focus-tracker/focus-tracker.php', 'edu-games/edu-games.php', 'learning-materials/learning-materials.php') as $plugin) {
            if (! is_plugin_active($plugin)) {
                $result = activate_plugin($plugin);
                category_native_assert(! is_wp_error($result), 'WordPress core activates ' . $plugin);
                $activated[] = $plugin;
            }
        }
        if ($activated !== array()) { $finished = true; echo json_encode(array('phase' => $phase, 'activated' => $activated, 'needsFreshRequest' => true), JSON_THROW_ON_ERROR) . "\n"; exit; }
    }
    category_native_assert(defined('FOCUS_TRACKER_VERSION') && ($phase === 'baseline' ? FOCUS_TRACKER_VERSION === '1.0.1' : version_compare(FOCUS_TRACKER_VERSION, '1.1.0', '>=')), 'The phase loads the expected installed Focus Tracker version');
    $container = (new CompositionRoot(PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE, OJ_PLATFORM_VERSION, '6.6', '8.3'), new WordPressHookRegistrar(), new WordPressErrorReporter()))->build();
    $container->get(Kernel::class)->boot();
    $profiles = $container->get(UserProfileRepository::class); $groups = $container->get(GroupService::class);
    $admin = get_user_by('login', (string) ($config['admin']['username'] ?? $config['admin']['login']));
    category_native_assert($admin instanceof WP_User && user_can($admin, 'manage_options'), 'A native fixture administrator authorizes setup');
    category_native_user($admin->ID);

    if ($phase === 'baseline') {
        category_native_assert(! is_file($fixturePath), 'A new baseline cannot overwrite an earlier upgrade fixture');
        $runId = 'ftcat' . bin2hex(random_bytes(5));
        foreach (array('focus_tracker_view_self', 'edu_games_play', 'learning_materials_view') as $cap) { get_role('subscriber')->add_cap(AddonCapabilityKeyV1::wordpress($cap), true); }
        $accounts = array();
        foreach (array('teacher', 'existing', 'privacy') as $kind) {
            $login = $runId . '_' . $kind; $password = wp_generate_password(28, true, false);
            $id = wp_insert_user(array('user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.test',
                'display_name' => 'Native category ' . $kind, 'role' => $kind === 'teacher' ? 'oj_group_manager' : 'subscriber'));
            category_native_assert(is_int($id) && $id > 0, 'Native WordPress creates the synthetic ' . $kind . ' account');
            $profiles->create(new UserProfile($id, null, '2000-01-01', AccessStatus::Active, false));
            $accounts[$kind] = array('username' => $login, 'password' => $password, 'userId' => $id, 'kind' => $kind);
        }
        $teacher = new WP_User($accounts['teacher']['userId']);
        foreach (array('focus_tracker_view', 'focus_tracker_manage', 'focus_tracker_view_self', 'focus_tracker_settings') as $cap) { $teacher->add_cap(AddonCapabilityKeyV1::wordpress($cap), true); }
        category_native_assert(! user_can($teacher, 'manage_options') && user_can($teacher, 'oj_access_console'), 'The teacher is an ordinary group manager rather than an administrator');
        foreach (array('existing', 'privacy') as $kind) {
            $user = new WP_User($accounts[$kind]['userId']);
            category_native_assert(user_can($user, AddonCapabilityKeyV1::wordpress('focus_tracker_participate')) && ! array_key_exists(AddonCapabilityKeyV1::wordpress('focus_tracker_participate'), $user->caps), 'The ' . $kind . ' learner inherits participation without an individual or manual grant');
        }
        category_native_user($teacher->ID);
        $groupId = $groups->save($teacher->ID, new GroupSaveCommand(0, 'Native category acceptance', $runId, 'Disposable native category acceptance', 'private', 'manual', true, null, 'normal', null));
        foreach (array('existing', 'privacy') as $kind) { $groups->membership($teacher->ID, $groupId, 'add', $accounts[$kind]['userId']); }
        category_native_assert(in_array($accounts['existing']['userId'], $groups->activeLearnerUserIds($groupId), true), 'The real group service includes the existing learner');
        $materials = new \LearningMaterials\Materials\Material_Repository();
        $materialId = $materials->create('Native focus reading', 'This published learning material is a disposable page-classification fixture.',
            array('storage_filename' => $runId . '.txt', 'original_filename' => 'native-focus-reading.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'file_size' => 0), $teacher->ID, array());
        category_native_assert($materialId > 0 && $materials->find($materialId)?->is_available(), 'The actual material repository creates a published public learning page');
        $fixture = array('baseUrl' => $config['baseUrl'], 'runId' => $runId, 'teacher' => $accounts['teacher'], 'students' => array($accounts['existing']), 'privacyStudent' => $accounts['privacy'],
            'group' => array('id' => $groupId, 'contextId' => 'group:' . $groupId, 'label' => 'Native category acceptance'),
            'material' => array('id' => $materialId, 'url' => $config['baseUrl'] . '/oj-addons/learning-materials/' . $materialId),
            'gameUrl' => $config['baseUrl'] . '/oj-addons/edu-games/games/binary-conversion', 'mlUrl' => $config['baseUrl'] . '/oj-addons/edu-games/games/ai-model-lab', 'artifactsDir' => $output);
        Settings::update(array('retention_days' => 75, 'check_retention_days' => 76, 'aggregate_retention_days' => 0, 'check_daily_limit' => 9,
            'tracked_url_patterns' => array('#^/(problems|submit|submissions|oj-addons|board)(/|$)#')));
        $from = Clock::now_ms() - HOUR_IN_SECONDS * 1000;
        $activityId = Activity_Repository::create(array('name' => 'Pre-category preserved activity', 'owner_user_id' => $teacher->ID, 'context_type' => 'group', 'context_id' => 'group:' . $groupId,
            'problem_ids' => array(123), 'starts_at' => Clock::ms_to_mysql($from), 'ends_at' => Clock::ms_to_mysql($from + 30000), 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
        $p = Participation_Repository::find_or_create(array('activity_id' => null, 'user_id' => $accounts['existing']['userId'], 'problem_id' => 123,
            'session_uid' => 'g6-' . substr(hash('sha256', $runId), 0, 32) . '-legacy', 'started_at_ms' => $from));
        Event_Repository::insert_batch($p['id'], array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $from, 'meta' => array('visible' => true, 'focused' => true)),
            array('seq' => 2, 'type' => 'activity', 'occurred_at' => $from + 1000), array('seq' => 3, 'type' => 'heartbeat', 'occurred_at' => $from + 15000), array('seq' => 4, 'type' => 'session_end', 'occurred_at' => $from + 30000)));
        Participation_Repository::touch_heartbeat($p['id'], $from + 30000); Participation_Repository::close($p['id'], $from + 30000, 'pagehide');
        Participation_Repository::record_event_revision($p['id'], 4); Participation_Repository::link_activity($p['id'], $activityId);
        $score = Activity_Score_Service::score_for_learner(Activity_Repository::find($activityId), $accounts['existing']['userId'], true);
        category_native_assert(! empty($score['cache_saved']), 'The official 1.0.1 scorer stores a real pre-upgrade score');
        $fixture['legacy'] = array('activityId' => $activityId, 'participationId' => $p['id']);
        $fixture['baseline'] = category_native_snapshot($fixture);
        $fixture['baseline']['settings'] = get_option('focus_tracker_settings');
        category_native_write($fixturePath, $fixture);
    } else {
        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        category_native_assert(Installer::ready(), 'The real WordPress dbDelta upgrade completes with every required schema field');
        $after = category_native_snapshot($fixture, $fixture['baseline']['columns']);
        category_native_assert($after['hashes'] === $fixture['baseline']['hashes'], 'Every old common activity/session/event/score/link column has the same SHA256 after upgrade');
        $legacy = Participation_Repository::find($fixture['legacy']['participationId']);
        category_native_assert($legacy['page_category'] === 'unclassified' && $legacy['page_context_version'] === 0 && $legacy['page_subtype'] === null && $legacy['page_key'] === null, 'Existing native records receive unclassified metadata defaults without fabricated history');
        $oldScore = $wpdb->get_row('SELECT activity_eligible,score_eligible FROM ' . Installer::scores_table() . ' WHERE activity_id=' . (int) $fixture['legacy']['activityId'], ARRAY_A);
        category_native_assert((int) $oldScore['activity_eligible'] === 1 && (int) $oldScore['score_eligible'] === 1, 'Existing native scores keep both eligibility defaults');
        category_native_assert(wp_json_encode(array_intersect_key(get_option('focus_tracker_settings'), $fixture['baseline']['settings'])) === wp_json_encode($fixture['baseline']['settings']), 'The upgrade preserves operator settings and the explicit custom tracking scope');
        // A fixture file is created directly in this disposable installation;
        // production upload validation remains the plugin's native HTTP path.
        $storage = new \LearningMaterials\Materials\Material_Storage();
        $storageName = $fixture['runId'] . '.txt';
        try { $storage->info($storageName); } catch (RuntimeException $ignored) { /* Native storage prepares its access guards before a missing fixture read. */ }
        $contents = "Native focus reading fixture.\nObserve the material, game and machine-learning page categories.\n";
        $storagePath = WP_CONTENT_DIR . '/learning-materials-private/' . $storageName;
        category_native_assert(file_put_contents($storagePath, $contents, LOCK_EX) === strlen($contents), 'A real protected native TXT fixture is readable by the material page');
        $file = array('storage_filename' => $storageName, 'original_filename' => 'native-focus-reading.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'file_size' => strlen($contents));
        (new \LearningMaterials\Materials\Material_Repository())->update($fixture['material']['id'], 'Native focus reading', 'This published learning material is a disposable page-classification fixture.', $file, array(), new \LearningMaterials\Materials\Material_Settings(status: 'published'));
        category_native_assert($storage->read($storageName) === $contents, 'The native material storage serves the complete fixture text');
        $teacherId = $fixture['teacher']['userId'];
        $login = $fixture['runId'] . '_new'; $password = wp_generate_password(28, true, false);
        $newId = wp_insert_user(array('user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.test', 'display_name' => 'Native category new student', 'role' => 'subscriber'));
        category_native_assert(is_int($newId) && $newId > 0, 'Native WordPress creates a new learner after the upgrade');
        $profiles->create(new UserProfile($newId, null, '2000-01-01', AccessStatus::Active, false));
        $new = new WP_User($newId);
        category_native_assert(user_can($new, AddonCapabilityKeyV1::wordpress('focus_tracker_participate')) && ! array_key_exists(AddonCapabilityKeyV1::wordpress('focus_tracker_participate'), $new->caps), 'The new learner inherits participation without a per-user or manual grant');
        category_native_user($teacherId); $groups->membership($teacherId, $fixture['group']['id'], 'add', $newId);
        $fixture['students'][] = array('username' => $login, 'password' => $password, 'userId' => $newId, 'kind' => 'new');
        $studentId = $fixture['students'][0]['userId'];
        $from = intdiv(Clock::now_ms() - 2 * HOUR_IN_SECONDS * 1000, 1000) * 1000;
        $created = category_native_ok(category_native_rest('POST', '/activities', array('name' => 'Native category history', 'context_type' => 'group', 'context_id' => $fixture['group']['contextId'],
            'problem_ids' => array(123), 'starts_at' => gmdate('c', intdiv($from, 1000)), 'ends_at' => gmdate('c', intdiv($from + 90000, 1000)))), 'The ordinary teacher creates a problem-filtered activity through native REST');
        $materialPath = '/oj-addons/learning-materials/' . $fixture['material']['id'];
        category_native_capture($fixture, $studentId, $materialPath, $from, 30, 'reading');
        category_native_capture($fixture, $studentId, '/oj-addons/edu-games/games/binary-conversion', $from + 30000, 30, 'game');
        category_native_capture($fixture, $studentId, '/oj-addons/edu-games/games/ai-model-lab', $from + 60000, 30, 'ml');
        $scope = array('type' => 'group', 'id' => $fixture['group']['contextId']);
        $date = (new DateTimeImmutable('@' . intdiv($from, 1000)))->setTimezone(wp_timezone())->format('Y-m-d');
        $week = Statistics_Service::report($studentId, 'week', $date, $scope);
        $month = Statistics_Service::report($studentId, 'month', $date, $scope);
        foreach (array(array('material', null), array('game', null), array('practice', 'ml')) as [$category, $subtype]) {
            category_native_assert(category_native_bin($week, $category, $subtype)['activeSeconds'] === 30.0, 'Native weekly statistics retain thirty active seconds for ' . $category . ':' . $subtype);
            category_native_assert(category_native_bin($week, $category, $subtype)['activeSeconds'] === category_native_bin($month, $category, $subtype)['activeSeconds'], 'Native calendar month agrees with category time for ' . $category);
        }
        category_native_assert((int) round(array_sum(array_column($week['pageCategories'], 'activeSeconds')) * 1000) === (int) round($week['totals']['activeSeconds'] * 1000), 'Native category totals exactly partition the active-time union');
        $metrics = Activity_Score_Service::score_for_learner(Activity_Repository::find($created['id']), $studentId, true);
        category_native_assert($metrics['activity_eligible'] === false && $metrics['score_activity'] === null && $metrics['score_total'] === 20.0, 'Reading, games and ML remain neutral for editing and preserve the normalized focus score');

        category_native_user($teacherId);
        $live = category_native_ok(category_native_rest('POST', '/activities', array('name' => 'Native category gate', 'context_type' => 'group', 'context_id' => $fixture['group']['contextId'],
            'problem_ids' => array(123), 'starts_at' => gmdate('c', time() - 10), 'ends_at' => gmdate('c', time() + 60))), 'The ordinary teacher creates the live page gate');
        category_native_user($studentId);
        $page = Page_Context::issue($studentId, $materialPath, array('group_id' => $fixture['group']['id']));
        $gateBody = array('user_id' => $studentId, 'problem_id' => null, 'page_context' => $page['token']);
        category_native_assert(category_native_ok(category_native_rest('POST', '/tracking/state', $gateBody), 'Native signed material gate is available')['active'] === true, 'A signed nullable material follows real group membership and its live schedule');
        $forged = $gateBody; $forged['page_context'] = substr($page['token'], 0, -1) . (str_ends_with($page['token'], 'a') ? 'b' : 'a');
        category_native_assert(category_native_rest('POST', '/tracking/state', $forged)->get_status() === 403, 'Native REST rejects a modified page signature');
        category_native_assert(category_native_rest('POST', '/tracking/state', array('problem_id' => null))->get_status() === 400, 'Native REST rejects unsigned non-problem capture');
        category_native_user($newId); $gateBody['user_id'] = $newId;
        category_native_assert(category_native_rest('POST', '/tracking/state', $gateBody)->get_status() === 403, 'Native account change invalidates another learner’s descriptor');
        category_native_user($teacherId);
        category_native_ok(category_native_rest('PATCH', '/activities/' . $live['id'], array('status' => 'closed')), 'The native teacher ends the live gate');
        category_native_user($studentId); $gateBody['user_id'] = $studentId;
        category_native_assert(category_native_ok(category_native_rest('POST', '/tracking/state', $gateBody), 'Native gate remains readable after end')['active'] === false, 'Native collection authorization ends when the real teacher closes the activity');

        $privacyId = $fixture['privacyStudent']['userId'];
        category_native_capture($fixture, $privacyId, $materialPath, $from, 30, 'privacy-reading');
        $privacyUser = get_userdata($privacyId);
        $export = Personal_Data::export_data($privacyUser->user_email);
        category_native_assert(is_array($export) && str_contains(wp_json_encode($export), 'page_category') && str_contains(wp_json_encode($export), 'material'), 'Native WordPress privacy export includes authenticated page metadata');
        $peerCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::participations_table() . ' WHERE user_id=' . (int) $studentId);
        for ($attempt = 0; $attempt < 20; ++$attempt) { $erased = Personal_Data::erase_data($privacyUser->user_email); category_native_assert(! is_wp_error($erased), 'Native privacy erasure makes bounded progress'); if (! empty($erased['done'])) { break; } }
        category_native_assert(! empty($erased['done']) && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::participations_table() . ' WHERE user_id=' . (int) $privacyId) === 0, 'Native privacy erasure removes categorized personal sessions');
        category_native_assert($peerCount === (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::participations_table() . ' WHERE user_id=' . (int) $studentId), 'Native privacy erasure preserves the other learner’s page history');
        $fixture['verifiedByNativePhp'] = true; $fixture['nativeHistoryActivityId'] = $created['id'];
        category_native_write($fixturePath, $fixture);
    }
    $report = array('phase' => $phase, 'passed' => true, 'assertions' => $assertions, 'assertionCount' => count($assertions),
        'environment' => array('wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'database' => $wpdb->get_var('SELECT VERSION()'), 'platform' => OJ_PLATFORM_VERSION, 'focusTracker' => FOCUS_TRACKER_VERSION),
        'scope' => 'Actual WordPress/MySQL services and internal REST dispatch. Cookie authentication and browser behavior are verified separately.');
    category_native_write($output . '/page-categories-' . $phase . '-report.json', $report);
    $finished = true;
    echo json_encode(array('phase' => $phase, 'passed' => true, 'assertions' => count($assertions), 'fixture' => $fixturePath), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Native category acceptance failed: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    if (is_string($failedAssertion)) { fwrite(STDERR, 'Assertion: ' . $failedAssertion . "\n"); }
    exit(1);
}
