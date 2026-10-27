<?php
/**
 * CLI contract check: real OJ Platform registration/validation/runtime classes,
 * in-memory WordPress hooks, REST cookie/nonce boundary, user-meta and HTML APIs.
 * This does not start WordPress, a web server, a database or a network request.
 */

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonPageRequestV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifestValidator;
use CodingPanda\OjPlatform\Kernel\Addon\AddonNamespaceLedger;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRegistrationGateway;
use CodingPanda\OjPlatform\Kernel\Addon\AddonRuntimeContext;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshot;
use CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshotProvider;
use CodingPanda\OjPlatform\Kernel\Addon\UnavailableAddonRuntimeServiceFactory;
use CodingPanda\OjPlatform\Kernel\Addon\WordPressAddonHookPublisher;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use OjAddonStarter\Core\Plugin;
use OjAddonStarter\Rest\NoteApi;
use OjAddonStarter\Storage\NoteRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (defined('ABSPATH')) {
    throw new RuntimeException('Run this isolated CLI check without loading WordPress.');
}
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$repo = dirname(__DIR__, 2);
$platform = $repo . '/oj-integration-rebuild/oj-platform';
$example = dirname(__DIR__) . '/examples/oj-addon-starter';
$GLOBALS['starter_test'] = [
    'hooks' => [], 'routes' => [], 'user' => 0, 'caps' => [],
    'meta' => [], 'writes' => 0, 'fail_write' => false,
];
$checks = 0;

function starter_same(mixed $expected, mixed $actual, string $message): void
{
    ++$GLOBALS['checks'];
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function starter_check(bool $condition, string $message): void
{
    starter_same(true, $condition, $message);
}

// WordPress doubles: only the behavior exercised by this example is modeled.
function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['starter_test']['hooks'][$hook][$priority][] = [$callback, $accepted_args];
}

function do_action(string $hook, mixed ...$arguments): void
{
    $priorities = $GLOBALS['starter_test']['hooks'][$hook] ?? [];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $callback(...array_slice($arguments, 0, $accepted));
        }
    }
}

function get_current_user_id(): int { return $GLOBALS['starter_test']['user']; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function current_user_can(string $capability): bool
{
    return in_array($capability, $GLOBALS['starter_test']['caps'][get_current_user_id()] ?? [], true);
}
function get_user_meta(int $userId, string $key, bool $single = false): mixed
{
    return $GLOBALS['starter_test']['meta'][$userId][$key] ?? '';
}
function update_user_meta(int $userId, string $key, mixed $value): int|bool
{
    if ($GLOBALS['starter_test']['fail_write']) {
        return false;
    }
    ++$GLOBALS['starter_test']['writes'];
    $GLOBALS['starter_test']['meta'][$userId][$key] = stripslashes($value);
    return true;
}
function wp_slash(string $value): string { return addslashes($value); }
function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
function esc_textarea(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function esc_html__(string $value, string $domain): string { return esc_textarea($value); }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function rest_url(string $path): string { return 'https://example.test/?rest_route=/' . ltrim($path, '/'); }
function wp_create_nonce(string $action): string { return 'test-nonce-' . get_current_user_id() . '-' . $action; }
function register_rest_route(string $namespace, string $route, array $definitions): void
{
    $GLOBALS['starter_test']['routes']['/' . $namespace . $route] = $definitions;
}
function delete_metadata(string $type, int $objectId, string $key, mixed $value, bool $deleteAll): bool
{
    starter_same(['user', 0, '_oj_addon_starter_note', '', true], [$type, $objectId, $key, $value, $deleteAll], 'Uninstall must target only its own user-meta key.');
    foreach ($GLOBALS['starter_test']['meta'] as &$values) {
        unset($values[$key]);
    }
    unset($values);
    return true;
}

final class WP_Error
{
    public function __construct(private string $code, private string $message, private array $data = []) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}

final class WP_REST_Request
{
    private string $body = '';
    public function __construct(public readonly string $method, public readonly string $route) {}
    public function set_body(string $body): void { $this->body = $body; }
    public function get_json_params(): mixed { return json_decode($this->body, true); }
}

final class WP_REST_Response
{
    private array $headers = [];
    public function __construct(private mixed $data, private int $status = 200) {}
    public function header(string $key, string $value): void { $this->headers[$key] = $value; }
    public function get_data(): mixed { return $this->data; }
    public function get_status(): int { return $this->status; }
    public function get_headers(): array { return $this->headers; }
}

/**
 * Models WordPress REST cookie authentication before permission_callback.
 * Missing nonce turns a cookie request into an anonymous request; an invalid
 * nonce is rejected before the addon. WordPress core itself is not loaded.
 */
function starter_dispatch(string $method, string $body = '', ?string $nonce = 'valid', int $cookieUser = 1): WP_Error|WP_REST_Response
{
    $GLOBALS['starter_test']['user'] = $cookieUser;
    if ($nonce === null) {
        $GLOBALS['starter_test']['user'] = 0;
    } elseif ($nonce !== 'valid' || $cookieUser < 1) {
        return new WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed.', ['status' => 403]);
    }
    $route = '/oj-addon-starter/v1/note';
    foreach ($GLOBALS['starter_test']['routes'][$route] ?? [] as $definition) {
        if ($definition['methods'] !== $method) {
            continue;
        }
        $request = new WP_REST_Request($method, $route);
        $request->set_body($body);
        $allowed = ($definition['permission_callback'])($request);
        return $allowed instanceof WP_Error ? $allowed : ($definition['callback'])($request);
    }
    throw new RuntimeException('The requested addon route has not been registered.');
}

function starter_error(WP_Error|WP_REST_Response $result, string $code, int $status, string $message): void
{
    starter_check($result instanceof WP_Error, $message . ': expected an error.');
    starter_same($code, $result->get_error_code(), $message . ': error code.');
    starter_same($status, $result->get_error_data()['status'] ?? null, $message . ': HTTP status.');
}

function starter_payload(WP_Error|WP_REST_Response $result, array $expected, string $message): void
{
    starter_check($result instanceof WP_REST_Response, $message . ': expected a response.');
    starter_same(200, $result->get_status(), $message . ': HTTP status.');
    starter_same($expected, $result->get_data(), $message . ': payload.');
    starter_same('private, no-store', $result->get_headers()['Cache-Control'] ?? null, $message . ': private caching.');
}

// Main-file loading must be safe even when the platform has not loaded yet.
define('ABSPATH', $repo . '/');
require $example . '/oj-addon-starter.php';
starter_same(false, Plugin::compatible(), 'The platform may load after this addon.');
starter_same(false, Plugin::can_use(), 'A missing platform must not grant access.');
starter_same(null, Plugin::runtime(), 'No runtime before platform acceptance.');
starter_same([], $GLOBALS['starter_test']['routes'], 'No REST routes before acceptance.');
starter_check(isset($GLOBALS['starter_test']['hooks']['oj_platform_register_addons']), 'Register the collector hook immediately during main-file loading.');

// Read actual published version constants without booting the entire platform.
$main = file_get_contents($platform . '/oj-platform.php');
foreach (['OJ_PLATFORM_VERSION', 'OJ_PLATFORM_ADDON_API_VERSION'] as $name) {
    starter_same(1, preg_match('/define\(\'' . $name . '\', \'([^\']+)\'\)/', $main, $match), 'Locate ' . $name . ' in the published platform.');
    define($name, $match[1]);
}
require $platform . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($platform);
starter_same(true, Plugin::compatible(), 'The published platform meets the example requirement.');

$errors = new class implements ErrorReporter {
    public array $reported = [];
    public function report(Throwable $error): void { $this->reported[] = $error; }
};
$core = new class implements CoreContributionSnapshotProvider {
    public function snapshot(): CoreContributionSnapshot
    {
        // Platform console anchor used by this example; other registries are empty.
        return new CoreContributionSnapshot([], [], [], ['classes'], [], [], [], []);
    }
};
$ledger = new class implements AddonNamespaceLedger {
    public array $owners = [];
    public function ownerOf(string $namespace): string { return $this->owners[$namespace] ?? ''; }
    public function bind(string $namespace, string $addonId): void { $this->owners[$namespace] = $addonId; }
};
$registration = new AddonRegistrationGateway(OJ_PLATFORM_ADDON_API_VERSION, $errors);
(new WordPressAddonHookPublisher())->publishRegistration($registration);
$candidates = $registration->close();
starter_same([], $errors->reported, 'Real registration must not report a build failure.');
starter_same(1, count($candidates), 'The example registers exactly one addon.');
$validator = new AddonManifestValidator(OJ_PLATFORM_VERSION, OJ_PLATFORM_ADDON_API_VERSION, $core, $ledger);
$entries = $validator->validate($candidates);
starter_same(1, count($entries), 'The validator returns one catalog entry.');
$entry = $entries[0];
starter_same('accepted', $entry->status(), 'Actual manifest validation accepts the example: ' . $entry->code());
starter_same('oj-addon-starter', $ledger->ownerOf('addon_starter'), 'Acceptance reserves the namespace.');
$manifest = $entry->manifest();
starter_same('addon_starter', $manifest->namespace(), 'The declared namespace is stable.');
starter_same('addon_starter_use', $manifest->capabilities()[0]->key(), 'The route permission belongs to this namespace.');
starter_same('/oj-console/addons/oj-addon-starter', $manifest->consolePages()[0]->path(), 'The route remains inside its own addon path.');
starter_same('addon.addon_starter.note', $manifest->consoleMenus()[0]->route(), 'The menu targets the declared console page.');
starter_same([], $GLOBALS['starter_test']['routes'], 'Validation alone does not activate REST routes.');
starter_same(false, isset($GLOBALS['starter_test']['hooks']['rest_api_init']), 'No REST hook before onReady.');

$conflictingCore = new class implements CoreContributionSnapshotProvider {
    public function snapshot(): CoreContributionSnapshot
    {
        return new CoreContributionSnapshot([], ['/oj-console/addons/oj-addon-starter'], [], ['classes'], [], [], [], []);
    }
};
$conflict = (new AddonManifestValidator(OJ_PLATFORM_VERSION, OJ_PLATFORM_ADDON_API_VERSION, $conflictingCore, $ledger))->validate($candidates)[0];
starter_same('rejected', $conflict->status(), 'A conflicting route is rejected by the actual validator.');
starter_same(null, $conflict->onReadyCallback(), 'A rejected entry exposes no onReady callback.');
starter_same(false, isset($GLOBALS['starter_test']['hooks']['rest_api_init']), 'A rejected entry cannot register the example REST hook.');

// Use real runtime objects; unused platform gateways are explicitly unavailable.
$correlations = new AddonCorrelationIdFactory();
$services = new UnavailableAddonRuntimeServiceFactory($correlations);
$runtime = new AddonRuntimeContext(
    'oj-addon-starter', $entry, OJ_PLATFORM_VERSION, OJ_PLATFORM_ADDON_API_VERSION,
    'starter-contract-check', $services->contexts('oj-addon-starter', $entry),
    $services->aiTasks('oj-addon-starter', $entry), $correlations
);
$ready = $entry->onReadyCallback();
starter_check(is_callable($ready), 'Accepted manifest supplies its own onReady callback.');
$ready($runtime);
starter_same($runtime, Plugin::runtime(), 'onReady stores only the supplied addon runtime.');
starter_same('accepted', Plugin::runtime()->snapshot()->status(), 'The real runtime snapshot is accepted.');
starter_check(isset($GLOBALS['starter_test']['hooks']['rest_api_init']), 'onReady installs the REST hook.');
do_action('rest_api_init');
starter_same(['GET', 'POST'], array_column($GLOBALS['starter_test']['routes']['/oj-addon-starter/v1/note'], 'methods'), 'Only the documented GET and POST endpoints are registered.');

$mappedCapability = AddonCapabilityKeyV1::wordpress('addon_starter_use');
$GLOBALS['starter_test']['caps'] = [1 => [$mappedCapability], 2 => [$mappedCapability], 3 => []];
starter_payload(starter_dispatch('GET'), ['note' => ''], 'A new user starts with an empty memo');
$before = $GLOBALS['starter_test']['meta'];
starter_error(starter_dispatch('POST', '{"note":"unauthorized"}', null), 'starter_authentication_required', 401, 'Missing nonce becomes anonymous at the modeled cookie boundary');
starter_same($before, $GLOBALS['starter_test']['meta'], 'Missing nonce cannot write.');
starter_error(starter_dispatch('POST', '{"note":"unauthorized"}', 'expired'), 'rest_cookie_invalid_nonce', 403, 'Invalid nonce is rejected at the modeled cookie boundary');
starter_same($before, $GLOBALS['starter_test']['meta'], 'Invalid nonce cannot write.');
starter_error(starter_dispatch('GET', '', null, 0), 'starter_authentication_required', 401, 'Anonymous reads require login');
starter_error(starter_dispatch('POST', '{"note":"forbidden"}', 'valid', 3), 'starter_forbidden', 403, 'A logged-in user still requires the mapped addon capability');
starter_same($before, $GLOBALS['starter_test']['meta'], 'Denied requests cannot change storage.');

$memo = "한글 메모\nC:\\temp\\파일과 \\\"따옴표\\\"";
$json = json_encode(['note' => $memo], JSON_THROW_ON_ERROR);
starter_payload(starter_dispatch('POST', $json), ['note' => $memo], 'POST stores UTF-8 and literal backslashes');
starter_payload(starter_dispatch('GET'), ['note' => $memo], 'GET reads the stored memo');
$writes = $GLOBALS['starter_test']['writes'];
starter_payload(starter_dispatch('POST', $json), ['note' => $memo], 'Saving the same value is successful');
starter_same($writes, $GLOBALS['starter_test']['writes'], 'An unchanged value does not depend on a successful metadata update.');
starter_payload(starter_dispatch('GET', '', 'valid', 2), ['note' => ''], 'User two cannot read user one data');
starter_payload(starter_dispatch('POST', '{"note":"second user"}', 'valid', 2), ['note' => 'second user'], 'User two stores a separate memo');
starter_payload(starter_dispatch('GET'), ['note' => $memo], 'User one remains isolated from user two writes');

foreach (['null', '[]', '{}', '{"note":null}', '{"note":[]}', '{"note":"x","user_id":2}', '{"user_id":2}', '{"note":'] as $body) {
    $before = $GLOBALS['starter_test']['meta'];
    $result = starter_dispatch('POST', $body);
    starter_check($result instanceof WP_Error && ($result->get_error_data()['status'] ?? null) === 400, 'Invalid JSON or an invalid note shape is rejected: ' . $body);
    starter_same($before, $GLOBALS['starter_test']['meta'], 'Invalid input cannot change any user memo.');
}
foreach ([str_repeat('a', NoteRepository::MAX_BYTES + 1), str_repeat('가', 1334), "nul\0byte", "control\x01"] as $note) {
    $before = $GLOBALS['starter_test']['meta'];
    starter_error(starter_dispatch('POST', json_encode(['note' => $note], JSON_THROW_ON_ERROR)), 'starter_invalid_note', 400, 'Oversized text and forbidden control characters are rejected');
    starter_same($before, $GLOBALS['starter_test']['meta'], 'Validation failure cannot write.');
}
$GLOBALS['starter_test']['user'] = 1;
starter_error(NoteRepository::save_for_current_user("\xC3\x28"), 'starter_invalid_note', 400, 'The storage boundary rejects malformed UTF-8');
$GLOBALS['starter_test']['fail_write'] = true;
starter_error(starter_dispatch('POST', '{"note":"different value"}'), 'starter_save_failed', 500, 'Database write failure is reported');
$GLOBALS['starter_test']['fail_write'] = false;
starter_payload(starter_dispatch('GET'), ['note' => $memo], 'A failed update preserves the previous value');
starter_payload(starter_dispatch('POST', '{"note":"<b>plain text</b>"}'), ['note' => 'plain text'], 'The sanitizer strips HTML tags');
starter_payload(starter_dispatch('POST', '{"note":""}'), ['note' => ''], 'An empty note clears the value');

$GLOBALS['starter_test']['user'] = 1;
$GLOBALS['starter_test']['meta'][1][NoteRepository::META_KEY] = '<script>unsafe()</script> & "quoted"';
$request = new AddonPageRequestV1('addon.addon_starter.note', 1, [], [], 'ko_KR', 'starter-page-check');
$page = $manifest->consolePages()[0]->handler()->handle($request);
starter_same(200, $page->status(), 'The accepted page handler returns HTML successfully.');
starter_same('standard', $page->layout(), 'The example uses standard console layout.');
starter_same(true, $page->supportsDarkMode(), 'The token-based page declares dark-mode support.');
starter_check(str_contains($page->body(), '&lt;script&gt;unsafe()&lt;/script&gt;'), 'Stored text is HTML-escaped in the textarea.');
starter_check(! str_contains($page->body(), '<script>unsafe()'), 'Stored text cannot inject a script.');
starter_same(1, preg_match('/<script type="application\/json" id="oj-addon-starter-config">(.*?)<\/script>/s', $page->body(), $configMatch), 'The page provides inert JSON configuration.');
$config = json_decode($configMatch[1], true, 512, JSON_THROW_ON_ERROR);
starter_same(rest_url(NoteApi::NAMESPACE . '/note'), $config['endpoint'], 'REST URL generation preserves plain-permalink query format.');
starter_same(wp_create_nonce('wp_rest'), $config['nonce'], 'The page config carries the current user REST nonce.');
$wrongActor = new AddonPageRequestV1('addon.addon_starter.note', 2, [], [], 'ko_KR', 'starter-wrong-actor');
starter_same(403, $manifest->consolePages()[0]->handler()->handle($wrongActor)->status(), 'A mismatched request actor cannot render another user memo.');
$GLOBALS['starter_test']['user'] = 3;
$denied = new AddonPageRequestV1('addon.addon_starter.note', 3, [], [], 'ko_KR', 'starter-denied-page');
starter_same(403, $manifest->consolePages()[0]->handler()->handle($denied)->status(), 'The page also enforces the addon capability.');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($example, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    starter_same(0, preg_match('/CodingPanda\\\\OjPlatform\\\\(?:Kernel|Modules|AddonApi\\\\V1\\\\Internal)\\\\/', $source), 'Addon code must use public API contracts only: ' . $file->getFilename());
}
$GLOBALS['starter_test']['meta'][1]['another_plugin_key'] = 'keep';
define('WP_UNINSTALL_PLUGIN', 'oj-addon-starter/oj-addon-starter.php');
require $example . '/uninstall.php';
starter_same('keep', $GLOBALS['starter_test']['meta'][1]['another_plugin_key'], 'Uninstall preserves other plugins user metadata.');
foreach ([1, 2] as $userId) {
    starter_same(false, isset($GLOBALS['starter_test']['meta'][$userId][NoteRepository::META_KEY]), 'Uninstall removes the example note for every user.');
}

echo '[starter] OK (' . $checks . ' checks): real platform manifest validation, lifecycle callback, REST authorization, user isolation, input validation and page escaping.' . PHP_EOL;
echo '[scope] WordPress authentication/storage/HTTP use in-memory doubles; no live WordPress server or network was tested.' . PHP_EOL;
