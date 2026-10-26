<?php
/** Real WordPress + isolated MariaDB at a 16 MiB server packet ceiling. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
set_exception_handler(static function (Throwable $error): void {
    $message = str_starts_with($error->getMessage(), 'FAIL: ') ? $error->getMessage() : 'Packet integration failed; private environment diagnosis required.';
    fwrite(STDERR, $message . PHP_EOL); exit(1);
});
$config = json_decode(file_get_contents(getenv('OCL_PACKET_TEST_CONFIG')), true, 512, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || (int) $config['port'] !== 33378) { throw new RuntimeException('Isolated packet DB required'); }
$dbName = 'ocl_packet_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));
$connection = new mysqli($config['host'], $config['user'], $config['password'], '', $config['port']);
$connection->query("CREATE DATABASE {$dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $connection->close();
define('ABSPATH', rtrim(getenv('OCL_PACKET_TEST_WP'), '/\\') . '/');
define('DB_NAME', $dbName); define('DB_HOST', '127.0.0.1:33378'); define('DB_USER', $config['user']); define('DB_PASSWORD', $config['password']);
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
define('WP_INSTALLING', true); define('WP_DEBUG', false); define('DISABLE_WP_CRON', true); define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_HOME', 'http://127.0.0.1:33379'); define('WP_SITEURL', WP_HOME);
foreach (array('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY') as $key) { define($key, bin2hex(random_bytes(32))); }
$table_prefix = 'oclpacket_'; $_SERVER['HTTP_HOST'] = '127.0.0.1:33379'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/';
require ABSPATH . 'wp-settings.php'; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', static fn() => false);
wp_install('Packet test', 'packet_admin', 'packet@example.invalid', false, '', bin2hex(random_bytes(16)));
$platform = getenv('OCL_PACKET_TEST_PLATFORM'); require_once $platform . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);
define('OJ_PLATFORM_VERSION', '2.0.0-dev.99'); define('OJ_PLATFORM_ADDON_API_VERSION', '1.1.0');
$testPlugin = getenv('OCL_PACKET_TEST_PLUGIN') ?: dirname(__DIR__);
require $testPlugin . '/oj-code-lab.php';
use OjCodeLab\Database\Installer;
use OjCodeLab\Database\Notebook_Repository as Books;
use OjCodeLab\Datasets\Upload_Store as Uploads;
use OjCodeLab\Datasets\Dataset_Cache as Cache;
use OjCodeLab\Support\Database_Packet;
use OjCodeLab\Support\Dataset_Limits;
use CodingPanda\OjPlatform\AddonApi\V1 as Api;
$checks = 0; $labels = array();
function packet_check(bool $condition, string $label): void {
    global $checks, $labels;
    if (! $condition) { throw new RuntimeException('FAIL: ' . $label); }
    $checks++; $labels[] = $label; echo "PASS {$label}\n";
}
function packet_request(string $method, array $params = array()): WP_REST_Response {
    $request = new WP_REST_Request($method, '/oj-code-lab/v1/notebook');
    $request->set_body_params($params); return rest_do_request($request);
}
$packetBytes = (int) $wpdb->get_var('SELECT @@SESSION.max_allowed_packet');
packet_check($packetBytes === 16777216, 'actual DB session is limited to 16 MiB');
oj_code_lab_activate();
packet_check(Installer::tables_exist() && get_option('oj_code_lab_db_version') === '2' && ! get_option('oj_code_lab_install_error'), 'fresh activation and bundled sample storage succeed at 16 MiB');
$limit = Database_Packet::payload_limit(Books::MAX_PAYLOAD_BYTES);
packet_check($limit === 8355840 && Dataset_Limits::max_bytes() === $limit, 'effective notebook and dataset limits include 2x escaping and 64 KiB overhead');
$student = wp_insert_user(array('user_login' => 'packet_learner', 'user_pass' => wp_generate_password(), 'user_email' => 'packet_learner@example.invalid', 'role' => 'subscriber'));
(new WP_User($student))->add_cap(Api\AddonCapabilityKeyV1::wordpress('oj_code_lab_use')); wp_set_current_user($student);
$maximumWriteBytes = 0; $writeCount = 0;
$measure = static function (string $sql) use (&$maximumWriteBytes, &$writeCount): string {
    if (preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/i', $sql)) { $maximumWriteBytes = max($maximumWriteBytes, strlen($sql)); $writeCount++; }
    return $sql;
};
add_filter('query', $measure, PHP_INT_MAX);
$small = array(array('id' => 'initial', 'type' => 'code', 'code' => 'print("keep")', 'outputs' => array()));
$created = packet_request('PUT', array('formatVersion' => 2, 'revision' => 0, 'cells' => $small));
packet_check($created->get_status() === 200 && $created->get_data()['revision'] === 1, 'initial notebook save succeeds');
$large = array(array('id' => 'boundary', 'type' => 'code', 'code' => '', 'outputs' => array()));
$overhead = strlen(wp_json_encode($large));
$large[0]['code'] = str_repeat('\\', intdiv($limit - $overhead, 2));
$large[0]['code'] .= str_repeat('x', $limit - strlen(wp_json_encode($large)));
packet_check(strlen(wp_json_encode($large)) === $limit, 'stress notebook exactly fills effective serialized byte limit');
$overflow = $large; $overflow[0]['code'] .= '\\';
$beforeWrites = $writeCount;
$rejected = packet_request('PUT', array('formatVersion' => 2, 'revision' => 1, 'cells' => $overflow));
packet_check($rejected->get_status() === 413 && $rejected->get_data()['data']['maxBytes'] === $limit && $writeCount === $beforeWrites, 'oversized notebook returns 413 without sending a mutation');
$preserved = packet_request('GET')->get_data();
packet_check($preserved['revision'] === 1 && $preserved['cells'] === $small, 'rejected save preserves existing notebook and revision');
unset($overflow, $preserved);
$saved = packet_request('PUT', array('formatVersion' => 2, 'revision' => 1, 'cells' => $large));
packet_check($saved->get_status() === 200 && $saved->get_data()['revision'] === 2, 'backslash-heavy boundary notebook saves despite SQL escaping');
$loaded = packet_request('GET')->get_data();
$bookHash = hash('sha256', $large[0]['code']);
packet_check($loaded['revision'] === 2 && hash('sha256', $loaded['cells'][0]['code']) === $bookHash, 'boundary notebook round trip preserves all bytes');
unset($loaded, $large);
$csv = "value\n" . str_repeat("'", $limit - 6);
$beforeRows = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::uploads_table());
$beforeWrites = $writeCount;
$tooLarge = Uploads::store_bytes($csv . "'");
packet_check(is_wp_error($tooLarge) && $tooLarge->get_error_data()['status'] === 413 && $writeCount === $beforeWrites
    && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::uploads_table()) === $beforeRows, 'oversized upload returns 413 without changing stored files');
$uploaded = Uploads::store_bytes($csv);
packet_check(is_string($uploaded) && hash('sha256', (string) Uploads::read($uploaded)) === hash('sha256', $csv), 'quote-heavy upload at effective limit round trips through real SQL');
Cache::put('packet-existing', 'original,csv'); $beforeWrites = $writeCount;
Cache::put('packet-existing', $csv . "'");
packet_check($writeCount === $beforeWrites && Cache::get('packet-existing') === 'original,csv', 'oversized cache refresh preserves old entry without cleanup or write');
Cache::put('packet-boundary', $csv);
$csvHash = hash('sha256', $csv);
packet_check(hash('sha256', (string) Cache::get('packet-boundary')) === $csvHash, 'quote-heavy boundary cache entry round trips through real SQL');
unset($csv);
$wpdb->suppress_errors(true);
$denyLimit = static fn(string $sql): string => $sql === 'SELECT @@SESSION.max_allowed_packet' ? 'SELECT missing_packet_test_column' : $sql;
add_filter('query', $denyLimit);
$beforeWrites = $writeCount;
$unknownBook = packet_request('PUT', array('formatVersion' => 2, 'revision' => 2, 'cells' => array()));
$unknownUpload = Uploads::store_bytes('small,csv');
Cache::put('packet-boundary', 'replacement');
remove_filter('query', $denyLimit);
packet_check($unknownBook->get_status() === 503 && is_wp_error($unknownUpload) && $unknownUpload->get_error_data()['status'] === 503 && $writeCount === $beforeWrites, 'packet lookup failure blocks notebook upload and cache mutations');
$keptBook = Books::find_for_user($student);
packet_check($keptBook['revision'] === 2 && hash('sha256', $keptBook['cells'][0]['code']) === $bookHash && hash('sha256', (string) Cache::get('packet-boundary')) === $csvHash, 'lookup failure preserves saved notebook and cache bytes');
unset($keptBook);
$recoveredBook = packet_request('PUT', array('formatVersion' => 2, 'revision' => 2, 'cells' => $small));
$recoveredUpload = Uploads::store_bytes('normal,csv'); Cache::put('packet-recovered', 'normal,csv');
packet_check($recoveredBook->get_status() === 200 && $recoveredBook->get_data()['revision'] === 3 && is_string($recoveredUpload)
    && Cache::get('packet-recovered') === 'normal,csv', 'normal notebook upload and cache writes recover after limit lookup failure');
$textBoundary = array(array('id' => 'text-boundary', 'type' => 'text', 'code' => "한글 기록\n<script>문자 그대로</script> & **정리**\n", 'outputs' => array()));
$textOverhead = strlen(wp_json_encode($textBoundary));
$textBoundary[0]['code'] .= str_repeat('\\', intdiv($limit - $textOverhead, 2));
$textBoundary[0]['code'] .= str_repeat('x', $limit - strlen(wp_json_encode($textBoundary)));
packet_check(strlen(wp_json_encode($textBoundary)) === $limit, 'text cell metadata and Unicode are included in exact byte boundary');
$textOverflow = $textBoundary; $textOverflow[0]['code'] .= 'x'; $beforeWrites = $writeCount;
$textRejected = packet_request('PUT', array('formatVersion' => 2, 'revision' => 3, 'cells' => $textOverflow));
packet_check($textRejected->get_status() === 413 && $writeCount === $beforeWrites && packet_request('GET')->get_data()['revision'] === 3, 'oversized text is rejected without changing code notebook or revision');
unset($textOverflow);
$textSaved = packet_request('PUT', array('formatVersion' => 2, 'revision' => 3, 'cells' => $textBoundary));
packet_check($textSaved->get_status() === 200 && $textSaved->get_data()['revision'] === 4, 'text cell at actual packet boundary saves successfully');
$textLoaded = packet_request('GET')->get_data();
packet_check($textLoaded['formatVersion'] === 2 && $textLoaded['cells'][0]['type'] === 'text' && $textLoaded['cells'][0]['outputs'] === array()
    && hash('sha256', $textLoaded['cells'][0]['code']) === hash('sha256', $textBoundary[0]['code']), 'boundary text round trip preserves literal content and type');
unset($textLoaded, $textBoundary);
\OjCodeLab\Jobs\Dataset_Cache_Cleanup::maybe_schedule();
$health = \OjCodeLab\Support\Operations::health();
packet_check($health['status'] === 'recommended' && str_contains($health['description'], '8,355,840'), 'site health explains safe automatically reduced storage limit');
packet_check($maximumWriteBytes < $packetBytes && $maximumWriteBytes > $packetBytes - 131072, 'largest escaped SQL approaches but stays below actual packet ceiling');
packet_check('1' === (string) $wpdb->get_var('SELECT 1'), 'database connection remains usable after every boundary and failure');
remove_filter('query', $measure, PHP_INT_MAX);
$report = array('scope' => 'isolated-real-wordpress-and-mariadb-packet-boundaries', 'passed' => true, 'checks' => $checks,
    'wordpress' => $wp_version, 'php' => PHP_VERSION, 'databaseEngineVersion' => $wpdb->db_version(), 'pluginVersion' => OJ_CODE_LAB_VERSION,
    'packetBytes' => $packetBytes, 'effectivePayloadBytes' => $limit, 'maximumObservedWriteBytes' => $maximumWriteBytes, 'peakMemoryBytes' => memory_get_peak_usage(true), 'cases' => $labels,
    'limitations' => array('Local test-only database; no production site access.', 'Real WordPress REST, SQL and storage; public Addon capability type only, no school login or live Kaggle requests.'));
if (getenv('OCL_PACKET_TEST_STATE')) {
    file_put_contents(getenv('OCL_PACKET_TEST_STATE'), wp_json_encode(array('database' => $dbName, 'tablePrefix' => $table_prefix, 'studentId' => $student), JSON_THROW_ON_ERROR));
}
file_put_contents(getenv('OCL_PACKET_TEST_REPORT'), wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "{$checks} real WordPress packet integration checks passed.\n";
