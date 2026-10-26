<?php
declare(strict_types=1);

/*
 * Console menu and settings capability shape (settings in the teacher menu).
 * Loads the real Addon API v1 value objects from the platform source tree
 * (OJ_PLATFORM_PATH, default ../../oj-integration-rebuild/oj-platform) and
 * records the manifest with two fake builders: one for platform 2.1+ (local
 * navigation) and one for 2.0.x (no local navigation).
 *
 * Run: php tests/console-menu.php
 */

namespace {
    error_reporting(E_ALL);
    set_error_handler(static function ($severity, $message, $file, $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    $platform = getenv('OJ_PLATFORM_PATH') ?: dirname(__DIR__, 2) . '/oj-integration-rebuild/oj-platform';
    $apiRoot = rtrim($platform, '/\\') . '/src/';
    if (! is_dir($apiRoot . 'AddonApi/V1')) {
        fwrite(STDERR, "Addon API v1 source not found under $apiRoot (set OJ_PLATFORM_PATH).\n");
        exit(2);
    }
    spl_autoload_register(static function (string $class) use ($apiRoot): void {
        $prefix = 'CodingPanda\\OjPlatform\\';
        if (str_starts_with($class, $prefix)) {
            $file = $apiRoot . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; }
        }
    });

    define('OJ_CODE_LAB_PATH', dirname(__DIR__) . '/');
    define('OJ_CODE_LAB_FILE', dirname(__DIR__) . '/oj-code-lab.php');
    define('OJ_CODE_LAB_VERSION', (require dirname(__DIR__) . '/plugin-package.php')['version']);
    function __(string $s, string $domain = ''): string { return $s; }

    require OJ_CODE_LAB_PATH . 'src/Core/Autoloader.php';
    \OjCodeLab\Core\Autoloader::register();
}

namespace OjCodeLabConsoleMenuTest {
    use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonAssetBundleDefinitionV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityDefinitionV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavBuilderV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleLocalNavItemV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonConsoleMenuDefinitionV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonConsolePageDefinitionV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonManifestBuilderV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicMenuDefinitionV1;
    use CodingPanda\OjPlatform\AddonApi\V1\AddonPublicPageDefinitionV1;

    class LegacyBuilder implements AddonManifestBuilderV1 {
        /** @var array<string, AddonCapabilityDefinitionV1> */
        public array $capabilities = array();
        /** @var array<string, AddonConsolePageDefinitionV1> */
        public array $pages = array();
        /** @var array<string, AddonConsoleMenuDefinitionV1> */
        public array $menus = array();
        /** @var list<AddonConsoleLocalNavItemV1> */
        public array $localNav = array();
        /** @var array<string, AddonAssetBundleDefinitionV1> */
        public array $assets = array();

        public function onReady(callable $callback): void {}
        public function manifest(string $namespace, string $version, string $pluginFile, string $requiresPlatform, string $requiresAddonApi, string $diagnosticLabel = '', string $supportReference = ''): void {}
        public function capability(AddonCapabilityDefinitionV1 $definition): void { $this->capabilities[$definition->key()] = $definition; }
        public function consolePage(AddonConsolePageDefinitionV1 $definition): void { $this->pages[$definition->key()] = $definition; }
        public function publicPage(AddonPublicPageDefinitionV1 $definition): void {}
        public function consoleMenu(AddonConsoleMenuDefinitionV1 $definition): void { $this->menus[$definition->key()] = $definition; }
        public function publicMenu(AddonPublicMenuDefinitionV1 $definition): void {}
        public function assetBundle(AddonAssetBundleDefinitionV1 $definition): void { $this->assets[$definition->key()] = $definition; }
        public function aiTask(AddonAiTaskDefinitionV1 $definition): void {}
    }

    final class LocalNavBuilder extends LegacyBuilder implements AddonConsoleLocalNavBuilderV1 {
        public function consoleLocalNav(AddonConsoleLocalNavItemV1 $definition): void { $this->localNav[] = $definition; }
    }
}

namespace {
    use OjCodeLab\Addon\Oj_Platform_Registration;
    use OjCodeLab\Support\Capabilities;
    use OjCodeLabConsoleMenuTest\LegacyBuilder;
    use OjCodeLabConsoleMenuTest\LocalNavBuilder;

    $assertions = 0;
    $expect = static function (bool $ok, string $message) use (&$assertions): void {
        $assertions++;
        if (! $ok) { throw new \RuntimeException($message); }
    };

    // Platform 2.1+: one sidebar menu, settings is its last local segment.
    $modern = new LocalNavBuilder();
    Oj_Platform_Registration::manifest($modern);

    foreach (array('lab', 'console_dashboard') as $surface) {
        $bundle = $modern->assets['addon.oj_code_lab.' . $surface . '_assets'];
        $scripts = $bundle->scripts();
        $expect(array('resources/vendor/markdown-it/markdown-it.js', 'resources/js/markdown.js') === array_slice($scripts, 0, 2), 'Markdown parser must load before its adapter and page script: ' . $surface);
        $expect(in_array('resources/css/markdown.css', $bundle->styles(), true), 'Markdown styles required: ' . $surface);
        foreach (array_merge($scripts, $bundle->styles()) as $asset) {
            $expect(is_file(OJ_CODE_LAB_PATH . $asset), 'Registered asset does not exist: ' . $asset);
        }
    }
    $expect(in_array('resources/js/text-cell.js', $modern->assets['addon.oj_code_lab.lab_assets']->scripts(), true), 'Student Markdown editor required');
    $expect(! in_array('resources/js/text-cell.js', $modern->assets['addon.oj_code_lab.console_dashboard_assets']->scripts(), true), 'Teacher view must not load editable text cells');

    $settingsCap = $modern->capabilities[Capabilities::SETTINGS] ?? null;
    $expect(Capabilities::SETTINGS === 'oj_code_lab_settings', 'Settings capability key changed');
    $expect(null !== $settingsCap, 'Settings capability missing');
    $expect('educator' === $settingsCap->audience(), 'Settings capability must be grantable to teachers (educator)');
    $expect('코드 실습실 설정' === $settingsCap->label(), 'Settings capability label');

    $settingsPage = $modern->pages['addon.oj_code_lab.settings'] ?? null;
    $expect(null !== $settingsPage && '/oj-console/addons/oj-code-lab/settings' === $settingsPage->path(), 'Settings page path changed');
    $expect(Capabilities::SETTINGS === $settingsPage->capability(), 'Settings page must require the settings capability');

    $expect(array('addon.oj_code_lab.dashboard_menu') === array_keys($modern->menus), 'Platform 2.1+ must register only the 코드 실습실 sidebar menu');
    $dashboardMenu = $modern->menus['addon.oj_code_lab.dashboard_menu'];
    $expect('learning_support' === $dashboardMenu->anchor() && '코드 실습실' === $dashboardMenu->label(), 'Sidebar menu anchor/label');
    foreach ($modern->menus as $menu) {
        $expect('operations' !== $menu->anchor(), 'No console menu may stay under operations');
    }

    $nav = $modern->localNav;
    usort($nav, static fn ($a, $b): int => $a->order() <=> $b->order());
    $segments = array_map(static fn ($item): array => array($item->label(), $item->route(), $item->menu()), $nav);
    $expect(array(
        array('학생 노트북', 'addon.oj_code_lab.dashboard', 'addon.oj_code_lab.dashboard_menu'),
        array('추천 데이터', 'addon.oj_code_lab.datasets', 'addon.oj_code_lab.dashboard_menu'),
        array('설정', 'addon.oj_code_lab.settings', 'addon.oj_code_lab.dashboard_menu'),
    ) === $segments, 'Local navigation must be 학생 노트북 / 추천 데이터 / 설정 (settings last)');
    foreach ($nav as $item) {
        $expect(isset($modern->pages[$item->route()]), 'Local segment points at an unknown page: ' . $item->route());
    }

    // Platform 2.0.x: no local navigation, so 추천 데이터 and settings keep
    // their own menus under the same teacher anchor (never operations).
    $legacy = new LegacyBuilder();
    Oj_Platform_Registration::manifest($legacy);
    $expect(array() === $legacy->localNav, 'Legacy builder must not receive local navigation');
    $expect(array('addon.oj_code_lab.dashboard_menu', 'addon.oj_code_lab.datasets_menu', 'addon.oj_code_lab.settings_menu') === array_keys($legacy->menus), 'Platform 2.0.x menus');
    $legacyDatasets = $legacy->menus['addon.oj_code_lab.datasets_menu'];
    $expect('learning_support' === $legacyDatasets->anchor() && '코드 실습실 추천 데이터' === $legacyDatasets->label(), 'Legacy 추천 데이터 menu anchor/label');
    $expect('addon.oj_code_lab.datasets' === $legacyDatasets->route() && Capabilities::MANAGE_DATASETS === $legacyDatasets->capability(), 'Legacy 추천 데이터 menu route/capability');
    $legacyOrders = array_map(static fn ($menu): int => $menu->order(), $legacy->menus);
    $expect(max($legacyOrders) === $legacy->menus['addon.oj_code_lab.settings_menu']->order() && count(array_unique($legacyOrders)) === count($legacyOrders), 'Legacy settings menu must come last with distinct orders');
    $legacyMenu = $legacy->menus['addon.oj_code_lab.settings_menu'];
    $expect('learning_support' === $legacyMenu->anchor(), 'Legacy settings menu must sit under learning_support');
    $expect('코드 실습실 설정' === $legacyMenu->label(), 'Legacy settings menu label');
    $expect('addon.oj_code_lab.settings' === $legacyMenu->route() && Capabilities::SETTINGS === $legacyMenu->capability(), 'Legacy settings menu route/capability');

    // No wp-admin menu registration anywhere in the plugin source.
    $sources = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(OJ_CODE_LAB_PATH . 'src', \FilesystemIterator::SKIP_DOTS));
    foreach ($sources as $file) {
        if ('php' !== $file->getExtension()) { continue; }
        $code = (string) file_get_contents($file->getPathname());
        $expect(! preg_match('/\badd_(menu|submenu|options|management)_page\s*\(/', $code), 'wp-admin menu registration found in ' . $file->getFilename());
        $expect(! str_contains($code, "current_user_can('manage_options')"), 'manage_options-only check found in ' . $file->getFilename());
    }

    echo json_encode(array('suite' => 'console-menu', 'assertions' => $assertions, 'passed' => true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
}
