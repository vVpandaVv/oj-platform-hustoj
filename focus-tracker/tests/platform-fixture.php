<?php
declare(strict_types=1);

/**
 * Test-only bridge to the checked-in OJ Platform. The production document,
 * asset renderer, submission form and Ace/Blockly runtimes render the UI;
 * only WordPress infrastructure and the context ledger are fixtures.
 */

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use CodingPanda\OjPlatform\Kernel\Addon as Addon;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract as Access;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferences;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyProfileCatalog;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklySettingsProvider;
use CodingPanda\OjPlatform\Modules\Blockly\Infrastructure\WordPress\WordPressBlocklyAssets;
use CodingPanda\OjPlatform\Modules\Blockly\Presentation\BlocklyEditorExtension;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Settings\Contract as Setting;
use CodingPanda\OjPlatform\Modules\Submissions\Application as Submission;
use CodingPanda\OjPlatform\Modules\Submissions\Infrastructure\WordPress\WordPressAceEditorAssets;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;
use CodingPanda\OjPlatform\Ui\Infrastructure\WordPress\WordPressUiAssetLocator;
use CodingPanda\OjPlatform\Ui\View\HtmlDocumentRenderer;

function platform_fixture_root(): string {
    return dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform';
}

require_once platform_fixture_root() . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register(platform_fixture_root());
require_once __DIR__ . '/check-context-fixture.php';

if (! function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string {
        $prefix = str_contains(str_replace('\\', '/', $plugin), '/focus-tracker/') ? '/__focus/' : '/__platform/';
        return $prefix . ltrim($path, '/');
    }
}
if (! function_exists('wp_register_script')) {
    function wp_register_script($handle, $src, $deps = array(), $version = null, $footer = false): void {
        $GLOBALS['platform_fixture_scripts'][$handle] = $src;
    }
}
if (! function_exists('wp_register_style')) {
    function wp_register_style($handle, $src, $deps = array(), $version = null): void {
        $GLOBALS['platform_fixture_styles'][$handle] = $src;
    }
}
if (! function_exists('wp_scripts')) {
    function wp_scripts(): object {
        return new class {
            public function do_items(array $handles): void {
                foreach ($handles as $handle) {
                    echo '<script src="' . htmlspecialchars($GLOBALS['platform_fixture_scripts'][$handle], ENT_QUOTES, 'UTF-8') . '"></script>';
                }
            }
        };
    }
}
if (! function_exists('wp_styles')) {
    function wp_styles(): object {
        return new class {
            public function do_items(array $handles): void {
                foreach ($handles as $handle) {
                    echo '<link rel="stylesheet" href="' . htmlspecialchars($GLOBALS['platform_fixture_styles'][$handle], ENT_QUOTES, 'UTF-8') . '">';
                }
            }
        };
    }
}

final class Platform_Fixture_Context_Gateway implements Api\AddonLearningContextGatewayV1, Api\AddonAssignmentRosterGatewayV1 {
    public int $teachingCalls = 0;
    public int $learningCalls = 0;
    public bool $repeatCursor = false;
    public bool $failLaterPage = false;

    public function __construct(private int $count = 1, private array $learners = array(1)) {}

    private function page(string $cursor, int $limit): Api\AddonContextPageV1 {
        if ($this->failLaterPage && '' !== $cursor) {
            return new Api\AddonContextPageV1(false, array(), '', new Api\AddonApiProblemV1('addon_context_unavailable', 'Fixture unavailable', 'fixture-context', true));
        }
        $offset = '' === $cursor ? 0 : (int) $cursor;
        if (isset($GLOBALS['focus_test_statistics_contexts'])) {
            $rows = $GLOBALS['focus_test_statistics_contexts'];
            $slice = array_slice($rows, $offset, $limit);
            $items = array_map(static fn (array $row): Api\AddonContextSummaryV1 => new Api\AddonContextSummaryV1($row['type'], $row['id'], $row['label'], 'active', 'revision-1'), $slice);
            $next = $offset + count($slice) < count($rows) ? (string) ($offset + count($slice)) : '';
            return new Api\AddonContextPageV1(true, $items, $next, null);
        }
        $items = array();
        for ($index = $offset + 1; $index <= min($this->count, $offset + $limit); $index++) {
            $items[] = new Api\AddonContextSummaryV1('class', 'class:' . $index, '수업 ' . $index, 'active', 'revision-1');
        }
        $next = $offset + count($items) < $this->count ? (string) ($offset + count($items)) : '';
        if ($this->repeatCursor && '' !== $cursor) {
            $next = $cursor;
        }
        return new Api\AddonContextPageV1(true, $items, $next, null);
    }

    public function listTeachingContexts(Api\AddonTeachingContextQueryV1 $query): Api\AddonContextPageV1 {
        $this->teachingCalls++;
        return $this->page($query->cursor(), $query->limit());
    }

    public function listLearningContexts(Api\AddonLearningContextQueryV1 $query): Api\AddonContextPageV1 {
        $this->learningCalls++;
        return $this->page($query->cursor(), $query->limit());
    }

    private function context(string $type, string $id): Api\AddonContextSnapshotV1 {
        return new Api\AddonContextSnapshotV1($type, $id, '수업 ' . $id, 'active', 'revision-1', 'manager');
    }

    public function authorize(Api\AddonContextAccessRequestV1 $request): Api\AddonContextAccessDecisionV1 {
        if (! empty($GLOBALS['focus_test_statistics_deny_context'])) {
            return new Api\AddonContextAccessDecisionV1(false, 'fixture_denied', null, 'fixture-context');
        }
        return new Api\AddonContextAccessDecisionV1(true, '', $this->context($request->contextType(), $request->contextId()), 'fixture-context');
    }

    public function snapshot(Api\AddonContextSnapshotRequestV1 $request): Api\AddonContextSnapshotResultV1 {
        return new Api\AddonContextSnapshotResultV1(true, $this->context($request->contextType(), $request->contextId()), null);
    }

    public function listAssignmentLearners(Api\AddonAssignmentRosterQueryV1 $query): Api\AddonAssignmentLearnerPageV1 {
        $offset = '' === $query->cursor() ? 0 : (int) $query->cursor();
        $ids = array_slice($this->learners, $offset, $query->limit());
        $next = $offset + count($ids) < count($this->learners) ? (string) ($offset + count($ids)) : '';
        return new Api\AddonAssignmentLearnerPageV1(true, array_map(static fn (int $id): Api\AddonAssignmentLearnerV1 => new Api\AddonAssignmentLearnerV1($id, 'membership-1'), $ids), $next, 'roster-1', null);
    }
}

function platform_fixture_connect_runtime(int $contexts = 1, array $learners = array(1)): Platform_Fixture_Context_Gateway {
    $gateway = new Platform_Fixture_Context_Gateway($contexts, $learners);
    $runtime = new class($gateway) implements Api\AddonLearningCheckRuntimeContextV1 {
        public function __construct(private Api\AddonContextGatewayV1 $gateway) {}
        public function snapshot(): Api\AddonRuntimeSnapshotV1 {
            return new Api\AddonRuntimeSnapshotV1('focus-tracker', FOCUS_TRACKER_VERSION, '>=2.3.0 <3.0.0', '2.3.22', '^1.4', '1.4.0', 'accepted', '', 'fixture-catalog', array(), 'fixture-runtime');
        }
        public function contexts(): Api\AddonContextGatewayV1 { return $this->gateway; }
        public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('AI is outside this fixture.'); }
        public function learningChecks(): Api\AddonLearningCheckGatewayV1 { return new Platform_Fixture_Learning_Check_Gateway(); }
    };
    \FocusTracker\Addon\Runtime_Bridge::connect($runtime);
    return $gateway;
}

function platform_fixture_manifest(): Addon\AddonManifest {
    $builder = new Addon\AddonManifestBuilder();
    \FocusTracker\Addon\Oj_Platform_Registration::manifest($builder);
    return $builder->close();
}

function platform_fixture_form(string $mode = 'code'): string {
    $settings = new class implements Setting\SettingsManager {
        public function read(string $key, mixed $default = null): Setting\SettingsSnapshot { return new Setting\SettingsSnapshot(1, $default); }
        public function save(string $key, mixed $value, int $expectedRevision): Setting\SettingsSnapshot { throw new LogicException('Fixture settings are read-only.'); }
    };
    $features = new class implements Setting\FeatureFlags {
        public function isEnabled(string $featureKey): bool { return true; }
    };
    $environment = PluginEnvironment::fromPluginFile(platform_fixture_root() . '/oj-platform.php', '2.3.22', '6.6', '8.3');
    $editors = new Submission\SubmissionEditorExtensionRegistry();
    $editors->register(new BlocklyEditorExtension(new BlocklySettingsProvider($settings, $features), new BlocklyProfileCatalog(), new WordPressBlocklyAssets($environment)));
    $urls = new class implements Access\ActionUrlProvider {
        public function actionUrl(): string { return '/wp-admin/admin-post.php'; }
        public function ajaxUrl(): string { return '/wp-admin/admin-ajax.php'; }
    };
    $security = new class implements Access\ActionSecurity {
        public function hiddenFields(string $actionKey): string { return '<input type="hidden" name="action" value="' . htmlspecialchars($actionKey, ENT_QUOTES, 'UTF-8') . '">'; }
        public function verify(string $actionKey, Access\HttpRequest $request): bool { return true; }
    };
    // The form only calls stateless escape(); query/report services are unused.
    $html = (new ReflectionClass(SubmissionHtml::class))->newInstanceWithoutConstructor();
    $languages = new Submission\SubmissionLanguageCatalog();
    $renderer = new Submission\SubmissionFormRenderer(new Submission\SubmissionSettingsProvider($settings), $languages, new WordPressAceEditorAssets($environment, $languages), $editors, $urls, $security, $html);
    $completion = 'completion' === $mode;
    $schema = json_encode(array('language_key' => 'c', 'blanks' => array(array('id' => 'answer', 'label' => '정답', 'kind' => 'block', 'max_bytes' => 1000), array('id' => 'expression', 'label' => '표현식', 'kind' => 'token', 'max_bytes' => 100))), JSON_UNESCAPED_UNICODE);
    $policy = new SubmissionCodePolicy(123, '집중도 통합 검사', $completion ? 'code_completion' : 'full_code', array(), '', $completion ? 'int main() { {{OJ_BLANK:answer}} return {{OJ_BLANK:expression}}; }' : '', '', null, $completion ? $schema : null, $completion ? str_repeat('a', 64) : null, 'problem-code-policy-v1:' . str_repeat('a', 64));
    $preferences = new UserSubmissionPreferences(defaultEditor: 'blockly' === $mode ? 'blockly' : 'code');
    $group_id = max(0, (int) ($_GET['group_id'] ?? 0));
    $class_id = array_key_exists('class_id', $_GET) ? max(0, (int) $_GET['class_id']) : ($group_id > 0 ? 0 : 1);
    return $renderer->render(123, $policy, null, $preferences, array('contest_id' => 0, 'contest_problem_number' => 0, 'class_id' => $class_id > 0 ? $class_id : null, 'group_id' => $group_id > 0 ? $group_id : null));
}

function platform_fixture_renderer(): HtmlDocumentRenderer {
    $catalog = new Addon\AcceptedAddonCatalog();
    $catalog->commitAndFreeze(array(new Addon\AddonCatalogEntry('focus-tracker', platform_fixture_manifest(), 'accepted', '')));
    $extensions = new Addon\AddonDocumentExtensions(new Addon\WordPressAddonAssetRenderer());
    $extensions->replace($catalog);
    $assets = new WordPressUiAssetLocator(PluginEnvironment::fromPluginFile(platform_fixture_root() . '/oj-platform.php', '2.3.22', '6.6', '8.3'));
    return new HtmlDocumentRenderer($assets, 'OJ Platform fixture', $extensions);
}

function platform_fixture_document(string $mode = 'code'): string {
    return platform_fixture_renderer()->render('Focus Tracker integration', platform_fixture_form($mode), 'ojp-app', get_current_user_id());
}

function platform_fixture_addon_document(string $kind): string {
    $self = 'self' === $kind;
    $settings = 'settings' === $kind;
    $request = new Api\AddonPageRequestV1($self ? 'addon.focus_tracker.me' : ($settings ? 'addon.focus_tracker.settings' : 'addon.focus_tracker.dashboard'), get_current_user_id(), array(), array(), 'ko_KR', 'fixture-page');
    $handler = $self ? new \FocusTracker\Addon\Self_View_Page_Handler() : ($settings ? new \FocusTracker\Addon\Console_Settings_Page_Handler() : new \FocusTracker\Addon\Console_Dashboard_Page_Handler());
    $response = $handler->handle($request);
    $manifest = platform_fixture_manifest();
    $keys = array($self ? 'addon.focus_tracker.self_view_assets' : ($settings ? 'addon.focus_tracker.settings_assets' : 'addon.focus_tracker.console_assets'));
    $assets = new Addon\WordPressAddonAssetRenderer();
    $body = $assets->renderStyles($manifest->pluginFile(), $manifest->assetBundles(), $keys)
        . $response->body()
        . $assets->renderScripts($manifest->pluginFile(), $manifest->assetBundles(), $keys);
    return platform_fixture_renderer()->render((string) $response->title(), $body, 'ojp-app', get_current_user_id());
}

/** Serve only checked-in resources, never PHP/test files or paths outside them. */
function platform_fixture_asset(string $path): bool {
    foreach (array('/__platform/' => platform_fixture_root(), '/__focus/' => dirname(__DIR__)) as $prefix => $root) {
        if (! str_starts_with($path, $prefix)) {
            continue;
        }
        $resource_root = realpath($root . '/resources');
        $file = realpath($root . '/' . substr($path, strlen($prefix)));
        if (false === $file || false === $resource_root || ! str_starts_with(strtolower($file), strtolower($resource_root . DIRECTORY_SEPARATOR)) || ! is_file($file)) {
            http_response_code(404);
            return true;
        }
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $types = array('js' => 'application/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'gif' => 'image/gif', 'woff2' => 'font/woff2', 'woff' => 'font/woff');
        if (! isset($types[$extension])) {
            http_response_code(404);
            return true;
        }
        header('Content-Type: ' . $types[$extension]);
        readfile($file);
        return true;
    }
    return false;
}
