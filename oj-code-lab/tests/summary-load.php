<?php
/** Actual summary repository workload; orchestrate using run-summary-load.py. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, "Summary load fixture failed; private diagnostics withheld.\n"); exit(1); });
$config = json_decode(file_get_contents(getenv('OCL_LOAD_CONFIG')), true, 512, JSON_THROW_ON_ERROR);
$state = json_decode(file_get_contents(getenv('OCL_LOAD_STATE')), true, 512, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || (int) $config['port'] !== 33378 || ! preg_match('/^ocl_packet_[0-9]{14}_[a-f0-9]{6}$/D', $state['database']) || $state['tablePrefix'] !== 'oclpacket_') { throw new RuntimeException('Isolated packet fixture required'); }
define('ABSPATH', rtrim(getenv('OCL_LOAD_WP'), '/\\') . '/');
define('DB_NAME', $state['database']); define('DB_HOST', '127.0.0.1:33378'); define('DB_USER', $config['user']); define('DB_PASSWORD', $config['password']);
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', ''); define('WP_INSTALLING', true); define('WP_DEBUG', false); define('DISABLE_WP_CRON', true); define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_HOME', 'http://127.0.0.1:33379'); define('WP_SITEURL', WP_HOME);
$table_prefix = $state['tablePrefix']; $_SERVER['HTTP_HOST'] = '127.0.0.1:33379'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/';
require ABSPATH . 'wp-settings.php';
define('OJ_CODE_LAB_PATH', dirname(__DIR__) . '/');
require dirname(__DIR__) . '/src/Core/Autoloader.php'; \OjCodeLab\Core\Autoloader::register();
use OjCodeLab\Database\Installer;
use OjCodeLab\Database\Notebook_Repository as Books;
const LOAD_FIRST_USER = 700000;
const LOAD_LAST_USER = 700099;
$table = Installer::notebooks_table();
$range = 'user_id BETWEEN ' . LOAD_FIRST_USER . ' AND ' . LOAD_LAST_USER;
$mode = $argv[1] ?? '';
$resultPath = getenv('OCL_LOAD_RESULT');
if ($mode === 'setup') {
    if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$range}") !== 0) { throw new RuntimeException('Reserved benchmark range is not empty'); }
    $cells = array(array('id' => 'load-fixture', 'code' => 'plot', 'outputs' => array(array('type' => 'image', 'image' => str_repeat('a', 2_000_000)))));
    for ($userId = LOAD_FIRST_USER; $userId <= LOAD_LAST_USER; $userId++) {
        if (is_wp_error(Books::save_for_user($userId, $cells, 0))) { throw new RuntimeException('Seeding failed'); }
    }
    $summary = $wpdb->get_row("SELECT COUNT(*) AS notebooks, SUM(OCTET_LENGTH(cells)) AS stored_bytes FROM {$table} WHERE {$range}", ARRAY_A);
    $summary = array('notebooks' => (int) $summary['notebooks'], 'storedNotebookBytes' => (int) $summary['stored_bytes'],
        'wordpress' => $wp_version, 'php' => PHP_VERSION, 'databaseEngineVersion' => $wpdb->db_version(), 'packetBytes' => (int) $wpdb->get_var('SELECT @@SESSION.max_allowed_packet'),
        'pluginVersion' => (require dirname(__DIR__) . '/plugin-package.php')['version']);
    file_put_contents($resultPath, json_encode($summary, JSON_THROW_ON_ERROR)); exit;
}
if ($mode === 'cleanup') {
    $removed = $wpdb->query("DELETE FROM {$table} WHERE {$range}");
    file_put_contents($resultPath, json_encode(array('removedRows' => $removed, 'remainingRows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$range}")), JSON_THROW_ON_ERROR)); exit;
}
if ($mode !== 'worker') { throw new RuntimeException('Unknown mode'); }
$workerId = (int) getenv('OCL_LOAD_WORKER'); $iterations = (int) getenv('OCL_LOAD_REQUESTS'); $intervalMs = (int) getenv('OCL_LOAD_INTERVAL_MS');
if ($workerId < 0 || $workerId > 15 || $iterations < 1 || $iterations > 1000 || $intervalMs < 0 || $intervalMs * $iterations > 30000) { throw new RuntimeException('Unbounded workload'); }
$runDirectory = getenv('OCL_LOAD_PRIVATE');
file_put_contents($runDirectory . '/ready-' . $workerId, 'ready');
$deadline = microtime(true) + 20;
while (! is_file($runDirectory . '/start.json')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout'); }
    usleep(10000);
}
$startAt = json_decode(file_get_contents($runDirectory . '/start.json'), true, 512, JSON_THROW_ON_ERROR)['startAt'];
$queryCount = 0; $unsafeProjection = false; $maxQueryBytes = 0;
$observer = static function (string $sql) use ($table, &$queryCount, &$unsafeProjection, &$maxQueryBytes): string {
    if (str_contains($sql, $table)) {
        $queryCount++; $maxQueryBytes = max($maxQueryBytes, strlen($sql));
        $safe = str_starts_with(ltrim($sql), 'SELECT ') && ! preg_match('/\b(?:cells|content)\b|SELECT\s+\*/i', $sql)
            && str_contains($sql, 'cell_count') && str_contains($sql, 'has_error');
        $unsafeProjection = $unsafeProjection || ! $safe;
    }
    return $sql;
};
add_filter('query', $observer, PHP_INT_MAX);
$failures = 0; $latencies = array(); $intervals = array(); $maximumResponseBytes = 0;
for ($i = 0; $i < $iterations; $i++) {
    $scheduledAt = $startAt + ($i * $intervalMs / 1000);
    $delay = $scheduledAt - microtime(true); if ($delay > 0) { usleep((int) ($delay * 1000000)); }
    $pageFirst = LOAD_FIRST_USER + (($i % 2) * 50);
    $startWall = microtime(true); $start = hrtime(true);
    $rows = Books::summaries_for_users(range($pageFirst, $pageFirst + 49));
    $elapsedMs = (hrtime(true) - $start) / 1000000;
    $latencies[] = $elapsedMs; $intervals[] = array($startWall, microtime(true));
    if (is_wp_error($rows) || count($rows) !== 50) { $failures++; continue; }
    $bytes = strlen(wp_json_encode($rows)); $maximumResponseBytes = max($maximumResponseBytes, $bytes);
    if ($bytes >= 16384 || isset($rows[$pageFirst]['cells']) || isset($rows[$pageFirst]['outputs']) || $rows[$pageFirst]['cellCount'] !== 1) { $failures++; }
}
remove_filter('query', $observer, PHP_INT_MAX);
if ($unsafeProjection || $queryCount !== $iterations) { $failures++; }
$result = array('worker' => $workerId, 'requests' => $iterations, 'failures' => $failures, 'latencyMs' => $latencies, 'intervals' => $intervals,
    'peakMemoryBytes' => memory_get_peak_usage(true), 'maximumResponseBytes' => $maximumResponseBytes, 'maximumQueryBytes' => $maxQueryBytes,
    'summaryQueryCount' => $queryCount, 'unsafeProjectionDetected' => $unsafeProjection);
file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
