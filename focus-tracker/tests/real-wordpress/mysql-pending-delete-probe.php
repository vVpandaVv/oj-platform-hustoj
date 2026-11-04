<?php
declare(strict_types=1);

/** Native RR pending-window probe; run only after the main load baseline ends. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

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
use FocusTracker\Database\Installer;
use FocusTracker\Support\Clock;
use FocusTracker\Tracking\Page_Context;

function pending_probe_json(string $path): array { return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
function pending_probe_save(string $path, array $value): void {
    $data = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $data, LOCK_EX) !== strlen($data)) { throw new RuntimeException('artifact_write'); }
}
function pending_probe_path(string $path): string { return strtolower(str_replace('\\', '/', rtrim($path, '/\\'))); }
function pending_probe_cli(string $path): string {
    if (preg_match('//u', $path) !== 1 && function_exists('sapi_windows_cp_conv')) { $path = sapi_windows_cp_conv(0, 65001, $path); }
    $path = str_replace('\\', '/', $path); $workspace = str_replace('\\', '/', dirname(__DIR__, 3));
    if (str_starts_with(strtolower($path), strtolower($workspace) . '/')) { $path = substr($path, strlen($workspace) + 1); }
    if (preg_match('/^[\x20-\x7e]+$/D', $path) !== 1) { throw new RuntimeException('child_path_encoding'); }
    return $path;
}
function pending_probe_user(int $id): void { wp_set_current_user(0); wp_set_current_user($id)->get_role_caps(); }
function pending_probe_rest(string $method, string $route, array $body = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/focus-tracker/v1' . $route); $nonce = wp_create_nonce('wp_rest');
    $request->set_query_params(array('_wpnonce' => $nonce)); $request->set_header('X-WP-Nonce', $nonce);
    $request->set_header('Content-Type', 'application/json'); $request->set_body((string) wp_json_encode($body));
    return rest_do_request($request);
}
function pending_probe_ok(WP_REST_Response $response): array {
    if ($response->get_status() !== 200 && $response->get_status() !== 201) { throw new RuntimeException('native_rest_status_' . $response->get_status()); }
    $data = $response->get_data(); if (! is_array($data)) { throw new RuntimeException('native_rest_shape'); } return $data;
}
function pending_probe_wait(string $path, float $deadline): void {
    while (! is_file($path)) { if (microtime(true) >= $deadline) { throw new RuntimeException('native_barrier_timeout'); } usleep(10000); }
}
function pending_probe_pair(array $jobs, string $dir, string $configPath): array {
    global $wpdb;
    $args = array(pending_probe_cli(PHP_BINARY), '-n', '-d', 'extension_dir=' . pending_probe_cli(ini_get('extension_dir')));
    foreach (array('mysqli', 'mbstring', 'openssl', 'curl', 'zip', 'intl', 'fileinfo', 'sodium') as $extension) { array_push($args, '-d', 'extension=' . $extension); }
    $workers = array();
    try {
        foreach ($jobs as $index => $job) {
            $path = $dir . '/worker-' . $index . '.json'; pending_probe_save($path, $job);
            $command = array_merge($args, array(pending_probe_cli(__FILE__), 'worker', pending_probe_cli($configPath), pending_probe_cli($path)));
            $process = proc_open($command, array(0 => array('file', 'NUL', 'r'), 1 => array('file', $path . '.stdout', 'w'), 2 => array('file', $path . '.stderr', 'w')), $pipes, null, null, array('bypass_shell' => true, 'create_process_group' => true));
            if (! is_resource($process)) { throw new RuntimeException('native_worker_start'); }
            $workers[] = array('process' => $process, 'path' => $path);
        }
        $deadline = microtime(true) + 75;
        foreach ($workers as $worker) { pending_probe_wait($worker['path'] . '.booted', $deadline); }
        $connections = array_map(static fn (array $worker): int => (int) pending_probe_json($worker['path'] . '.booted')['connection'], $workers);
        $bothReady = false; $serialized = false; $order = array(0, 1);
        while (microtime(true) < $deadline) {
            $ready = array(is_file($workers[0]['path'] . '.ready'), is_file($workers[1]['path'] . '.ready'));
            foreach ($workers as $index => $worker) { if (! $ready[$index] && is_file($worker['path'] . '.result')) { throw new RuntimeException('native_operation_before_pending'); } }
            if ($ready[0] && $ready[1]) { $bothReady = true; break; }
            if ($ready[0] || $ready[1]) {
                // A production fix may serialize mutations before the pending
                // query. Confirm an actual cross-worker lock wait rather than
                // interpreting a slow bootstrap as a successful serialization.
                $winner = $ready[0] ? 0 : 1; $other = 1 - $winner;
                $wait = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits w
                    JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID
                    JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID
                    WHERE r.PROCESSLIST_ID=%d AND b.PROCESSLIST_ID=%d', $connections[$other], $connections[$winner]));
                if ((int) $wait > 0) { $serialized = true; $order = array($winner, $other); break; }
            }
            usleep(50000);
        }
        if (! $bothReady && ! $serialized) { throw new RuntimeException('native_pair_not_synchronized'); }
        // Both real activity writes have happened. Release the first operation
        // through check reconciliation and COMMIT while the second stays at its
        // existing query boundary. No SQL/read-view is injected by this probe.
        file_put_contents($workers[$order[0]]['path'] . '.go', 'go');
        pending_probe_wait($workers[$order[0]]['path'] . '.result', $deadline);
        pending_probe_wait($workers[$order[1]]['path'] . '.ready', $deadline);
        file_put_contents($workers[$order[1]]['path'] . '.go', 'go');
        pending_probe_wait($workers[$order[1]]['path'] . '.result', $deadline);
        $results = array();
        foreach ($workers as $worker) {
            $result = pending_probe_json($worker['path'] . '.result');
            if (empty($result['completed']) || ($result['status'] ?? 0) !== 200 || empty($result['barrierReached'])) { throw new RuntimeException('native_worker_operation'); }
            $results[] = $result;
        }
        return array('results' => $results, 'bothAtPendingBeforeFirstCommit' => $bothReady, 'serializedBeforePending' => $serialized, 'releaseOrder' => $order);
    } finally {
        foreach ($workers as $worker) {
            $state = proc_get_status($worker['process']); if ($state['running']) { proc_terminate($worker['process']); } proc_close($worker['process']);
        }
    }
}

$stage = 'configuration'; $reportPath = null;
try {
    $mode = $argv[1] ?? ''; if (! in_array($mode, array('run', 'worker'), true)) { throw new RuntimeException('expected_run_or_worker'); }
    $root = realpath(dirname(__DIR__, 3)); $run = realpath($root . '/_notes/tmp/focus-tracker-mysql8');
    $configPath = realpath($argv[2] ?? '');
    if ($run === false || $configPath === false || pending_probe_path($configPath) !== pending_probe_path($run . '/config.json')) { throw new RuntimeException('config_path_guard'); }
    $config = pending_probe_json($configPath); $wpPath = realpath((string) ($config['wpPath'] ?? '')); $out = realpath((string) ($config['outputDir'] ?? ''));
    if ($wpPath === false || $out === false || pending_probe_path($wpPath) !== pending_probe_path($run . '/wordpress') || ! str_starts_with(pending_probe_path($out) . '/', pending_probe_path($run) . '/')
        || ($config['home'] ?? '') !== 'http://127.0.0.1:33918' || ($config['baseUrl'] ?? '') !== $config['home'] || ($config['dbHost'] ?? '') !== '127.0.0.1:33917' || ($config['dbName'] ?? '') !== 'focus_acceptance_mysql8_20261028') { throw new RuntimeException('disposable_instance_guard'); }
    $_SERVER['HTTP_HOST'] = '127.0.0.1:33918'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['SERVER_PORT'] = '33918'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $stage = 'bootstrap'; require $wpPath . '/wp-load.php'; global $wpdb; $wpdb->suppress_errors(true);
    if (wp_get_environment_type() !== 'local' || ! defined('DISABLE_WP_CRON') || ! DISABLE_WP_CRON || ! Installer::ready()) { throw new RuntimeException('native_site_guard'); }
    $version = (string) $wpdb->get_var('SELECT VERSION()'); $isolation = (string) $wpdb->get_var('SELECT @@transaction_isolation');
    if (! str_starts_with($version, '8.0.') || ! str_contains((string) $wpdb->get_var('SELECT @@version_comment'), 'MySQL Community Server') || $isolation !== 'REPEATABLE-READ') { throw new RuntimeException('native_oracle_rr_guard'); }
    if ($mode === 'worker') {
        $jobPath = realpath($argv[3] ?? '');
        if ($jobPath === false || ! str_starts_with(pending_probe_path($jobPath), pending_probe_path($out) . '/pending-probe-') || ! str_ends_with($jobPath, '.json')) { throw new RuntimeException('worker_path_guard'); }
        $job = pending_probe_json($jobPath); pending_probe_user((int) $job['actor']); $reached = false;
        add_filter('query', static function (string $sql) use ($jobPath, &$reached): string {
            if (! $reached && str_contains($sql, Installer::checks_table()) && str_contains($sql, 'activity_ids_json') && str_contains($sql, 'ORDER BY id ASC FOR UPDATE')) {
                $reached = true; file_put_contents($jobPath . '.ready', 'ready'); pending_probe_wait($jobPath . '.go', microtime(true) + 70);
            }
            return $sql;
        });
        // This pure connection query runs before REST starts a transaction; it
        // creates no InnoDB read-view and selects no learning data.
        pending_probe_save($jobPath . '.booted', array('connection' => (int) $wpdb->get_var('SELECT CONNECTION_ID()')));
        $response = pending_probe_rest($job['method'], '/activities/' . (int) $job['activity'], $job['body']);
        pending_probe_save($jobPath . '.result', array('completed' => true, 'status' => $response->get_status(), 'barrierReached' => $reached));
        echo json_encode(array('completed' => true, 'status' => $response->get_status()), JSON_THROW_ON_ERROR) . "\n"; exit(0);
    }
    $stage = 'fixture'; $fixture = pending_probe_json($out . '/mysql-concurrency-fixture.json'); $teacher = (int) $fixture['teacher']['userId'];
    $container = (new CompositionRoot(PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE, OJ_PLATFORM_VERSION, '6.6', '8.3'), new WordPressHookRegistrar(), new WordPressErrorReporter()))->build(); $container->get(Kernel::class)->boot();
    $groups = $container->get(GroupService::class); $profiles = $container->get(UserProfileRepository::class);
    $tag = 'pending-probe-' . bin2hex(random_bytes(4)); $directory = $out . '/' . $tag;
    if (! mkdir($directory, 0700)) { throw new RuntimeException('probe_directory'); }
    $reportPath = $out . '/' . $tag . '-report.json'; $cases = array();
    foreach (array('delete_delete', 'shorten_shorten', 'shorten_delete') as $case) {
        $stage = $case; $caseDir = $directory . '/' . $case; if (! mkdir($caseDir, 0700)) { throw new RuntimeException('case_directory'); }
        $login = str_replace('-', '_', $tag) . '_' . $case;
        $student = wp_insert_user(array('user_login' => $login, 'user_pass' => wp_generate_password(28, true, false), 'user_email' => $login . '@example.test', 'display_name' => 'Pending probe learner', 'role' => 'subscriber'));
        if (! is_int($student) || $student < 1) { throw new RuntimeException('native_student'); }
        $profiles->create(new UserProfile($student, null, '2000-01-01', AccessStatus::Active, false)); $activities = array(); pending_probe_user($teacher);
        foreach (array('a', 'b') as $letter) {
            $group = $groups->save($teacher, new GroupSaveCommand(0, 'Pending probe ' . $case . ' ' . $letter, str_replace('_', '-', $login) . '-' . $letter, 'Disposable RR reconciliation probe', 'private', 'manual', true, null, 'normal', null));
            $groups->membership($teacher, $group, 'add', $student); $from = intdiv(Clock::now_ms(), 1000) * 1000 - 60000;
            $activities[] = pending_probe_ok(pending_probe_rest('POST', '/activities', array('name' => 'Pending probe ' . $letter, 'context_type' => 'group', 'context_id' => 'group:' . $group, 'problem_ids' => array((int) $fixture['problem']['id']), 'starts_at' => gmdate('c', intdiv($from, 1000)), 'ends_at' => gmdate('c', intdiv($from + 3660000, 1000)))))['id'];
        }
        pending_probe_user($student); $page = Page_Context::issue($student, '/problems/' . (int) $fixture['problem']['id']);
        if (! is_array($page)) { throw new RuntimeException('native_problem_page'); }
        $question = pending_probe_ok(pending_probe_rest('POST', '/checks/next', array('problem_id' => (int) $fixture['problem']['id'], 'context_type' => 'general', 'context_id' => null, 'page_context' => $page['token'], 'draft' => array('source' => "value = 3\nif value < 5:\n    print(value)\n", 'language_id' => 6))));
        $qid = (int) ($question['check']['id'] ?? 0); if (($question['status'] ?? '') !== 'ready' || $qid < 1) { throw new RuntimeException('native_verified_question'); }
        $before = $wpdb->get_row($wpdb->prepare('SELECT status,activity_ids_json,issued_at_ms,expires_at_ms,window_end_ms FROM ' . Installer::checks_table() . ' WHERE id=%d AND user_id=%d', $qid, $student), ARRAY_A);
        $bound = array_map('intval', json_decode((string) $before['activity_ids_json'], true)); sort($bound); $expected = array_map('intval', $activities); sort($expected);
        if ($bound !== $expected || (int) $before['expires_at_ms'] >= (int) $before['window_end_ms']) { throw new RuntimeException('exact_two_long_windows'); }
        $shorten = (int) (ceil(((int) $before['issued_at_ms'] + 2000) / 1000) * 1000);
        while (Clock::now_ms() <= $shorten) { usleep(10000); }
        $shortBody = array('ends_at' => gmdate('c', intdiv($shorten, 1000))); $jobs = array();
        foreach ($activities as $index => $activity) {
            $delete = $case === 'delete_delete' || ($case === 'shorten_delete' && $index === 1);
            $jobs[] = array('actor' => $teacher, 'activity' => (int) $activity, 'method' => $delete ? 'DELETE' : 'PATCH', 'body' => $delete ? array() : $shortBody);
        }
        $workers = pending_probe_pair($jobs, $caseDir, $configPath);
        $after = $wpdb->get_row($wpdb->prepare('SELECT status,activity_ids_json,expires_at_ms,window_end_ms FROM ' . Installer::checks_table() . ' WHERE id=%d AND user_id=%d', $qid, $student), ARRAY_A);
        $ended = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::activities_table() . ' WHERE id IN (%d,%d) AND ends_at <= UTC_TIMESTAMP()', $activities));
        $cases[] = array('scenario' => $case, 'bothWindowsEnded' => $ended === 2, 'questionStatus' => $after['status'], 'remainingBoundCount' => count(json_decode((string) $after['activity_ids_json'], true)),
            'passed' => $ended === 2 && $after['status'] === 'invalidated', 'workers' => $workers);
    }
    $passed = ! in_array(false, array_column($cases, 'passed'), true);
    pending_probe_save($reportPath, array('passed' => $passed, 'installedVersion' => FOCUS_TRACKER_VERSION, 'databaseVersion' => $version, 'isolation' => $isolation, 'cases' => $cases,
        'scope' => 'Real native WordPress groups, profiles, REST activity changes and a verified question. Query filters only pause at the existing pending-check locking query; no additional SQL, read-view, result, or permission is injected. Dedicated students belong only to the two newly created groups. No code or answer keys are exported.'));
    echo json_encode(array('passed' => $passed, 'scenarios' => count($cases), 'failedScenarios' => array_values(array_column(array_filter($cases, static fn (array $case): bool => ! $case['passed']), 'scenario'))), JSON_THROW_ON_ERROR) . "\n";
    exit($passed ? 0 : 1);
} catch (Throwable $error) {
    if ($reportPath !== null) { pending_probe_save($reportPath, array('passed' => false, 'stage' => $stage, 'exceptionClass' => get_class($error), 'code' => (int) $error->getCode())); }
    $symbol = '';
    if ($error instanceof Error && preg_match('/(?:Class "|undefined function |Undefined constant ")([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $error->getMessage(), $match)) { $symbol = ' symbol=' . $match[1]; }
    fwrite(STDERR, 'Native pending-window probe failed: stage=' . $stage . ' class=' . get_class($error) . ' code=' . (int) $error->getCode() . ' file=' . basename($error->getFile()) . ' line=' . $error->getLine() . $symbol . "\n"); exit(1);
}
