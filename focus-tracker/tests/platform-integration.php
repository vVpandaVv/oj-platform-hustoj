<?php
declare(strict_types=1);

/** Run: D:\php\php.exe focus-tracker/tests/platform-integration.php */

define('FOCUS_TRACKER_PATH', dirname(__DIR__) . '/');
define('FOCUS_TRACKER_FILE', FOCUS_TRACKER_PATH . 'focus-tracker.php');
define('FOCUS_TRACKER_URL', '/__focus/');
preg_match('/^ \* Version:\s*(\S+)/m', file_get_contents(FOCUS_TRACKER_FILE), $version);
define('FOCUS_TRACKER_VERSION', $version[1]);
require_once FOCUS_TRACKER_PATH . 'src/Core/Autoloader.php';
\FocusTracker\Core\Autoloader::register();
// This Windows PHP build reports is_readable=false on readable project files.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'FocusTracker\\')) {
        $path = FOCUS_TRACKER_PATH . 'src/' . str_replace('\\', '/', substr($class, strlen('FocusTracker\\'))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

$GLOBALS['fixture_actor'] = 1;
$GLOBALS['fixture_can_participate'] = true;
$GLOBALS['fixture_home'] = 'https://fixture.test/';
$GLOBALS['fixture_route_var'] = null;
function get_current_user_id(): int { return $GLOBALS['fixture_actor']; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function current_user_can($capability): bool { return $GLOBALS['fixture_can_participate']; }
function get_option($key, $default = false) { return $default; }
function apply_filters($key, $value) { return $value; }
function wp_unslash($value) { return stripslashes((string) $value); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = ''): string { return rtrim($GLOBALS['fixture_home'], '/') . '/' . ltrim($path, '/'); }
function get_query_var($key, $default = null) { return $GLOBALS['fixture_route_var'] ?? $default; }
function rest_url($path): string { return 'https://fixture.test/wp-json/' . $path; }
function wp_timezone(): DateTimeZone { return new DateTimeZone('Asia/Seoul'); }
function esc_url_raw($url): string { return $url; }
function wp_create_nonce($action): string { return 'fixture-nonce'; }
function __($text, $domain = ''): string { return $text; }
function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr_e($text, $domain = ''): void { echo esc_attr($text); }
function esc_html_e($text, $domain = ''): void { echo esc_attr($text); }
function wp_json_encode($data): string { return json_encode($data, JSON_THROW_ON_ERROR); }
function wp_enqueue_script($handle, $url, $deps, $version, $footer): void { $GLOBALS['fixture_enqueued'][$handle] = $url; }
function wp_enqueue_style($handle, $url, $deps, $version): void { $GLOBALS['fixture_styles'][$handle] = $url; }
function wp_localize_script($handle, $name, $data): void { $GLOBALS['fixture_localized'][$name] = $data; }

require_once __DIR__ . '/platform-fixture.php';

use CodingPanda\OjPlatform\Kernel\Module\ModuleId;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteMatcher;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteArea;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRouteTransport;
use FocusTracker\Addon\Context_Resolver;
use FocusTracker\Tracking\Tracked_Page_Enqueuer;

$checks = 0;
function platform_check(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$registry = new DefinitionRegistry('fixture.routes', RouteDefinition::class);
foreach (require platform_fixture_root() . '/config/routes.php' as $route) {
    $registry->register(new RouteDefinition($route['name'], new ModuleId($route['owner_module']), $route['feature_key'], $route['path'], RouteArea::from($route['area']), $route['methods'], AuthenticationRequirement::from($route['authentication']), $route['response_type']));
}
$transport = new WordPressRouteTransport(new RouteMatcher($registry));
class_exists(Tracked_Page_Enqueuer::class);
$path_method = new ReflectionMethod(Tracked_Page_Enqueuer::class, 'current_route_path');
$cases = array(
    array('/', '/problems/123', array(), null, '/problems/123', 123),
    array('/', '/problems/123/', array(), null, '/problems/123', 123),
    array('/', '/submit?problem_id=123', array('problem_id' => '123'), null, '/submit', 123),
    array('/school/', '/school/submit/123', array(), null, '/submit/123', 123),
    array('/school/', '/school/?oj_route=%2Fsubmit&problem_id=123', array('oj_route' => '/submit', 'problem_id' => '123'), null, '/submit', 123),
    array('/school/', '/school/probs', array(), '/problems/123', '/problems/123', 123),
    array('/', '/submissions/123', array(), null, '/submissions/123', null),
    array('/', '/?oj_route=%2Fproblems%2F123%3Fbad', array('oj_route' => '/problems/123?bad'), null, '/_oj_invalid_route_', null),
);
foreach ($cases as [$home, $uri, $query, $route_var, $expected_path, $problem_id]) {
    $GLOBALS['fixture_home'] = 'https://fixture.test' . $home;
    $GLOBALS['fixture_route_var'] = $route_var;
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = $query;
    $actual_path = $transport->requestPath($uri, $query);
    platform_check($actual_path === $expected_path, 'Platform route expectation: ' . $uri);
    platform_check($path_method->invoke(null) === $actual_path, 'Tracker must normalize the actual platform route: ' . $uri);
    $config = Tracked_Page_Enqueuer::config(1);
    if (null !== $problem_id) {
        platform_check(($config['problemId'] ?? null) === $problem_id, 'Problem context must survive every supported route transport: ' . $uri);
    } else {
        platform_check(array() === $config, 'Untracked/invalid platform route must not collect: ' . $uri);
    }
}

$GLOBALS['fixture_home'] = 'https://fixture.test/';
$GLOBALS['fixture_route_var'] = null;
$_GET = array();
$_SERVER['REQUEST_URI'] = '/problems/123';
$manifest = platform_fixture_manifest();
platform_check(count($manifest->siteAssets()) === 1, 'Manifest must register the platform document extension.');
platform_check($manifest->requiresAddonApi() === '^1.4', 'Unsupported Addon APIs must be rejected explicitly.');
$core = new class implements \CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshotProvider {
    public function snapshot(): \CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshot {
        return new \CodingPanda\OjPlatform\Kernel\Addon\CoreContributionSnapshot(array(), array(), array(), array('classes'), array('classes'), array(), array(), array());
    }
};
$ledger = new class implements \CodingPanda\OjPlatform\Kernel\Addon\AddonNamespaceLedger {
    public function ownerOf(string $namespace): string { return ''; }
    public function bind(string $namespace, string $addonId): void {}
};
$candidate = \CodingPanda\OjPlatform\Kernel\Addon\AddonCandidate::manifest('focus-tracker', $manifest);
$validated = (new \CodingPanda\OjPlatform\Kernel\Addon\AddonManifestValidator('2.3.22', '1.4.0', $core, $ledger))->validate(array($candidate));
platform_check($validated[0]->isAccepted(), 'The actual current platform manifest validator must accept all tracking contributions.');
$older = (new \CodingPanda\OjPlatform\Kernel\Addon\AddonManifestValidator('2.3.22', '1.3.0', $core, $ledger))->validate(array($candidate));
platform_check($older[0]->status() === 'incompatible', 'The actual platform validator rejects runtimes without site assets.');

foreach (array('code', 'completion', 'blockly') as $mode) {
    $html = platform_fixture_document($mode);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    $config_node = $dom->getElementById('focus-tracker-config');
    platform_check(null !== $config_node, 'Real HtmlDocumentRenderer must include tracker config: ' . $mode);
    $config = json_decode($config_node->textContent, true, 512, JSON_THROW_ON_ERROR);
    platform_check($config['userId'] === 1 && $config['problemId'] === 123 && $config['serverNowMs'] > 0, 'Platform config identifies the actor, problem and server time.');
    platform_check($xpath->query('//script[contains(@src,"/__focus/resources/js/tracker.js")]')->length === 1, 'Actual platform asset renderer must print exactly one tracker script.');
    platform_check($xpath->query('//form[@data-oj-submission-form and @action="/wp-admin/admin-post.php"]')->length === 1, 'Actual form renderer supplies the supported submission selector.');
    if ('completion' === $mode) {
        platform_check($xpath->query('//textarea[starts-with(@name,"completion_answers[")] | //input[starts-with(@name,"completion_answers[")]')->length === 2, 'Completion fixture uses real platform blank markup.');
    } else {
        platform_check($xpath->query('//textarea[@name="source" and @data-oj-source-editor]')->length === 1, 'Actual Ace fallback emits source input events.');
        platform_check($xpath->query('//*[@data-oj-blockly-editor]')->length === 1, 'Actual Blockly extension is available in the renderer.');
    }
}

Tracked_Page_Enqueuer::maybe_enqueue();
platform_check(isset($GLOBALS['fixture_enqueued']['focus-tracker-tracker']), 'Theme-owned WordPress pages retain an enqueue fallback.');
platform_check(($GLOBALS['fixture_localized']['FocusTrackerConfig']['userId'] ?? null) === 1, 'Legacy fallback has the same per-user configuration.');
$GLOBALS['fixture_can_participate'] = false;
platform_check(array() === Tracked_Page_Enqueuer::config(1), 'Non-participating users have no tracking config.');
platform_check(! str_contains(platform_fixture_document(), 'id="focus-tracker-config"'), 'Platform renderer excludes non-participating actors.');
$GLOBALS['fixture_can_participate'] = true;
platform_check(array() === Tracked_Page_Enqueuer::config(2), 'Never emit one actor nonce/config for another actor.');
foreach (array('dashboard', 'self') as $kind) {
    $_SERVER['REQUEST_URI'] = '/__test/' . $kind;
    $document = platform_fixture_addon_document($kind);
    platform_check(str_contains($document, 'data-ftk-' . ('self' === $kind ? 'self-view' : 'dashboard')), 'Actual addon page handler renders its document: ' . $kind);
    platform_check(str_contains($document, '/__focus/resources/js/' . ('self' === $kind ? 'self-view' : 'dashboard') . '.js'), 'Actual addon asset bundle is rendered: ' . $kind);
    platform_check(! str_contains($document, 'id="focus-tracker-config"'), 'Report pages never start a problem-tracking session.');
}

$gateway = platform_fixture_connect_runtime(125, range(1, 205));
platform_check(count(Context_Resolver::list_teaching_contexts(1)) === 125 && $gateway->teachingCalls === 2, 'Teachers can select contexts beyond the first page.');
platform_check(count(Context_Resolver::list_learning_contexts(1)) === 125 && $gateway->learningCalls === 2, 'Learner activity matching receives every context.');
platform_check(count(Context_Resolver::list_all_learner_ids(1, 'class', 'class:1', 'revision-1')) === 205, 'Roster pagination preserves all learners.');
$gateway->repeatCursor = true;
platform_check(array() === Context_Resolver::list_learning_contexts(1), 'Repeated cursors fail closed instead of looping forever.');
$gateway->repeatCursor = false;
$gateway->failLaterPage = true;
platform_check(array() === Context_Resolver::list_teaching_contexts(1), 'A failed later page cannot be mistaken for a complete context list.');

echo 'Platform integration: ', $checks, ' checks passed.', PHP_EOL;
