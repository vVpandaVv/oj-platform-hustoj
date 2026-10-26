<?php
declare(strict_types=1);
// Disposable local test host. Never part of the distribution package.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }
$config = json_decode(file_get_contents(getenv('OCL_TEST_CONFIG')), true, 512, JSON_THROW_ON_ERROR);
$report = json_decode(file_get_contents(getenv('OCL_TEST_REPORT')), true, 512, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || (int)$config['port'] !== 33368 || !str_starts_with($report['database'], 'ocl_test_')) { exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$asset = preg_match('~^/assets/(worker-runtime.js|lab.js|lab.css)$~D', $path, $matches) ? $matches[1] : null;
if ($asset) {
    header('Content-Type: ' . (str_ends_with($asset, '.css') ? 'text/css' : 'application/javascript') . '; charset=utf-8');
    readfile((getenv('OCL_TEST_PLUGIN') ?: dirname(__DIR__)) . '/resources/' . (str_ends_with($asset, '.css') ? 'css/' : 'js/') . $asset); exit;
}
define('ABSPATH', rtrim(getenv('OCL_TEST_WP'), '/\\') . '/');
define('DB_NAME', $report['database']); define('DB_HOST', '127.0.0.1:33368'); define('DB_USER', $config['user']); define('DB_PASSWORD', $config['password']);
define('DB_CHARSET', 'utf8mb4'); define('DB_COLLATE', '');
define('WP_INSTALLING', true); define('WP_DEBUG', false); define('DISABLE_WP_CRON', true); define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_HOME', 'http://127.0.0.1:33369'); define('WP_SITEURL', WP_HOME);
foreach (array('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY') as $key) { define($key, hash('sha256', $config['password'] . $key)); }
$table_prefix = 'ocltest_'; require ABSPATH . 'wp-settings.php';
$platform = getenv('OCL_TEST_PLATFORM'); require_once $platform . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);
define('OJ_PLATFORM_VERSION', '2.0.0-dev.99'); define('OJ_PLATFORM_ADDON_API_VERSION', '1.1.0');
$testPlugin = getenv('OCL_TEST_PLUGIN') ?: dirname(__DIR__);
require $testPlugin . '/oj-code-lab.php';
// Authentication is a local fixture; capability and nonce checks are real WordPress.
$learner = get_user_by('login', 'learner_a'); wp_set_current_user((int)$learner->ID);
\OjCodeLab\Support\Worker_Script_Route::maybe_serve();
if (str_starts_with($path, '/api/')) {
    $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
    header('Content-Type: application/json; charset=utf-8');
    if (!wp_verify_nonce($nonce, 'wp_rest')) { http_response_code(403); echo '{"message":"invalid nonce"}'; exit; }
    $request = new WP_REST_Request($_SERVER['REQUEST_METHOD'], substr($path, 4));
    $request->set_query_params($_GET);
    $body = json_decode(file_get_contents('php://input'), true);
    if (is_array($body)) { $request->set_body_params($body); }
    $response = rest_do_request($request); http_response_code($response->get_status()); echo wp_json_encode($response->get_data()); exit;
}
if ($path !== '/oj-addons/oj-code-lab/lab') { http_response_code(404); exit; }
\OjCodeLab\Support\Cross_Origin_Isolation::maybe_send_headers();
add_filter('rest_url', static fn($url, $route) => WP_HOME . '/api/' . ltrim($route, '/'), 10, 2);
$request = new \CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1('addon.oj_code_lab.lab', (int)$learner->ID, array(), array(), 'ko_KR', 'test-browser');
$response = (new \OjCodeLab\Addon\Lab_Page_Handler())->handle($request);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="ko"><meta charset="utf-8"><title>Code Lab browser test</title><link rel="stylesheet" href="/assets/lab.css"><body>' . $response->body() . '<script src="/assets/worker-runtime.js"></script><script src="/assets/lab.js"></script></body></html>';
