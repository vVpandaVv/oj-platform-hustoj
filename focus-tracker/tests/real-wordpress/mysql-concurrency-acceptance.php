<?php
declare(strict_types=1);

/** Isolated Oracle MySQL 8 + native WordPress concurrency acceptance. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$mysqlFinished = false;
register_shutdown_function(static function () use (&$mysqlFinished): void { if (! $mysqlFinished) { fwrite(STDERR, "Native MySQL concurrency acceptance did not complete.\n"); exit(1); } });

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
use FocusTracker\Jobs\Retention_Cleanup;
use FocusTracker\Privacy\Personal_Data;
use FocusTracker\Privacy\Privacy_Guard;
use FocusTracker\Support\Clock;
use FocusTracker\Tracking\Page_Context;

$mysqlChecks = array();
function mysql_accept_check(bool $pass, string $label): void {
    global $mysqlChecks; $mysqlChecks[$label] = $pass;
    if (! $pass) { throw new RuntimeException($label); }
}
function mysql_accept_json(string $path): array { return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
function mysql_accept_save(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) { throw new RuntimeException('Could not save the private native artifact.'); }
}
function mysql_accept_path(string $path): string { return strtolower(str_replace('\\', '/', rtrim($path, '/\\'))); }
function mysql_accept_cli_path(string $path): string {
    // PHP_BINARY is returned in the Windows ANSI code page even though
    // __FILE__/getcwd() and this repository use UTF-8.
    if(preg_match('//u',$path)!==1&&function_exists('sapi_windows_cp_conv')){$converted=sapi_windows_cp_conv(0,65001,$path);if(is_string($converted)){$path=$converted;}}
    $workspace=dirname(__DIR__,3);$normalized=str_replace('\\','/',$path);$base=str_replace('\\','/',$workspace);
    if(str_starts_with(strtolower($normalized),strtolower($base).'/')){$normalized=substr($normalized,strlen($base)+1);}
    if(preg_match('/^[\x20-\x7e]+$/D',$normalized)!==1){throw new RuntimeException('Native Windows child-process arguments require an ASCII runtime path or a workspace-relative path.');}
    return $normalized;
}
function mysql_accept_user(int $id): WP_User { wp_set_current_user(0); $user = wp_set_current_user($id); $user->get_role_caps(); return $user; }
function mysql_accept_rest(string $method, string $path, array $body = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/focus-tracker/v1' . $path);
    $nonce = wp_create_nonce('wp_rest');
    $request->set_header('X-WP-Nonce', $nonce);
    if ($method === 'GET') { $request->set_query_params($body + array('_wpnonce' => $nonce)); }
    else { $request->set_query_params(array('_wpnonce' => $nonce)); $request->set_header('Content-Type', 'application/json'); $request->set_body((string) wp_json_encode($body)); }
    return rest_do_request($request);
}
function mysql_accept_ok(WP_REST_Response $response, string $label): array {
    mysql_accept_check($response->get_status() >= 200 && $response->get_status() < 300 && is_array($response->get_data()), $label); return $response->get_data();
}
function mysql_accept_metrics(): array {
    global $wpdb;
    $values = array();
    foreach ($wpdb->get_results("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_deadlocks','Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_row_lock_time_max','Threads_connected','Threads_running')", ARRAY_A) as $row) { $values[$row['Variable_name']] = (int) $row['Value']; }
    if (! array_key_exists('Innodb_deadlocks',$values)) {
        $deadlocks=$wpdb->get_row("SELECT `COUNT` AS n,STATUS AS metric_status FROM information_schema.INNODB_METRICS WHERE NAME='lock_deadlocks'",ARRAY_A);
        if(is_array($deadlocks)){$values['Innodb_deadlocks']=(int)$deadlocks['n'];$values['deadlockMetricEnabled']=$deadlocks['metric_status']==='enabled';$values['deadlockMetricSource']='information_schema.INNODB_METRICS/lock_deadlocks';}
    } else {$values['deadlockMetricEnabled']=true;$values['deadlockMetricSource']='SHOW GLOBAL STATUS/Innodb_deadlocks';}
    $waits = $wpdb->get_var('SELECT COUNT(*) FROM performance_schema.data_lock_waits');
    $values['currentDataLockWaits'] = $waits === null ? null : (int) $waits;
    return $values;
}
function mysql_accept_database_settings(): array {
    global $wpdb;
    return $wpdb->get_row('SELECT @@GLOBAL.sql_mode AS globalSqlMode,@@SESSION.sql_mode AS wordPressSessionSqlMode,@@GLOBAL.transaction_isolation AS globalTransactionIsolation,@@SESSION.transaction_isolation AS wordPressSessionTransactionIsolation,@@GLOBAL.innodb_deadlock_detect AS deadlockDetection,@@GLOBAL.innodb_lock_wait_timeout AS lockWaitTimeoutSeconds,@@GLOBAL.datadir AS datadir', ARRAY_A);
}
function mysql_accept_packet(array $fixture, int $user, string $uid, int $from, int $duration = 30, bool $material = false): array {
    $problem = $material ? null : (int) $fixture['problem']['id'];
    $path = $material ? '/oj-addons/learning-materials/' . $fixture['material']['id'] : '/problems/' . $problem;
    $page = Page_Context::issue($user, $path, array('group_id' => $fixture['group']['id']), $from);
    mysql_accept_check(is_array($page), 'The native page signer produces actor-bound descriptors');
    $events = array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $from, 'meta' => array('visible' => true, 'focused' => true)),
        array('seq' => 2, 'type' => $material ? 'heartbeat' : 'activity', 'occurred_at' => $from + 1000, 'meta' => array('visible' => true, 'focused' => true)),
        array('seq' => 3, 'type' => 'heartbeat', 'occurred_at' => $from + 15000, 'meta' => array('visible' => true, 'focused' => true)),
        array('seq' => 4, 'type' => 'session_end', 'occurred_at' => $from + $duration * 1000));
    return array('user_id' => $user, 'problem_id' => $problem, 'page_context' => $page['token'], 'context_type' => 'group', 'context_id' => $fixture['group']['contextId'],
        'session_uid' => $uid, 'session_started_at' => $from, 'is_final' => true, 'events' => $events);
}
function mysql_accept_prefix(array $body): string {
    $page = Page_Context::verify($body['page_context'], $body['user_id'], Clock::now_ms(), true);
    if ($page instanceof WP_Error) { throw new RuntimeException('The expected source has an invalid native signature.'); }
    $id = (string) $body['context_id']; $type = $body['context_type'];
    if ($type !== 'general' && ! str_starts_with($id, $type . ':')) { $id = $type . ':' . $id; }
    $identity = array($body['session_uid'], $body['session_started_at'], $body['problem_id'], $type, $id);
    $cutoff = Privacy_Guard::cutoff($body['user_id']); if ($cutoff > 0) { $identity[] = $cutoff; }
    $identity[] = array('page-v1', $body['user_id'], Page_Context::columns($page));
    return 'g6-' . substr(hash('sha256', (string) wp_json_encode($identity)), 0, 32) . '-';
}
function mysql_accept_source(array $body): array {
    global $wpdb;
    $prefix = mysql_accept_prefix($body);
    $rows = $wpdb->get_results($wpdb->prepare('SELECT e.sequence_number,e.event_type,e.occurred_at_ms,p.id,p.event_revision,p.ended_at_ms FROM ' . Installer::participations_table() . ' p JOIN ' . Installer::events_table() . ' e ON e.participation_id=p.id WHERE p.session_uid >= %s AND p.session_uid < %s AND e.sequence_number > 0 AND e.sequence_number < 2147483648 ORDER BY e.sequence_number,p.id', $prefix, $prefix . 'g'), ARRAY_A);
    mysql_accept_check(is_array($rows) && $wpdb->last_error === '', 'Independent SQL reads committed physical source events'); return $rows;
}
function mysql_accept_activity(array $fixture, string $name, int $from, int $to, int $group = 0): array {
    mysql_accept_user($fixture['teacher']['userId']);
    return mysql_accept_ok(mysql_accept_rest('POST', '/activities', array('name' => $name, 'context_type' => 'group', 'context_id' => $fixture['groups'][$group]['contextId'],
        'problem_ids' => array((int) $fixture['problem']['id']), 'starts_at' => gmdate('c', intdiv($from, 1000)), 'ends_at' => gmdate('c', intdiv($to, 1000)))), 'The ordinary native group teacher creates an authorized activity');
}
function mysql_accept_rotate_private_actors(array $fixture, string $runId): array {
    $container=(new CompositionRoot(PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE,OJ_PLATFORM_VERSION,'6.6','8.3'),new WordPressHookRegistrar(),new WordPressErrorReporter()))->build();$container->get(Kernel::class)->boot();
    $groups=$container->get(GroupService::class);$profiles=$container->get(UserProfileRepository::class);$teacher=$fixture['teacher']['userId'];mysql_accept_user($teacher);
    foreach(array('privacyStudent'=>'privacy','retentionStudent'=>'retention') as $field=>$kind){
        $old=$fixture[$field];foreach($fixture['groups'] as $group){$groups->membership($teacher,$group['id'],'remove',$old['userId']);}
        $login=$fixture['runId'].'_'.substr($runId,-6).'_'.$kind;$password=wp_generate_password(28,true,false);
        $id=wp_insert_user(array('user_login'=>$login,'user_pass'=>$password,'user_email'=>$login.'@example.test','display_name'=>'MySQL8 repeat '.$kind,'role'=>'subscriber'));
        mysql_accept_check(is_int($id)&&$id>0,'Each native repeat uses fresh isolated erasure and retention actors');$profiles->create(new UserProfile($id,null,'2000-01-01',AccessStatus::Active,false));
        foreach($fixture['groups'] as $group){$groups->membership($teacher,$group['id'],'add',$id);}
        $user=new WP_User($id);$key=AddonCapabilityKeyV1::wordpress('focus_tracker_participate');mysql_accept_check(user_can($user,$key)&&!array_key_exists($key,$user->caps),'Repeated private actors also inherit participation without a direct grant');
        $fixture[$field]=array('username'=>$login,'password'=>$password,'userId'=>$id,'kind'=>$kind);
    }
    foreach($fixture['groups'] as $group){mysql_accept_check(count($groups->activeLearnerUserIds($group['id']))===count($fixture['students'])+2,'Private-actor rotation preserves the same native teacher roster size');}
    return $fixture;
}
function mysql_accept_worker(array $job, string $jobPath): array {
    global $wpdb;
    mysql_accept_user((int) $job['actor']);
    if (! empty($job['insertBarrier'])) {
        $paused = false;$GLOBALS['mysqlBarrierObservations']=array();
        $barrierTable=match($job['insertTable']??'participations'){'checks'=>Installer::checks_table(),'events'=>Installer::events_table(),default=>Installer::participations_table()};
        add_filter('query', static function (string $sql) use (&$paused, $job, $jobPath, $barrierTable): string {
            if (! $paused && preg_match('/^INSERT\s+INTO\s+`?' . preg_quote($barrierTable, '/') . '`?\s/i', $sql)) {
                $paused = true; file_put_contents($jobPath . '.insert-ready', 'ready'); $waitingSince=microtime(true);$until = $waitingSince + 15;
                while (count(glob(substr($job['barrier'],0,-3) . '-job-*.json.insert-ready')) < (int) $job['insertBarrier']) {
                    if(($job['insertTable']??'')==='checks'&&microtime(true)-$waitingSince>0.75){global $wpdb;$locks=$wpdb->get_results($wpdb->prepare('SELECT r.INDEX_NAME AS indexName,r.LOCK_MODE AS requestingMode,b.LOCK_MODE AS blockingMode FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks r ON r.ENGINE=w.ENGINE AND r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.data_locks b ON b.ENGINE=w.ENGINE AND b.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID WHERE r.OBJECT_SCHEMA=DATABASE() AND r.OBJECT_NAME=%s',$barrierTable),ARRAY_A);if(is_array($locks)&&$locks!==array()){$GLOBALS['mysqlBarrierObservations'][]=array('reason'=>'unrelated_check_table_serialization','lockMetadata'=>$locks);break;}}
                    if (microtime(true) > $until) { throw new RuntimeException('The native INSERT barrier did not receive every real transaction.'); } usleep(5000);
                }
            }
            return $sql;
        });
    }
    file_put_contents($jobPath . '.ready', 'ready');
    $deadline = microtime(true) + 30;
    while (! is_file($job['barrier'])) { if (microtime(true) > $deadline) { throw new RuntimeException('The native concurrency barrier timed out.'); } usleep(10000); }
    $started = microtime(true); $samples = array(); $responses = array(); $retries = 0; $GLOBALS['mysqlWorkerTrace'] =& $responses;
    $operation = $job['operation'];
    if ($operation === 'cleanup') { Retention_Cleanup::run(); $responses[] = array('status' => 200, 'code' => 'cleanup_completed'); }
    elseif ($operation === 'erase_account') {
        $done = false;
        for ($index = 0; $index < 40; ++$index) { if (Personal_Data::erase_user((int) $job['target'])) { $done = true; break; } ++$retries; usleep(50000); }
        if (! $done) { throw new RuntimeException('The native account erasure did not finish.'); }
        $responses[] = array('status' => 200, 'code' => 'account_erased');
    }
    elseif ($operation === 'erase') {
        $user = get_userdata((int) $job['target']); $done = false;
        for ($index = 0; $index < 120; ++$index) {
            $at = microtime(true); $result = Personal_Data::erase_data($user->user_email); $samples[] = (microtime(true) - $at) * 1000;
            if ($result instanceof WP_Error) { ++$retries; $responses[] = array('status' => 503, 'code' => $result->get_error_code()); usleep(50000); continue; }
            $responses[] = array('status' => 200, 'done' => $result['done']);
            if ($result['done']) { $done = true; break; } usleep(20000);
        }
        if (! $done) { throw new RuntimeException('Native bounded privacy erasure could not finish.'); }
    } else {
        for ($repeat = 0; $repeat < ($job['repeat'] ?? 1); ++$repeat) {
            $accepted = false;
            for ($attempt = 0; $attempt < 20; ++$attempt) {
                $at = microtime(true); $response = mysql_accept_rest($job['method'] ?? 'POST', $job['route'] ?? '/events', $job['body'] ?? array());
                $samples[] = (microtime(true) - $at) * 1000; $data = $response->get_data();
                $entry = array('status' => $response->get_status(), 'code' => $data['code'] ?? null, 'stored' => $data['stored'] ?? null,
                    'acknowledged' => $data['acknowledged'] ?? null, 'policyDropped' => $data['policyDropped'] ?? null, 'participationIds' => $data['participation_ids'] ?? array());
                if(str_starts_with($job['route']??'','/checks')){$entry['resultStatus']=$data['status']??null;$entry['affectsFocusScore']=$data['affectsFocusScore']??($data['check']['affectsFocusScore']??null);$entry['noMisfocusClaim']=!isset($data['message'])||!in_array($data['status']??'',array('incorrect','unknown','skipped','unanswered','invalidated'),true)||str_contains($data['message'],'미집중으로 판정하지');$entry['privateAnswerAbsent']=!str_contains(wp_json_encode($data),'"correctAnswer"')&&!str_contains(wp_json_encode($data),'"proof"')&&!str_contains(wp_json_encode($data),'"verification"');if(isset($data['check'])){$entry['publicCheck']=array('id'=>$data['check']['id'],'choiceIds'=>array_column($data['check']['choices'],'id'));}}
                $responses[] = $entry;
                if (in_array($entry['status'], array(423, 503), true)) { ++$retries; usleep(50000 + $attempt * 20000); continue; }
                if (! in_array($entry['status'], $job['expectedStatuses'] ?? array(200), true)) { throw new RuntimeException('An unexpected native REST status was returned.'); }
                if (($job['route'] ?? '/events') === '/events' && $entry['status'] === 200 && $entry['acknowledged'] !== true) { throw new RuntimeException('A successful event request had no durable ACK.'); }
                $accepted = true; break;
            }
            if (! $accepted) { throw new RuntimeException('A native operation exhausted its bounded retry budget.'); }
        }
    }
    return array('passed' => true, 'operation' => $operation, 'durationMs' => round((microtime(true) - $started) * 1000, 3), 'serviceSamplesMs' => $samples, 'retries' => $retries, 'responses' => $responses,'barrierObservations'=>$GLOBALS['mysqlBarrierObservations']??array(),'eventInsertObserved'=>($job['insertTable']??'')==='events'&&is_file($jobPath.'.insert-ready'));
}
function mysql_accept_pool(array $jobs, string $directory, string $configPath, int $concurrency): array {
    global $mysqlChecks;
    if(!chdir(dirname(__DIR__,3))){throw new RuntimeException('Could not select the known native worker workspace.');}
    if (! is_dir($directory) && ! mkdir($directory, 0700, true)) { throw new RuntimeException('Could not prepare the private native worker directory.'); }
    $arguments = array(mysql_accept_cli_path(PHP_BINARY), '-n', '-d', 'extension_dir=' . mysql_accept_cli_path(ini_get('extension_dir')));
    foreach (array('mysqli','mbstring','openssl','curl','zip','intl','fileinfo','sodium') as $extension) { $arguments[] = '-d'; $arguments[] = 'extension=' . $extension; }
    $results = array(); $waitSamples = array();
    foreach (array_chunk($jobs, $concurrency) as $wave => $batch) {
        $barrier = $directory . '/wave-' . $wave . '.go'; $workers = array();
        foreach ($batch as $index => $job) {
            $path = $directory . '/wave-' . $wave . '-job-' . $index . '.json'; $job['barrier'] = $barrier; mysql_accept_save($path, $job);
            $command = array_merge($arguments, array(mysql_accept_cli_path(__FILE__), 'worker', mysql_accept_cli_path($configPath), mysql_accept_cli_path($path)));
            $descriptors = array(0 => array('file', 'NUL', 'r'), 1 => array('file', $path . '.stdout', 'w'), 2 => array('file', $path . '.stderr', 'w'));
            $process = proc_open($command, $descriptors, $pipes, null, null, array('bypass_shell' => true, 'create_process_group' => true));
            if (! is_resource($process)) { throw new RuntimeException('Could not start an independent native PHP worker.'); }
            $GLOBALS['mysqlChildProcesses'][] = $process;
            $workers[] = array('process' => $process, 'path' => $path, 'exitCode' => null);
        }
        $readyDeadline = microtime(true) + 45;
        while (count(array_filter($workers, static fn (array $worker): bool => is_file($worker['path'] . '.ready'))) !== count($workers)) {
            if (microtime(true) > $readyDeadline) { foreach ($workers as $worker) { proc_terminate($worker['process']); } throw new RuntimeException('Native workers did not reach the synchronized barrier.'); }
            foreach ($workers as $worker) { $state = proc_get_status($worker['process']); if (! $state['running'] && ! is_file($worker['path'] . '.ready')) { throw new RuntimeException('A native worker failed while booting WordPress.'); } }
            usleep(20000);
        }
        file_put_contents($barrier, 'go'); $deadline = microtime(true) + 120; $nextSample = 0.0;
        do {
            $running = 0;
            foreach ($workers as &$worker) { if ($worker['exitCode'] !== null) { continue; } $state = proc_get_status($worker['process']); if ($state['running']) { ++$running; } else { $worker['exitCode'] = $state['exitcode']; } } unset($worker);
            if (microtime(true) >= $nextSample) { $waitSamples[] = mysql_accept_metrics(); $nextSample = microtime(true) + 0.1; }
            if (microtime(true) > $deadline) { foreach ($workers as $worker) { proc_terminate($worker['process']); } throw new RuntimeException('A synchronized native worker wave timed out.'); }
            if ($running > 0) { usleep(10000); }
        } while ($running > 0);
        foreach ($workers as $worker) {
            proc_close($worker['process']);
            mysql_accept_check($worker['exitCode'] === 0 && is_file($worker['path'] . '.result'), 'Every independent native worker completes with a result artifact');
            $result = mysql_accept_json($worker['path'] . '.result'); mysql_accept_check(($result['passed'] ?? false) === true, 'All native worker operations finish within their bounded retry policy'); $results[] = $result;
        }
        echo json_encode(array('progress' => basename($directory), 'wave' => $wave + 1, 'waves' => (int) ceil(count($jobs) / $concurrency), 'completedWorkers' => count($results)), JSON_THROW_ON_ERROR) . "\n";
    }
    return array('workers' => $results, 'lockSamples' => $waitSamples);
}

try {
    $phase = $argv[1] ?? '';
    mysql_accept_check(in_array($phase, array('prepare','cleanup-fixture','probe','replay-probe','check-probe','checks','run','worker','audit'), true), 'The native MySQL acceptance phase is valid');
    $configPath = realpath($argv[2] ?? ''); $config = mysql_accept_json($configPath ?: '');
    $workspace = realpath(dirname(__DIR__, 3)); $runRoot = realpath($workspace . '/_notes/tmp/focus-tracker-mysql8');
    $wpPath = realpath((string) ($config['wpPath'] ?? '')); $output = realpath((string) ($config['outputDir'] ?? ''));
    mysql_accept_check($runRoot !== false && $wpPath !== false && $output !== false && mysql_accept_path($wpPath) === mysql_accept_path($runRoot . '/wordpress')
        && str_starts_with(mysql_accept_path($output) . '/', mysql_accept_path($runRoot) . '/'), 'The native runner is restricted to its new MySQL8 fixture workspace');
    mysql_accept_check(($config['baseUrl'] ?? $config['home'] ?? '') === 'http://127.0.0.1:33918' && ($config['dbHost'] ?? '') === '127.0.0.1:33917'
        && ($config['dbName'] ?? '') === 'focus_acceptance_mysql8_20261028', 'Only the exact new Oracle MySQL8 loopback database is allowed');
    $_SERVER['HTTP_HOST'] = '127.0.0.1:33918'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['SERVER_PORT'] = '33918';
    $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    require $wpPath . '/wp-load.php';
    mysql_accept_check(wp_get_environment_type() === 'local' && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, 'The native fixture is local and background cron is disabled during controlled races');
    $wpdb->suppress_errors(true);
    $version = (string) $wpdb->get_var('SELECT VERSION()'); $versionComment = (string) $wpdb->get_var('SELECT @@version_comment');
    mysql_accept_check(preg_match('/^8\.0\.[0-9]+/', $version) === 1 && ! str_contains(strtolower($version . $versionComment), 'mariadb') && str_contains($versionComment, 'MySQL Community Server'), 'The actual server is Oracle MySQL8 Community Server rather than MariaDB or a fixture adapter');
    mysql_accept_check(Installer::ready(), 'The actual installed Focus Tracker schema is ready');
    $fixturePath = $output . '/mysql-concurrency-fixture.json';
    if ($phase === 'worker') {
        $jobPath = realpath($argv[3] ?? '');
        mysql_accept_check($jobPath !== false && str_starts_with(mysql_accept_path($jobPath), mysql_accept_path($output) . '/') && str_ends_with($jobPath, '.json'), 'The native worker job stays inside the private artifact directory');
        $job = mysql_accept_json($jobPath); $result = mysql_accept_worker($job, $jobPath); mysql_accept_save($jobPath . '.result', $result); $mysqlFinished = true; exit;
    }
    if ($phase === 'prepare') {
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; $activated = array();
        foreach (array('learning-materials/learning-materials.php','edu-games/edu-games.php') as $plugin) { if (! is_plugin_active($plugin)) { $result = activate_plugin($plugin); mysql_accept_check(! is_wp_error($result), 'Native WordPress activates the real learning resource add-ons'); $activated[] = $plugin; } }
        if ($activated !== array()) { $mysqlFinished = true; echo json_encode(array('phase' => 'prepare', 'needsFreshRequest' => true, 'activated' => $activated), JSON_THROW_ON_ERROR) . "\n"; exit; }
        mysql_accept_check(! is_file($fixturePath), 'A native concurrency fixture cannot overwrite earlier accounts');
        $container = (new CompositionRoot(PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE, OJ_PLATFORM_VERSION, '6.6', '8.3'), new WordPressHookRegistrar(), new WordPressErrorReporter()))->build(); $container->get(Kernel::class)->boot();
        $groups = $container->get(GroupService::class); $profiles = $container->get(UserProfileRepository::class);
        $count = (int) ($argv[3] ?? 60); mysql_accept_check($count >= 30 && $count <= 60, 'The fixture prepares thirty to sixty distinct native learners');
        $admin = get_user_by('login', (string) ($config['admin']['username'] ?? $config['admin']['login'])); mysql_accept_check($admin instanceof WP_User && user_can($admin, 'manage_options'), 'A native disposable administrator authorizes account setup');
        $runId = 'fm8' . bin2hex(random_bytes(5)); $accounts = array();
        foreach (array_merge(array('teacher','privacy','retention'), array_map(static fn (int $index): string => 'student' . $index, range(1, $count))) as $kind) {
            $login = $runId . '_' . $kind; $password = wp_generate_password(28, true, false);
            $id = wp_insert_user(array('user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.test', 'display_name' => 'MySQL8 ' . $kind, 'role' => $kind === 'teacher' ? 'oj_group_manager' : 'subscriber'));
            mysql_accept_check(is_int($id) && $id > 0, 'Native WordPress creates all isolated load-test accounts');
            $profiles->create(new UserProfile($id, null, '2000-01-01', AccessStatus::Active, false)); $accounts[$kind] = array('username' => $login, 'password' => $password, 'userId' => $id, 'kind' => $kind);
        }
        foreach (array('focus_tracker_view_self','learning_materials_view','edu_games_play') as $cap) { get_role('subscriber')->add_cap(AddonCapabilityKeyV1::wordpress($cap), true); }
        $teacher = new WP_User($accounts['teacher']['userId']); foreach (array('focus_tracker_view','focus_tracker_manage','focus_tracker_view_self') as $cap) { $teacher->add_cap(AddonCapabilityKeyV1::wordpress($cap), true); }
        mysql_accept_check(! user_can($teacher, 'manage_options') && user_can($teacher, 'oj_access_console'), 'The load-test teacher is a real group manager rather than an administrator');
        $students = array_values(array_filter($accounts, static fn (array $account): bool => str_starts_with($account['kind'], 'student')));
        $contexts = array(); mysql_accept_user($teacher->ID);
        foreach (array('A','B') as $label) {
            $group = $groups->save($teacher->ID, new GroupSaveCommand(0, 'MySQL8 group ' . $label, $runId . strtolower($label), 'Disposable Oracle MySQL concurrency test', 'private', 'manual', true, null, 'normal', null));
            foreach (array_merge($students, array($accounts['privacy'],$accounts['retention'])) as $account) { $groups->membership($teacher->ID, $group, 'add', $account['userId']); }
            $contexts[] = array('id' => $group, 'contextId' => 'group:' . $group, 'label' => 'MySQL8 group ' . $label);
        }
        $key = AddonCapabilityKeyV1::wordpress('focus_tracker_participate'); foreach ($students as $student) { $user = new WP_User($student['userId']); mysql_accept_check(user_can($user, $key) && ! array_key_exists($key, $user->caps), 'Every load learner inherits participation without a direct grant'); }
        $storage = new \LearningMaterials\Materials\Material_Storage(); $storageName = $runId . '.txt'; try { $storage->info($storageName); } catch (RuntimeException $ignored) {}
        $text = "Oracle MySQL8 load-test material.\nTwo tabs exercise real group schedules and page categories.\n";
        mysql_accept_check(file_put_contents(WP_CONTENT_DIR . '/learning-materials-private/' . $storageName, $text, LOCK_EX) === strlen($text), 'The native resource fixture has a real protected TXT file');
        $material = (new \LearningMaterials\Materials\Material_Repository())->create('MySQL8 native reading', 'Disposable concurrency acceptance material.', array('storage_filename' => $storageName, 'original_filename' => 'mysql8-reading.txt','extension' => 'txt','mime_type' => 'text/plain','file_size' => strlen($text)), $teacher->ID, array());
        $problemData = is_file($output . '/problem-fixture.json') ? mysql_accept_json($output . '/problem-fixture.json')['problem'] : array('id' => 123);
        $problemData['url'] = $problemData['problemUrl'] ?? $config['baseUrl'] . '/problems/' . $problemData['id']; $problemData['submitUrl'] = $problemData['submitUrl'] ?? $config['baseUrl'] . '/submit/' . $problemData['id'];
        $fixture = array('baseUrl' => $config['baseUrl'], 'runId' => $runId, 'teacher' => $accounts['teacher'], 'students' => $students, 'privacyStudent' => $accounts['privacy'], 'retentionStudent' => $accounts['retention'],
            'group' => $contexts[0], 'groups' => $contexts, 'memberships' => 'All synthetic learners belong to both groups; signed group A must never become group B data.',
            'material' => array('id' => $material, 'url' => $config['baseUrl'] . '/oj-addons/learning-materials/' . $material), 'problem' => $problemData, 'artifactsDir' => $output);
        mysql_accept_save($fixturePath, $fixture);
        mysql_accept_save($output . '/mysql-concurrency-prepare-report.json', array('passed' => true, 'assertionCount' => count($mysqlChecks), 'checks' => $mysqlChecks, 'students' => $count, 'tabsPerStudent' => 2, 'databaseVersion' => $version, 'versionComment' => $versionComment, 'databaseSettings' => mysql_accept_database_settings(),
            'scope' => 'Real WordPress roles, active profiles and native group membership. Only self-summary/material/game permissions are added; participation is inherited. Credentials remain in the private fixture only.'));
    } elseif ($phase === 'cleanup-fixture') {
        $fixture = mysql_accept_json($fixturePath);
        $mainIds = array_map(static fn (array $student): int => (int) $student['userId'], $fixture['students']);
        $privateIds = array((int) $fixture['privacyStudent']['userId'], (int) $fixture['retentionStudent']['userId']);
        $teacherId = (int) $fixture['teacher']['userId'];
        mysql_accept_check(count($mainIds) === 60 && count(array_unique($mainIds)) === 60
            && count(array_unique($privateIds)) === 2 && min($privateIds) > 0
            && array_intersect($mainIds, $privateIds) === array() && ! in_array($teacherId, $privateIds, true),
            'Only the two isolated maintenance actors outside the sixty main learners can be detached');
        foreach (array('privacyStudent', 'retentionStudent') as $field) {
            $actor = $fixture[$field]; $user = get_userdata((int) $actor['userId']);
            mysql_accept_check($user instanceof WP_User && $user->user_login === $actor['username']
                && str_starts_with($user->user_login, $fixture['runId'] . '_')
                && ($actor['kind'] ?? '') === ($field === 'privacyStudent' ? 'privacy' : 'retention'),
                'Both detached accounts have the exact original native fixture identity and maintenance purpose');
        }
        $container = (new CompositionRoot(PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE, OJ_PLATFORM_VERSION, '6.6', '8.3'), new WordPressHookRegistrar(), new WordPressErrorReporter()))->build();
        $container->get(Kernel::class)->boot(); $groups = $container->get(GroupService::class); mysql_accept_user($teacherId);
        mysql_accept_check(count($fixture['groups']) === 2 && $fixture['group'] === $fixture['groups'][0]
            && count(array_unique(array_column($fixture['groups'], 'id'))) === 2, 'Only the two original dedicated load-test groups are selected');
        $beforeRosters = array();
        foreach ($fixture['groups'] as $index => $entry) {
            $group = $groups->findManaged((int) $entry['id'], $teacherId);
            mysql_accept_check($group->ownerUserId() === $teacherId && $group->slug() === $fixture['runId'] . ($index === 0 ? 'a' : 'b')
                && $group->visibility() === 'private' && $entry['contextId'] === 'group:' . $group->id(),
                'Native ownership, private visibility and exact fixture group scope are verified before membership changes');
            $beforeRosters[$group->id()] = $groups->activeLearnerUserIds($group->id());
            mysql_accept_check(array_diff($mainIds, $beforeRosters[$group->id()]) === array()
                && array_diff($beforeRosters[$group->id()], array_merge($mainIds, $privateIds)) === array(),
                'Each selected group contains all sixty main learners and no unrelated learner');
        }
        $snapshot = static function () use ($wpdb): array {
            $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'focus_tracker_') . '%'));
            mysql_accept_check(is_array($tables) && count($tables) === 8, 'All eight native Focus tables are covered by the fixture-cleanup preservation digest');
            $result = array();
            foreach ($tables as $table) {
                mysql_accept_check(preg_match('/^[A-Za-z0-9_]+$/D', $table) === 1, 'Preservation snapshot table identifiers are safe');
                $rows = $wpdb->get_results('SELECT * FROM `' . $table . '`', ARRAY_A); mysql_accept_check(is_array($rows) && $wpdb->last_error === '', 'The native preservation snapshot reads committed learning records');
                $hashes = array(); foreach ($rows as $row) { ksort($row); $hashes[] = hash('sha256', serialize($row)); } sort($hashes);
                $result[$table] = array('rows' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes)));
            }
            ksort($result); return $result;
        };
        $beforeData = $snapshot(); $mainUsers = array();
        foreach ($mainIds as $userId) { $mainUsers[$userId] = hash('sha256', serialize(array($wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE ID=%d", $userId), ARRAY_A), $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->usermeta} WHERE user_id=%d ORDER BY umeta_id", $userId), ARRAY_A)))); }
        $fullReport = $output . '/mysql-concurrency-report.json'; $fullReportHash = hash_file('sha256', $fullReport);
        $removed = 0;
        foreach ($fixture['groups'] as $entry) { foreach ($privateIds as $userId) { if ($groups->membershipStatus((int) $entry['id'], $userId) === 'active') { $groups->membership($teacherId, (int) $entry['id'], 'remove', $userId); ++$removed; } } }
        foreach ($fixture['groups'] as $entry) {
            $afterIds = $groups->activeLearnerUserIds((int) $entry['id']); $expected = array_values(array_diff($beforeRosters[$entry['id']], $privateIds)); sort($expected);
            mysql_accept_check($afterIds === $expected && count($afterIds) === 60, 'Native membership removal changes only the two maintenance actors and preserves all sixty main learners');
        }
        foreach ($mainIds as $userId) { mysql_accept_check($mainUsers[$userId] === hash('sha256', serialize(array($wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE ID=%d", $userId), ARRAY_A), $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->usermeta} WHERE user_id=%d ORDER BY umeta_id", $userId), ARRAY_A)))), 'Every main learner account and WordPress user metadata survives fixture cleanup unchanged'); }
        mysql_accept_check($snapshot() === $beforeData && hash_file('sha256', $fullReport) === $fullReportHash, 'Every native learning record and the completed 1047-worker report survives fixture cleanup unchanged');
        foreach ($privateIds as $userId) { mysql_accept_check(get_userdata($userId) instanceof WP_User, 'Detached maintenance accounts remain present; no account or learning material is deleted'); }
        mysql_accept_save($output . '/mysql-concurrency-fixture-cleanup.json', array('passed' => true, 'assertionCount' => count($mysqlChecks), 'checks' => $mysqlChecks, 'capturedAt' => gmdate('c'),
            'detachedAccounts' => 2, 'selectedGroups' => 2, 'removedMemberships' => $removed, 'mainLearnersPreserved' => 60, 'learningDataSnapshot' => $beforeData, 'completedRunReportSha256' => $fullReportHash,
            'scope' => 'Post-run synthetic-fixture isolation only, through native GroupService membership removal. The sixty main learner accounts, permissions, learning records, resource files and completed run evidence remain unchanged.'));
    } elseif ($phase === 'probe') {
        $fixture = mysql_accept_json($fixturePath); $id = 'probe-' . bin2hex(random_bytes(4)); $from=intdiv(Clock::now_ms(),1000)*1000-90000;
        $activity=mysql_accept_activity($fixture,'MySQL8 native gap-lock probe',$from,$from+30000);
        // Select two distinct actors whose future source keys fall in the same
        // existing unique-index gap. Existing rows are never deleted to arrange it.
        $uids=$wpdb->get_col('SELECT session_uid FROM '.Installer::participations_table().' ORDER BY session_uid'); $buckets=array(); $pair=null;
        for($attempt=0;$attempt<4000;++$attempt){
            $actor=$fixture['students'][$attempt%2]['userId']; $body=mysql_accept_packet($fixture,$actor,$fixture['runId'].'-'.$id.'-'.$attempt,$from); $prefix=mysql_accept_prefix($body); $gap=0;
            foreach($uids as $uid){if(strcmp($uid,$prefix)<0){++$gap;}else{break;}}
            if(isset($buckets[$gap])&&$buckets[$gap]['user_id']!==$actor){$pair=array($buckets[$gap],$body);break;}
            $buckets[$gap]=$body;
        }
        mysql_accept_check(is_array($pair),'Two isolated actors map to the same real unique-index gap without removing data');
        $before=mysql_accept_metrics();mysql_accept_check(($before['deadlockMetricEnabled']??false)===true,'The native deadlock counter is available and enabled'); $jobs=array(); foreach($pair as $body){$jobs[]=array('operation'=>'source_gap_probe','actor'=>$body['user_id'],'body'=>$body,'insertBarrier'=>2);}
        $evidence=mysql_accept_pool($jobs,$output.'/'.$id,$configPath,2); $after=mysql_accept_metrics();
        $outcome=$after['Innodb_deadlocks']-$before['Innodb_deadlocks'];
        foreach($pair as $body){mysql_accept_check(count(mysql_accept_source($body))===4,'The native source-gap probe eventually preserves every real source event once');}
        $report=array('passed'=>$outcome===0,'capturedAt'=>gmdate('c'),'deadlockDelta'=>$outcome,'databaseVersion'=>$version,'versionComment'=>$versionComment,'databaseSettings'=>mysql_accept_database_settings(),'before'=>$before,'after'=>$after,'workerEvidence'=>$evidence,
            'scope'=>'Two real native Events REST transactions pause through the standard WordPress query filter immediately before their first actual participation INSERT. SQL, responses, privileges and DB contents are not replaced. A same-gap pair is selected from current keys without deleting rows.');
        mysql_accept_save($output.'/'.$id.'-report.json',$report);
        mysql_accept_check($outcome===0,'Brand-new independent source transactions introduce no InnoDB gap-lock deadlock');
    } elseif($phase==='replay-probe'){
        $fixture=mysql_accept_json($fixturePath);$id='replay-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3));$from=intdiv(Clock::now_ms(),1000)*1000-90000;mysql_accept_activity($fixture,'Oracle pure replay probe',$from,$from+30000);
        $bodies=array();foreach(array_slice($fixture['students'],0,2) as $index=>$student){$body=mysql_accept_packet($fixture,$student['userId'],$fixture['runId'].'-'.substr($id,-6).'-rp-'.$index,$from);mysql_accept_user($student['userId']);$seed=mysql_accept_ok(mysql_accept_rest('POST','/events',$body),'Pure replay begins with real native committed source');mysql_accept_check($seed['stored']===6,'Every replay source initially has four real and two server-control observations');$bodies[]=$body;}
        $before=mysql_accept_metrics();$jobs=array();for($iteration=0;$iteration<20;++$iteration){foreach($bodies as $body){$jobs[]=array('operation'=>'pure_replay','actor'=>$body['user_id'],'body'=>$body,'insertTable'=>'events','insertBarrier'=>2);}}
        $evidence=mysql_accept_pool($jobs,$output.'/'.$id,$configPath,2);$after=mysql_accept_metrics();$attempted=0;
        foreach($evidence['workers'] as $worker){if($worker['eventInsertObserved']){++$attempted;}foreach($worker['responses'] as $response){if($response['status']===200){mysql_accept_check($response['stored']===0&&$response['acknowledged']===true,'Pure duplicate replay ACKs without introducing an event');}}}
        foreach($bodies as $body){$rows=mysql_accept_source($body);mysql_accept_check(count($rows)===4&&count(array_unique(array_column($rows,'sequence_number')))===4,'Twenty pure replays preserve each original source observation exactly once');}
        $deadlocks=$after['Innodb_deadlocks']-$before['Innodb_deadlocks'];
        $publicEvidence=$evidence;foreach($publicEvidence['workers'] as &$worker){foreach($worker['responses'] as &$response){unset($response['participationIds']);}unset($response);}unset($worker);
        mysql_accept_save($output.'/'.$id.'-report.json',array('passed'=>$deadlocks===0&&$attempted===0,'phase'=>$phase,'capturedAt'=>gmdate('c'),'actorCount'=>2,'replaysPerActor'=>20,'eventInsertAttempts'=>$attempted,'deadlockDelta'=>$deadlocks,'before'=>$before,'after'=>$after,'workerEvidence'=>$publicEvidence,'installedVersion'=>FOCUS_TRACKER_VERSION,
            'installedHashes'=>array('Events_Controller'=>hash_file('sha256',$wpPath.'/wp-content/plugins/focus-tracker/src/Rest/Controllers/Events_Controller.php'),'Event_Repository'=>hash_file('sha256',$wpPath.'/wp-content/plugins/focus-tracker/src/Database/Event_Repository.php')),
            'scope'=>'Real native REST seeds four real and two control observations, then forty same-source requests synchronize only an actual event INSERT. If known-tuple filtering avoids the INSERT, no artificial barrier is applied. No raw tuples, source IDs, actor IDs, SQL, token or answer key is exported.'));
        mysql_accept_check($deadlocks===0,'Pure native replay produces no InnoDB deadlock');mysql_accept_check($attempted===0,'Known native observations do not enter duplicate-key INSERT processing');
    } elseif ($phase === 'check-probe' || $phase === 'checks') {
        $fixture=mysql_accept_json($fixturePath);$checkRunId='checks-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3));$checkDir=$output.'/'.$checkRunId;mkdir($checkDir,0700,true);
        $checkActors=$fixture['students'];$checkFixture=$fixture;
        if($phase==='checks'){mysql_accept_check(count($checkActors)===60,'The full native question burst uses sixty distinct learner accounts');}
        if($phase==='check-probe'){
            $container=(new CompositionRoot(PluginEnvironment::fromPluginFile(OJ_PLATFORM_FILE,OJ_PLATFORM_VERSION,'6.6','8.3'),new WordPressHookRegistrar(),new WordPressErrorReporter()))->build();$container->get(Kernel::class)->boot();$profiles=$container->get(UserProfileRepository::class);$groups=$container->get(GroupService::class);$teacher=$fixture['teacher']['userId'];mysql_accept_user($teacher);
            $group=$groups->save($teacher,new GroupSaveCommand(0,'Oracle check storage probe',str_replace('-','',$checkRunId),'Disposable isolated native check storage actors','private','manual',true,null,'normal',null));$checkActors=array();
            for($index=0;$index<2;++$index){$login=$fixture['runId'].'_cp_'.substr($checkRunId,-6).'_'.$index;$id=wp_insert_user(array('user_login'=>$login,'user_pass'=>wp_generate_password(28,true,false),'user_email'=>$login.'@example.test','display_name'=>'Oracle check probe '.$index,'role'=>'subscriber'));mysql_accept_check(is_int($id)&&$id>0,'The short check probe uses fresh native actors without changing any main learner quota');$profiles->create(new UserProfile($id,null,'2000-01-01',AccessStatus::Active,false));$groups->membership($teacher,$group,'add',$id);$checkActors[]=array('userId'=>$id);}
            $checkFixture['group']=array('id'=>$group,'contextId'=>'group:'.$group,'label'=>'Oracle check storage probe');$checkFixture['groups']=array($checkFixture['group']);
        }
        $checkFrom=intdiv(Clock::now_ms(),1000)*1000-5000;$checkActivity=mysql_accept_activity($checkFixture,'Oracle automatic check burst',$checkFrom,$checkFrom+605000);
        $rawBefore=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::events_table());$scoresBefore=hash('sha256',wp_json_encode($wpdb->get_results('SELECT * FROM '.Installer::scores_table().' ORDER BY id',ARRAY_A)));
        $statesBefore=array();foreach($checkActors as $actor){$state=$wpdb->get_row($wpdb->prepare('SELECT issued_day,issued_today,next_allowed_at_ms FROM '.Installer::check_states_table().' WHERE user_id=%d',$actor['userId']),ARRAY_A);$day=(new DateTimeImmutable('now',wp_timezone()))->format('Y-m-d');$statesBefore[$actor['userId']]=($state['issued_day']??'')===$day?(int)$state['issued_today']:0;mysql_accept_check(!$state||(int)$state['next_allowed_at_ms']<=Clock::now_ms(),'Normal native cooldown has elapsed before a new check burst');}
        $before=mysql_accept_metrics();$jobs=array();$nextBodies=array();
        foreach($checkActors as $actor){$body=array('user_id'=>$actor['userId'],'problem_id'=>$fixture['problem']['id'],'context_type'=>'group','context_id'=>$checkFixture['group']['id'],'draft'=>array('language_id'=>6,'source'=>"if number < 4:\n    print(number)\n"));$nextBodies[$actor['userId']]=$body;$copies=$phase==='check-probe'?1:2;for($copy=0;$copy<$copies;++$copy){$job=array('operation'=>'check_next','actor'=>$actor['userId'],'route'=>'/checks/next','body'=>$body);if($phase==='check-probe'){$job['insertBarrier']=2;$job['insertTable']='checks';}$jobs[]=$job;}}
        $issue=mysql_accept_pool($jobs,$checkDir.'/issuance',$configPath,$phase==='check-probe'?2:12);$checkIds=array();$choices=array();
        foreach($checkActors as $actor){$rows=$wpdb->get_results($wpdb->prepare('SELECT id,status FROM '.Installer::checks_table().' WHERE user_id=%d AND issued_at_ms>=%d ORDER BY id',$actor['userId'],$checkFrom),ARRAY_A);mysql_accept_check(count($rows)===1&&$rows[0]['status']==='pending','Concurrent native next requests create exactly one pending question per learner');$checkIds[$actor['userId']]=(int)$rows[0]['id'];$state=$wpdb->get_row($wpdb->prepare('SELECT issued_today FROM '.Installer::check_states_table().' WHERE user_id=%d',$actor['userId']),ARRAY_A);mysql_accept_check((int)$state['issued_today']===$statesBefore[$actor['userId']]+1,'Concurrent native next requests consume exactly one daily quota unit');}
        foreach($issue['workers'] as $index=>$worker){$actor=$jobs[$index]['actor'];$success=array_values(array_filter($worker['responses'],static fn(array $r):bool=>$r['status']===200));$last=end($success);mysql_accept_check(($last['resultStatus']??null)==='ready'&&($last['publicCheck']['id']??null)===$checkIds[$actor],'Both native duplicate issuance callers receive the same committed public question');mysql_accept_check(($last['privateAnswerAbsent']??false)&&($last['affectsFocusScore']??null)===false,'Actual native check responses expose no answer key and keep focus scoring separate');$choices[$actor]=$last['publicCheck']['choiceIds'];}
        $responseEvidence=null;
        if($phase==='checks'){
            $jobs=array();foreach(array_slice($checkActors,0,50) as $index=>$actor){$ids=$choices[$actor['userId']];$answer=$ids[$index%2===0?0:count($ids)-1];$body=array('user_id'=>$actor['userId'],'response_uid'=>'r-'.substr($checkRunId,-6).'-'.$actor['userId'],'answer_id'=>$answer);for($copy=0;$copy<2;++$copy){$jobs[]=array('operation'=>'check_response','actor'=>$actor['userId'],'route'=>'/checks/'.$checkIds[$actor['userId']].'/response','body'=>$body);}}
            $responseEvidence=mysql_accept_pool($jobs,$checkDir.'/responses',$configPath,12);
            $stableOutcomes=array();foreach($responseEvidence['workers'] as $workerIndex=>$worker){foreach($worker['responses'] as $response){if($response['status']!==200){continue;}$stableOutcomes[$jobs[$workerIndex]['actor']][$response['resultStatus']]=true;mysql_accept_check(in_array($response['resultStatus'],array('correct','incorrect'),true)&&$response['affectsFocusScore']===false&&$response['noMisfocusClaim']&&$response['privateAnswerAbsent'],'Native duplicate response returns its stable learning outcome without a focus judgment or answer disclosure');}}
            foreach($stableOutcomes as $actor=>$outcomes){$storedStatus=$wpdb->get_var($wpdb->prepare('SELECT status FROM '.Installer::checks_table().' WHERE id=%d AND user_id=%d',$checkIds[$actor],$actor));mysql_accept_check(count($outcomes)===1&&isset($outcomes[$storedStatus]),'Both duplicate answer callers agree with the one committed response outcome');}
            $wrong=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::checks_table().' WHERE id IN('.implode(',',array_values($checkIds)).") AND status='incorrect'");mysql_accept_check($wrong>0,'The real response burst includes incorrect answers while focus scores remain separate');
        }
        mysql_accept_user($fixture['teacher']['userId']);mysql_accept_ok(mysql_accept_rest('PATCH','/activities/'.$checkActivity['id'],array('status'=>'closed')),'The native teacher closes every check-burst activity before other load scenarios');
        $pending=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::checks_table().' WHERE id IN('.implode(',',array_values($checkIds)).") AND status='pending'");mysql_accept_check($pending===0,'Activity close immediately retires every unanswered native check');
        $checkStatusCounts=array();foreach($wpdb->get_results('SELECT status,COUNT(*) AS n FROM '.Installer::checks_table().' WHERE id IN('.implode(',',array_values($checkIds)).') GROUP BY status',ARRAY_A) as $statusRow){$checkStatusCounts[$statusRow['status']]=(int)$statusRow['n'];}
        mysql_accept_check(($checkStatusCounts['invalidated']??0)===($phase==='checks'?10:2),'Every deliberately unanswered question is canceled rather than classified as unanswered or incorrect');
        $afterJobs=array();foreach($checkActors as $actor){$afterJobs[]=array('operation'=>'check_after_end','actor'=>$actor['userId'],'route'=>'/checks/next','body'=>$nextBodies[$actor['userId']]);}
        $afterEvidence=mysql_accept_pool($afterJobs,$checkDir.'/after-end',$configPath,$phase==='check-probe'?2:12);foreach($afterEvidence['workers'] as $worker){foreach($worker['responses'] as $response){if($response['status']===200){mysql_accept_check($response['resultStatus']==='inactive','Ended native check bursts issue no further question');}}}
        foreach($checkActors as $actor){$count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Installer::checks_table().' WHERE user_id=%d AND issued_at_ms>=%d',$actor['userId'],$checkFrom));$quota=(int)$wpdb->get_var($wpdb->prepare('SELECT issued_today FROM '.Installer::check_states_table().' WHERE user_id=%d',$actor['userId']));mysql_accept_check($count===1&&$quota===$statesBefore[$actor['userId']]+1,'Post-end next requests leave both question count and daily quota unchanged');}
        $rawAfter=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::events_table());$scoresAfter=hash('sha256',wp_json_encode($wpdb->get_results('SELECT * FROM '.Installer::scores_table().' ORDER BY id',ARRAY_A)));mysql_accept_check($rawAfter===$rawBefore&&$scoresAfter===$scoresBefore,'Issuance, incorrect answers and duplicate responses create no tracking event or score change');
        $after=mysql_accept_metrics();$deadlocks=$after['Innodb_deadlocks']-$before['Innodb_deadlocks'];$serialized=array();foreach($issue['workers'] as $worker){$serialized=array_merge($serialized,$worker['barrierObservations']??array());}
        $report=array('passed'=>$deadlocks===0&&$serialized===array(),'phase'=>$phase,'capturedAt'=>gmdate('c'),'actorCount'=>count($checkActors),'checkStatusCounts'=>$checkStatusCounts,'deadlockDelta'=>$deadlocks,'unrelatedCheckSerialization'=>$serialized,'databaseSettings'=>mysql_accept_database_settings(),'before'=>$before,'after'=>$after,'activityClosed'=>true,'issueEvidence'=>$issue,'responseEvidence'=>$responseEvidence,'afterEndEvidence'=>$afterEvidence,
            'installedPathHashes'=>array('Check_Repository.php'=>hash_file('sha256',$wpPath.'/wp-content/plugins/focus-tracker/src/Database/Check_Repository.php'),'Check_Service.php'=>hash_file('sha256',$wpPath.'/wp-content/plugins/focus-tracker/src/Checks/Check_Service.php'),'Activities_Controller.php'=>hash_file('sha256',$wpPath.'/wp-content/plugins/focus-tracker/src/Rest/Controllers/Activities_Controller.php')),
            'scope'=>'Actual native verified-problem next/response APIs, unchanged cooldown and daily quota. Only public choice IDs are selected; private candidate keys are neither read nor exported. The short probe synchronizes actual check INSERT through the standard query filter, without replacing SQL or responses.');
        mysql_accept_save($checkDir.'-report.json',$report);mysql_accept_check($deadlocks===0,'Concurrent native check issuance and responses introduce no InnoDB deadlock');mysql_accept_check($serialized===array(),'Independent check actors are not serialized by a broad expired-check update');
    } elseif ($phase === 'run') {
        // The driver and all children boot the installed plugin, never a test bootstrap.
        $fixture = mysql_accept_json($fixturePath);
        $rounds = (int) ($argv[3] ?? 3); $concurrency = (int) ($argv[4] ?? 12);
        mysql_accept_check($rounds >= 2 && $rounds <= 5 && $concurrency >= 4 && $concurrency <= 24, 'The repeated native load has bounded rounds and real process concurrency');
        $runId = 'run-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)); $runDir = $output . '/' . $runId; mkdir($runDir, 0700, true);
        $fixture=mysql_accept_rotate_private_actors($fixture,$runId);mysql_accept_save($fixturePath,$fixture);
        $baselineUser=$fixture['students'][count($fixture['students'])-1]['userId'];mysql_accept_user($baselineUser);$anchorDate=(new DateTimeImmutable('now',wp_timezone()))->format('Y-m-d');
        $baselineMonth=mysql_accept_ok(mysql_accept_rest('GET','/me/statistics',array('period'=>'month','date'=>$anchorDate)),'A native repeat records the existing monthly total before adding observations');$baselineActiveMs=(int)round($baselineMonth['totals']['activeSeconds']*1000);
        $occupied=$wpdb->get_results($wpdb->prepare('SELECT started_at_ms,COALESCE(ended_at_ms,last_heartbeat_at_ms) AS ended_at_ms FROM '.Installer::participations_table().' WHERE user_id=%d',$baselineUser),ARRAY_A);
        $base=intdiv(Clock::now_ms(),1000)*1000;$available=false;
        for($choice=0;$choice<100;++$choice){$overlap=false;foreach($occupied as $old){for($round=0;$round<$rounds;++$round){$from=$base-($round+2)*120000;if((int)$old['started_at_ms']<$from+30000&&(int)$old['ended_at_ms']>$from){$overlap=true;break 2;}}}if(!$overlap){$available=true;break;}$base-=10*MINUTE_IN_SECONDS*1000;}
        mysql_accept_check($available&&$base-($rounds+1)*120000>Clock::now_ms()-22*HOUR_IN_SECONDS*1000,'New repeat windows avoid every earlier peer source without deleting existing learning history');
        $before = mysql_accept_metrics();mysql_accept_check(($before['deadlockMetricEnabled']??false)===true,'The native deadlock counter is available and enabled'); $evidence = array(); $mainBodies = array(); $mainActivities = array();
        for ($round = 0; $round < $rounds; ++$round) {
            $from = $base - ($round + 2) * 120000; $activity = mysql_accept_activity($fixture, 'MySQL8 round ' . ($round + 1), $from, $from + 30000); $mainActivities[] = $activity['id'];
            $foreign = mysql_accept_activity($fixture, 'MySQL8 isolated group B ' . ($round + 1), $from, $from + 30000, 1);
            $jobs = array(); $dense = array();
            foreach ($fixture['students'] as $index => $student) {
                foreach (array(false,true) as $tab => $material) {
                    $body = mysql_accept_packet($fixture, $student['userId'], $fixture['runId'] . '-' . substr($runId,-6) . '-' . $round . '-' . $index . '-' . $tab, $from, 30, $material); $mainBodies[] = $body;
                    for ($copy = 0; $copy < 2; ++$copy) { $jobs[] = array('operation' => 'duplicate_ingest', 'actor' => $student['userId'], 'body' => $body); }
                    $extra = $body; $extra['events'] = array(); for ($seq = 5; $seq <= 24; ++$seq) { $extra['events'][] = array('seq' => $seq, 'type' => 'activity', 'occurred_at' => $from + ($seq - 3) * 1000, 'meta' => array('visible' => true, 'focused' => true)); }
                    for ($copy = 0; $copy < 2; ++$copy) { $dense[] = array('operation' => 'populated_ingest', 'actor' => $student['userId'], 'body' => $extra); }
                }
                if ($index % 12 === 0) {
                    $jobs[] = array('operation' => 'initial_teacher_scores', 'actor' => $fixture['teacher']['userId'], 'method' => 'GET', 'route' => '/activities/' . $activity['id'] . '/scores', 'body' => array('refresh' => '1'));
                    $dense[] = array('operation' => 'populated_teacher_scores', 'actor' => $fixture['teacher']['userId'], 'method' => 'GET', 'route' => '/activities/' . $activity['id'] . '/scores', 'body' => array('refresh' => '1'));
                    $dense[] = array('operation' => 'populated_month_statistics', 'actor' => $student['userId'], 'method' => 'GET', 'route' => '/me/statistics', 'body' => array('period' => 'month'));
                }
            }
            $evidence[] = mysql_accept_pool($jobs, $runDir . '/round-' . $round . '-initial', $configPath, $concurrency);
            $evidence[] = mysql_accept_pool($dense, $runDir . '/round-' . $round . '-populated', $configPath, $concurrency);
            mysql_accept_user($fixture['teacher']['userId']); mysql_accept_ok(mysql_accept_rest('PATCH', '/activities/' . $foreign['id'], array('starts_at' => gmdate('c', intdiv($from - 5000,1000)), 'problem_ids' => array($fixture['problem']['id']))), 'A real teacher time/problem edit reconciles signed data safely');
            $foreignRows = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::activity_links_table() . ' WHERE activity_id=%d', $foreign['id']));
            mysql_accept_check($foreignRows === 0, 'Signed group A observations never retro-link to a different group after an actual edit');
        }
        foreach ($mainBodies as $body) {
            $rows = mysql_accept_source($body); mysql_accept_check(count($rows) === 24 && count(array_unique(array_column($rows, 'sequence_number'))) === 24, 'Concurrent duplicate packets preserve every expected real source event exactly once');
            $map = array(); foreach ($rows as $row) { $map[(int) $row['sequence_number']] = $row; }
            foreach ($body['events'] as $event) { mysql_accept_check(isset($map[$event['seq']]) && (int) $map[$event['seq']]['occurred_at_ms'] === $event['occurred_at'] && $map[$event['seq']]['event_type'] === $event['type'], 'Committed source timestamps and types match acknowledged original events'); }
        }
        // Changing an existing run must not let a duplicate sequence enter a
        // different physical segment. Both sides use real REST transactions.
        $raceBodies = array();
        for ($iteration = 0; $iteration < 3; ++$iteration) {
            $from = $base - (12 + $iteration) * 120000;
            $activity = mysql_accept_activity($fixture, 'MySQL8 schedule edit race ' . $iteration, $from, $from + 30000);
            $body = mysql_accept_packet($fixture, $fixture['students'][0]['userId'], $fixture['runId'] . '-' . substr($runId,-6) . '-edit-' . $iteration, $from);
            mysql_accept_user($body['user_id']); mysql_accept_ok(mysql_accept_rest('POST', '/events', $body), 'The edit race begins with committed source observations');
            $jobs = array(array('operation' => 'teacher_window_edit', 'actor' => $fixture['teacher']['userId'], 'method' => 'PATCH', 'route' => '/activities/' . $activity['id'], 'body' => array('starts_at' => gmdate('c', intdiv($from - 5000,1000)))));
            for ($copy = 0; $copy < 8; ++$copy) { $jobs[] = array('operation' => 'edit_race_duplicate', 'actor' => $body['user_id'], 'body' => $body); }
            $evidence[] = mysql_accept_pool($jobs, $runDir . '/window-edit-' . $iteration, $configPath, $concurrency);
            mysql_accept_user($body['user_id']); mysql_accept_ok(mysql_accept_rest('POST', '/events', $body), 'A post-edit source retry is acknowledged');
            $rows = mysql_accept_source($body);
            mysql_accept_check(count($rows) === 4 && count(array_unique(array_column($rows, 'sequence_number'))) === 4, 'A changed schedule run never duplicates the stable physical source sequences');
            $raceBodies[] = $body;
        }
        $liveFrom = intdiv(Clock::now_ms(),1000) * 1000 - 30000;
        $live = mysql_accept_activity($fixture, 'MySQL8 early-end race', $liveFrom, $liveFrom + 120000);
        $body = mysql_accept_packet($fixture, $fixture['students'][1]['userId'], $fixture['runId'] . '-' . substr($runId,-6) . '-end', $liveFrom + 1000, 20, true);
        $jobs = array(array('operation' => 'teacher_early_end', 'actor' => $fixture['teacher']['userId'], 'method' => 'PATCH', 'route' => '/activities/' . $live['id'], 'body' => array('status' => 'closed')));
        for ($copy = 0; $copy < 8; ++$copy) { $jobs[] = array('operation' => 'end_race_delayed_valid', 'actor' => $body['user_id'], 'body' => $body); }
        $evidence[] = mysql_accept_pool($jobs, $runDir . '/early-end', $configPath, $concurrency);
        mysql_accept_check(count(mysql_accept_source($body)) === 4, 'Early end keeps all four already-observed valid events despite concurrent delayed retries');
        mysql_accept_user($body['user_id']);
        $late = $body; $late['events'] = array(array('seq' => 99,'type' => 'activity','occurred_at' => Clock::now_ms()));
        $lateResponse = mysql_accept_ok(mysql_accept_rest('POST','/events',$late), 'An event after teacher end receives an explicit policy ACK');
        mysql_accept_check($lateResponse['stored'] === 0 && $lateResponse['policyDropped'] === 1, 'No new learning event is persisted after the committed teacher end');
        $forged = $body; $forged['context_id'] = $fixture['groups'][1]['contextId'];
        mysql_accept_check(mysql_accept_rest('POST','/events',$forged)->get_status() === 403, 'A valid group A signature cannot be reused as a group B page');

        // Seed historical source through real repositories so native retention
        // has work. The upload API deliberately rejects source older than24h.
        $oldFrom = $base - 61 * DAY_IN_SECONDS * 1000;
        $oldActivity = mysql_accept_activity($fixture, 'MySQL8 retention provenance', $oldFrom, $oldFrom + 30000);
        $oldPage = Page_Context::issue($fixture['retentionStudent']['userId'], '/oj-addons/learning-materials/' . $fixture['material']['id'], array('group_id' => $fixture['group']['id']), $oldFrom);
        $oldP = Participation_Repository::find_or_create(array('activity_id' => null, 'user_id' => $fixture['retentionStudent']['userId'], 'problem_id' => null,
            'session_uid' => 'g6-' . substr(hash('sha256',$runId . '-old'),0,32) . '-old', 'started_at_ms' => $oldFrom) + Page_Context::columns($oldPage));
        mysql_accept_check(is_array($oldP), 'The real repository provides bounded historical source for retention');
        $oldEvents = array(array('seq'=>1,'type'=>'session_start','occurred_at'=>$oldFrom,'meta'=>array('visible'=>true,'focused'=>true)), array('seq'=>2,'type'=>'heartbeat','occurred_at'=>$oldFrom+15000,'meta'=>array('visible'=>true,'focused'=>true)),array('seq'=>3,'type'=>'session_end','occurred_at'=>$oldFrom+30000));
        Event_Repository::insert_batch($oldP['id'],$oldEvents); Participation_Repository::touch_heartbeat($oldP['id'],$oldFrom+30000); Participation_Repository::close($oldP['id'],$oldFrom+30000,'pagehide'); Participation_Repository::record_event_revision($oldP['id'],3); Participation_Repository::link_activity($oldP['id'],$oldActivity['id']);
        // Forty live sessions require multiple actual privacy batches; peer
        // ingestion and report reads contend with its per-user/global guards.
        $privacyFrom = $base - 20 * 120000;
        mysql_accept_activity($fixture,'MySQL8 privacy race source',$privacyFrom,$privacyFrom+30000);
        $privacyBody = null;
        for ($index=0;$index<40;++$index) { $packet=mysql_accept_packet($fixture,$fixture['privacyStudent']['userId'],$fixture['runId'].'-'.substr($runId,-6).'-privacy-'.$index,$privacyFrom,30,true); mysql_accept_user($packet['user_id']); mysql_accept_ok(mysql_accept_rest('POST','/events',$packet),'The privacy race has actual multi-batch native source'); $privacyBody=$packet; }
        $peerBefore = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Installer::events_table().' e JOIN '.Installer::participations_table().' p ON p.id=e.participation_id WHERE p.user_id=%d',$fixture['students'][count($fixture['students'])-1]['userId']));
        $jobs=array(array('operation'=>'erase','actor'=>$fixture['teacher']['userId'],'target'=>$fixture['privacyStudent']['userId']),array('operation'=>'cleanup','actor'=>$fixture['teacher']['userId']),array('operation'=>'cleanup','actor'=>$fixture['teacher']['userId']));
        for($copy=0;$copy<4;++$copy){ $jobs[]=array('operation'=>'privacy_race_retry','actor'=>$privacyBody['user_id'],'body'=>$privacyBody,'repeat'=>5); $jobs[]=array('operation'=>'privacy_race_statistics','actor'=>$privacyBody['user_id'],'method'=>'GET','route'=>'/me/statistics','body'=>array('period'=>'month'),'repeat'=>3); }
        $peerBody=$mainBodies[count($mainBodies)-1]; $jobs[]=array('operation'=>'unrelated_peer_ingest','actor'=>$peerBody['user_id'],'body'=>$peerBody,'repeat'=>5);
        $evidence[]=mysql_accept_pool($jobs,$runDir.'/privacy-retention',$configPath,$concurrency);
        // Finish a genuine bounded cleanup if a contender deferred to the
        // global privacy lease; this is recorded separately from the race.
        Retention_Cleanup::run();
        $victimRows=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Installer::participations_table().' WHERE user_id=%d',$privacyBody['user_id']));
        mysql_accept_check($victimRows===0 && Privacy_Guard::cutoff($privacyBody['user_id'])>0,'Privacy erasure and concurrent retries never resurrect deleted source');
        $peerAfter=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Installer::events_table().' e JOIN '.Installer::participations_table().' p ON p.id=e.participation_id WHERE p.user_id=%d',$peerBody['user_id']));
        mysql_accept_check($peerAfter===$peerBefore,'Concurrent privacy and retention preserve unrelated learner observations');
        $oldAfter=Participation_Repository::find($oldP['id']); $oldRaw=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Installer::events_table().' WHERE participation_id=%d',$oldP['id']));
        mysql_accept_check($oldRaw===0 && $oldAfter['raw_events_expired']===true,'Native retention archives and purges only eligible historical raw events');
        $oldDaily=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Installer::statistics_table().' WHERE user_id=%d AND archived=1',$fixture['retentionStudent']['userId']));
        mysql_accept_check($oldDaily>0,'Retention preserves actual native archived daily provenance before raw purge');
        $jobs=array(array('operation'=>'erase_account','actor'=>$fixture['teacher']['userId'],'target'=>$privacyBody['user_id']));
        for($copy=0;$copy<8;++$copy){$jobs[]=array('operation'=>'peer_ingest_during_account_erasure','actor'=>$peerBody['user_id'],'body'=>$peerBody);}
        $evidence[]=mysql_accept_pool($jobs,$runDir.'/account-erasure',$configPath,$concurrency);
        mysql_accept_check(Privacy_Guard::state($privacyBody['user_id'])==='deleted','Account erasure leaves a durable native tombstone without affecting peers');

        $student=$fixture['students'][count($fixture['students'])-1]; mysql_accept_user($student['userId']);
        $month=mysql_accept_ok(mysql_accept_rest('GET','/me/statistics',array('period'=>'month','date'=>$anchorDate)),'Native month totals remain readable after all writer races');
        $periodStart=(new DateTimeImmutable($month['startDate'].' 00:00:00',wp_timezone()))->getTimestamp()*1000; $periodEnd=(new DateTimeImmutable($month['endDate'].' 00:00:00',wp_timezone()))->modify('+1 day')->getTimestamp()*1000;
        $expectedMs=0;for($round=0;$round<$rounds;++$round){$start=$base-($round+2)*120000;$expectedMs+=max(0,min($start+30000,$periodEnd)-max($start,$periodStart));}
        mysql_accept_check((int)round($month['totals']['activeSeconds']*1000)===$baselineActiveMs+$expectedMs,'Two concurrent tabs add exactly the independently calculated union to preserved monthly history');
        mysql_accept_check((int)round(array_sum(array_column($month['pageCategories'],'activeSeconds'))*1000)===$baselineActiveMs+$expectedMs,'Native category times partition the same preserved monthly active-time union without double counting');
        mysql_accept_user($fixture['teacher']['userId']); $foreignMonth=mysql_accept_ok(mysql_accept_rest('GET','/statistics',array('period'=>'month','user_id'=>$student['userId'],'context_type'=>'group','context_id'=>$fixture['groups'][1]['contextId'])),'The teacher can inspect the genuinely separate authorized group scope');
        mysql_accept_check((float)$foreignMonth['totals']['activeSeconds']===0.0,'Matching times and dual membership do not copy group A evidence into group B statistics');
        $orphans=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::events_table().' e LEFT JOIN '.Installer::participations_table().' p ON p.id=e.participation_id WHERE p.id IS NULL');
        $linkOrphans=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::activity_links_table().' l LEFT JOIN '.Installer::participations_table().' p ON p.id=l.participation_id LEFT JOIN '.Installer::activities_table().' a ON a.id=l.activity_id WHERE p.id IS NULL OR a.id IS NULL');
        mysql_accept_check($orphans===0 && $linkOrphans===0,'Concurrent native writes and erasures leave no event or activity-link orphan');
        $revisionGaps=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Installer::participations_table().' p WHERE p.raw_events_expired=0 AND p.event_revision<>(SELECT COUNT(*) FROM '.Installer::events_table().' e WHERE e.participation_id=p.id)');
        mysql_accept_check($revisionGaps===0,'Every remaining native participation revision equals its committed event count');
        $after = mysql_accept_metrics();
        mysql_accept_check(($after['Innodb_deadlocks'] ?? 0) === ($before['Innodb_deadlocks'] ?? 0), 'The native concurrency run introduces no InnoDB deadlocks');
        $report = array('passed' => true, 'assertionCount' => count($mysqlChecks), 'checks' => $mysqlChecks, 'capturedAt' => gmdate('c'), 'students' => count($fixture['students']), 'tabsPerStudent' => 2, 'rounds' => $rounds, 'maxConcurrentPhpProcesses' => $concurrency,
            'environment' => array('wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'database' => $version, 'versionComment' => $versionComment, 'platform' => OJ_PLATFORM_VERSION, 'focusTracker' => FOCUS_TRACKER_VERSION),
            'databaseSettings' => mysql_accept_database_settings(), 'databaseMetrics' => array('before' => $before, 'after' => $after), 'workerEvidence' => $evidence, 'activityIds' => $mainActivities,
            'monthlyPreservation'=>array('anchorDate'=>$anchorDate,'existingActiveMs'=>$baselineActiveMs,'addedIndependentUnionMs'=>$expectedMs,'finalActiveMs'=>(int)round($month['totals']['activeSeconds']*1000),'newWindowAnchorMs'=>$base),
            'scope' => 'Independent native CLI WordPress processes and real internal REST requests. Browser cookies, HTTP worker saturation, and actual visibility timers are separate load tests.');
        mysql_accept_save($output . '/mysql-concurrency-report.json', $report);
    } else {
        $fixture=mysql_accept_json($fixturePath); $browserPath=realpath($argv[3]??'');
        mysql_accept_check($browserPath!==false && str_ends_with($browserPath,'.json') && (str_starts_with(mysql_accept_path($browserPath),mysql_accept_path($output).'/') || str_starts_with(mysql_accept_path($browserPath),mysql_accept_path($workspace.'/_notes/verification').'/')),'The browser evidence stays within the dedicated private artifacts or verification tree');
        $browser=mysql_accept_json($browserPath); $expected=$browser['expectedEvents']??array();
        mysql_accept_check(is_array($expected) && count($expected)>0,'The actual HTTP browser report provides expected event identities');
        $sessions=array(); $seenExpected=array();
        foreach($expected as $event){
            foreach(array('userId','sessionUid','sessionStartedAt','seq','occurredAt','type','pageKey','contextType','contextId') as $key){mysql_accept_check(array_key_exists($key,$event),'Browser expected events contain every source identity field');}
            $unique=$event['userId'].'|'.$event['sessionUid'].'|'.$event['seq'];
            if(isset($seenExpected[$unique])){continue;} $seenExpected[$unique]=true;
            $sessionKey=$event['userId'].'|'.$event['sessionUid']; if(!isset($sessions[$sessionKey])){$sessions[$sessionKey]=array('metadata'=>$event,'events'=>array());}$sessions[$sessionKey]['events'][]=$event;
        }
        $ackKeys=array();foreach($browser['stages']??array() as $stage){foreach($stage['acknowledgedEventKeys']??array() as $key){$ackKeys[$key]=true;}}
        $missing=0;$missingAck=0;$missingUnacked=0;$duplicates=0;$different=0;$stateDifferences=0;$policyOutside=0;$storedOutsideFinalWindow=0;$additional=0;$crossScope=0;$sourceCount=0;$matched=0;
        foreach($sessions as $session){
            $meta=$session['metadata']; $user=(int)$meta['userId']; $type=$meta['contextType'];$context=(string)$meta['contextId'];if($type!=='general'&&!str_starts_with($context,$type.':')){$context=$type.':'.$context;}
            $pageCategory=$meta['pageCategory']??null;$pageSubtype=$meta['pageSubtype']??null;
            if(!is_string($pageCategory)){$stored=$wpdb->get_row($wpdb->prepare('SELECT page_category,page_subtype FROM '.Installer::participations_table().' WHERE user_id=%d AND page_key=%s AND page_context_version=1 LIMIT 1',$user,$meta['pageKey']),ARRAY_A);$pageCategory=$stored['page_category']??null;$pageSubtype=$stored['page_subtype']??null;}
            mysql_accept_check(is_string($pageCategory),'The browser source has explicit page metadata or matching committed server metadata');
            $columns=array('page_category'=>$pageCategory,'page_subtype'=>$pageSubtype,'page_key'=>$meta['pageKey'],'page_context_version'=>1);
            $identity=array($meta['sessionUid'],(int)$meta['sessionStartedAt'],$meta['problemId']??null,$type,$context);$cutoff=Privacy_Guard::cutoff($user);if($cutoff>0){$identity[]=$cutoff;}$identity[]=array('page-v1',$user,$columns);
            $prefix='g6-'.substr(hash('sha256',(string)wp_json_encode($identity)),0,32).'-';
            $rows=$wpdb->get_results($wpdb->prepare('SELECT e.sequence_number,e.event_type,e.occurred_at_ms,e.meta,p.id FROM '.Installer::participations_table().' p JOIN '.Installer::events_table().' e ON e.participation_id=p.id WHERE p.session_uid >= %s AND p.session_uid < %s AND e.sequence_number>0 AND e.sequence_number<2147483648',$prefix,$prefix.'g'),ARRAY_A);mysql_accept_check(is_array($rows)&&$wpdb->last_error==='','Native audit reads each exact hashed browser source');
            ++$sourceCount;$bySeq=array();foreach($rows as $row){$bySeq[(int)$row['sequence_number']][]=$row;}
            $contextBare=str_starts_with($context,$type.':')?substr($context,strlen($type)+1):$context;
            $activities=$wpdb->get_results($wpdb->prepare('SELECT id,problem_ids,starts_at,ends_at,status FROM '.Installer::activities_table().' WHERE context_type=%s AND context_id IN (%s,%s)',$type,$contextBare,$type.':'.$contextBare),ARRAY_A);
            $expectedSeqs=array();
            foreach($session['events'] as $event){
                $seq=(int)$event['seq'];$expectedSeqs[$seq]=true;$at=(int)$event['occurredAt'];$eligible=false;
                foreach($activities as $activity){if($activity['status']==='deleted'){continue;}$problems=$activity['problem_ids']===null?null:json_decode($activity['problem_ids'],true);if(($meta['problemId']??null)!==null&&$problems!==null&&!in_array((int)$meta['problemId'],$problems,true)){continue;}$start=Clock::mysql_to_ms($activity['starts_at']);$end=Clock::mysql_to_ms($activity['ends_at']);if($at>=$start&&($at<$end||($event['type']==='session_end'&&$at===$end))){$eligible=true;break;}}
                $found=$bySeq[$seq]??array();
                if(!$eligible){++$policyOutside;if(count($found)>0){++$storedOutsideFinalWindow;}}
                elseif(count($found)===0){++$missing;$ackKey=$user.'|'.$meta['sessionUid'].'|'.$seq;if(isset($ackKeys[$ackKey])){++$missingAck;}else{++$missingUnacked;}}
                if(count($found)>1){$duplicates+=count($found)-1;}
                foreach($found as $row){if((int)$row['occurred_at_ms']!==$at||$row['event_type']!==$event['type']){++$different;}else{++$matched;}$storedMeta=json_decode((string)($row['meta']??''),true);foreach(array('visible','focused') as $stateKey){if(array_key_exists($stateKey,$event)&&is_bool($event[$stateKey])&&(!is_array($storedMeta)||!array_key_exists($stateKey,$storedMeta)||$storedMeta[$stateKey]!==$event[$stateKey])){++$stateDifferences;}}}
            }
            foreach($rows as $row){if(!isset($expectedSeqs[(int)$row['sequence_number']])){++$additional;}}
            $pids=array_values(array_unique(array_map('intval',array_column($rows,'id'))));
            if($pids!==array()){$placeholders=implode(',',array_fill(0,count($pids),'%d'));$links=$wpdb->get_results($wpdb->prepare('SELECT DISTINCT a.context_type,a.context_id FROM '.Installer::activity_links_table().' l JOIN '.Installer::activities_table()." a ON a.id=l.activity_id WHERE l.participation_id IN ({$placeholders})",$pids),ARRAY_A);foreach($links as $link){if($link['context_type']!==$type||!\FocusTracker\Tracking\Activity_Window_Gate::same_context($type,$link['context_id'],$context)){++$crossScope;}}}
        }
        // Preserve negative capacity-run evidence as well as successful runs.
        // A failed ACKed/unACKed comparison must still report its exact counts;
        // defer throwing until after the independent report is written.
        $auditChecks=array(
            'Every expected event inside the final authorized window exists in committed native MySQL source'=>$missing===0,
            'Browser retransmission produces no duplicate source sequence or changed event payload'=>$duplicates===0&&$different===0,
            'Explicit browser or protocol visibility and focus booleans survive storage without alteration'=>$stateDifferences===0,
            'Every explicitly scoped browser source is linked only to its signed group or class'=>$crossScope===0,
        );
        foreach($auditChecks as $label=>$passed){$mysqlChecks[$label]=$passed;}
        $statistics=array();foreach(array_slice($fixture['students'],0,3) as $student){mysql_accept_user($student['userId']);foreach(array('week','month') as $period){$report=mysql_accept_ok(mysql_accept_rest('GET','/me/statistics',array('period'=>$period)),'Real native browser learners retain readable weekly and monthly reports');$sum=(int)round(array_sum(array_column($report['pageCategories'],'activeSeconds'))*1000);$total=(int)round($report['totals']['activeSeconds']*1000);mysql_accept_check($sum===$total,'Browser learner category seconds exactly partition weekly and monthly active totals');$statistics[]=array('period'=>$period,'activeMs'=>$total,'categoryActiveMs'=>$sum);}}
        $auditPassed=!in_array(false,$auditChecks,true);
        $report=array('passed'=>$auditPassed,'assertionCount'=>count($mysqlChecks),'checks'=>$mysqlChecks,'capturedAt'=>gmdate('c'),'clientMode'=>$browser['mode']??'unspecified','syntheticProtocolState'=>(bool)($browser['syntheticProtocolState']??false),'databaseVersion'=>$version,'databaseSettings'=>mysql_accept_database_settings(),
            'expectedUniqueEvents'=>count($seenExpected),'acknowledgedUniqueEvents'=>count($ackKeys),'exactBrowserSources'=>$sourceCount,'matchedPersistedEvents'=>$matched,'missingEligibleEvents'=>$missing,'missingAcknowledgedEvents'=>$missingAck,'missingUnacknowledgedOrPendingEvents'=>$missingUnacked,'duplicateSequences'=>$duplicates,'changedPayloads'=>$different,'changedVisibilityOrFocusValues'=>$stateDifferences,'crossScopeLinks'=>$crossScope,
            'outsideFinalScheduleExpected'=>$policyOutside,'retainedOutsideEditedFinalSchedule'=>$storedOutsideFinalWindow,'additionalPersistedEventsNotObservedInFetch'=>$additional,'statisticsSamples'=>$statistics,
            'scope'=>'Independent committed-source SQL checks exact native g6 identities from the reported outgoing HTTP mode. Actual Chrome and protocol-load state are explicitly distinguished; synthetic protocol state proves no browser visibility behavior. Outside-final-window delivery is separated from loss; previously accepted rows can remain after a later shortening. Extra source events can come from unreadable beacon bodies and are reported rather than silently inferred. Statistics calls use actual native REST and privacy guards.');
        mysql_accept_save($output.'/mysql-browser-sql-audit.json',$report);
        if(!$auditPassed){throw new RuntimeException('The committed source differs from the reported eligible event ledger.');}
    }
    $mysqlFinished = true; echo json_encode(array('phase' => $phase, 'passed' => true, 'assertions' => count($mysqlChecks), 'databaseVersion' => $version), JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    foreach($GLOBALS['mysqlChildProcesses']??array() as $child){if(is_resource($child)){$state=proc_get_status($child);if($state['running']){proc_terminate($child);}proc_close($child);}}
    if(isset($checkActivity,$fixture)&&is_array($checkActivity)){try{mysql_accept_user($fixture['teacher']['userId']);$activity=Activity_Repository::find($checkActivity['id']);if($activity!==null&&$activity['status']!=='closed'){mysql_accept_rest('PATCH','/activities/'.$checkActivity['id'],array('status'=>'closed'));}}catch(Throwable $ignored){}}
    $failed = array_keys(array_filter($mysqlChecks, static fn (bool $passed): bool => ! $passed));
    $failure=array('passed'=>false,'phase'=>$phase??null,'errorClass'=>get_class($error),'sourceFile'=>basename($error->getFile()),'sourceLine'=>$error->getLine(),'failedAssertions'=>$failed);
    if(($phase??'')==='worker'&&isset($jobPath)&&is_string($jobPath)){ $failure['operation']=$job['operation']??null; $failure['responses']=$GLOBALS['mysqlWorkerTrace']??array(); mysql_accept_save($jobPath.'.result',$failure); }
    elseif(isset($output)&&is_string($output)&&is_dir($output)){ $failure['checks']=$mysqlChecks; $failure['workerEvidence']=$evidence??array(); $failure['databaseMetrics']=array('before'=>$before??null,'after'=>isset($wpdb)?mysql_accept_metrics():null); mysql_accept_save($output.'/mysql-concurrency-failure-'.gmdate('YmdHis').'.json',$failure); }
    fwrite(STDERR, 'Native MySQL concurrency failed: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    if ($failed !== array()) { fwrite(STDERR, 'Assertion: ' . end($failed) . "\n"); }
    exit(1);
}
