<?php
declare(strict_types=1);
define('TEACHING_TOOLS_PATH', dirname(__DIR__) . '/');
preg_match('/ \* Version: ([^\r\n]+)/', file_get_contents(TEACHING_TOOLS_PATH . 'teaching-tools.php'), $version);
define('TEACHING_TOOLS_VERSION', $version[1]);
class WP_Error { public function __construct(public string $code, public string $message, public array $data = array()) {} public function get_error_message(): string { return $this->message; } }
function __(string $text, string $domain): string { return $text; }
function get_option(string $key, mixed $default): mixed { return array('enabled' => true, 'secret' => str_repeat('s', 48), 'public_url' => 'wss://school.test/classroom-live'); }
function untrailingslashit(string $text): string { return rtrim($text, '/'); }
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_remote_get(string $url, array $args): mixed {
    if ($url !== 'https://school.test/classroom-live/status') { throw new RuntimeException('Incorrect status URL'); }
    $time = $args['headers']['X-OJ-Realtime-Time'];
    if (!hash_equals(hash_hmac('sha256', "GET\n/status\n" . $time . "\n", str_repeat('s', 48)), $args['headers']['X-OJ-Realtime-Signature'])) { throw new RuntimeException('Unsigned version query'); }
    return $GLOBALS['server_response'];
}
function wp_remote_retrieve_response_code(array $response): int { return $response['status']; }
function wp_remote_retrieve_body(array $response): string { return json_encode($response['body']); }
function rest_ensure_response(mixed $response): mixed { return $response; }
foreach (array('Support/Realtime_Settings', 'Support/Realtime_Versions', 'Rest/Controllers/Realtime_Controller') as $class) { require TEACHING_TOOLS_PATH . 'src/' . $class . '.php'; }
use TeachingTools\Rest\Controllers\Realtime_Controller;
$base = array('ok' => true, 'authenticated' => true, 'service' => 'oj-realtime-hub', 'app' => 'teaching-tools', 'protocol' => 1, 'version' => '1.0.1');
foreach (array('1.0.0', TEACHING_TOOLS_VERSION) as $module) {
    $GLOBALS['server_response'] = array('status' => 200, 'body' => $base + array('appVersion' => $module));
    $status = Realtime_Controller::status();
    if ($status['versions']['updateRequired'] !== ($module === '1.0.0')) { throw new RuntimeException('Version status mismatch'); }
}
$GLOBALS['server_response'] = array('status' => 200, 'body' => array_merge($base, array('authenticated' => false)));
if (!is_wp_error(Realtime_Controller::status())) { throw new RuntimeException('Unverified server must not be reported current'); }
$GLOBALS['server_response'] = new WP_Error('offline', 'unreachable');
if (!is_wp_error(Realtime_Controller::status())) { throw new RuntimeException('Unreachable server must remain unknown'); }
echo "Signed realtime status passed: installed versions, update comparison, unverified and unreachable fail closed.\n";
