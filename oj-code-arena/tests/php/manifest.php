<?php
/**
 * Runs Oj_Platform_Registration::manifest() against OJ Platform's real
 * Addon API v1 definition classes (their validators run in constructors)
 * and a recording builder, then checks every page handler loads.
 *
 *   php tests/php/manifest.php <path to oj-platform>
 */
declare(strict_types=1);

$platform = rtrim($argv[1] ?? '', '/\\');
if (! is_dir($platform . '/src/AddonApi/V1')) {
    fwrite(STDERR, "usage: php tests/php/manifest.php <oj-platform dir>\n");
    exit(2);
}

define('ABSPATH', __DIR__);
define('OJ_CODE_ARENA_PATH', dirname(__DIR__, 2) . '/');
define('OJ_CODE_ARENA_FILE', OJ_CODE_ARENA_PATH . 'oj-code-arena.php');
preg_match('/^ \* Version: (.+)$/m', (string) file_get_contents(OJ_CODE_ARENA_FILE), $version);
define('OJ_CODE_ARENA_VERSION', trim($version[1]));
function __($s, $d = '') { return $s; }

spl_autoload_register(static function (string $class) use ($platform): void {
    $prefix = 'CodingPanda\\OjPlatform\\';
    if (str_starts_with($class, $prefix)) {
        $file = $platform . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }
});
require OJ_CODE_ARENA_PATH . 'src/Core/Autoloader.php';
\OjCodeArena\Core\Autoloader::register();

use CodingPanda\OjPlatform\AddonApi\V1 as A;

class Recorder implements A\AddonManifestBuilderV1 {
    public array $calls = array();
    public function onReady(callable $callback): void { $this->calls[] = array('onReady'); }
    public function manifest(string $namespace, string $version, string $pluginFile, string $requiresPlatform, string $requiresAddonApi, string $diagnosticLabel, string $supportReference): void { $this->calls[] = array('manifest', $namespace); }
    public function capability(A\AddonCapabilityDefinitionV1 $d): void { $this->calls[] = array('capability', $d->key(), $d->label(), $d->audience()); }
    public function consolePage(A\AddonConsolePageDefinitionV1 $d): void { $this->calls[] = array('consolePage', $d->path(), $d->assetBundles(), $d->capability()); }
    public function publicPage(A\AddonPublicPageDefinitionV1 $d): void { $this->calls[] = array('publicPage', $d->path(), $d->assetBundles()); }
    public function consoleMenu(A\AddonConsoleMenuDefinitionV1 $d): void { $this->calls[] = array('consoleMenu', $d->label(), $d->anchor(), $d->route(), $d->capability()); }
    public function publicMenu(A\AddonPublicMenuDefinitionV1 $d): void { $this->calls[] = array('publicMenu', $d->label()); }
    public function assetBundle(A\AddonAssetBundleDefinitionV1 $d): void {
        foreach (array_merge($d->styles(), $d->scripts()) as $f) {
            if (! is_file(OJ_CODE_ARENA_PATH . $f)) {
                throw new RuntimeException('missing asset ' . $f);
            }
        }
        $this->calls[] = array('assetBundle', $d->key(), count($d->scripts()));
    }
    public function aiTask(A\AddonAiTaskDefinitionV1 $d): void {}
}

// Platform 2.1+ builder: also takes local navigation segments.
final class NavRecorder extends Recorder implements A\AddonConsoleLocalNavBuilderV1 {
    public function consoleLocalNav(A\AddonConsoleLocalNavItemV1 $d): void { $this->calls[] = array('consoleLocalNav', $d->label(), $d->menu(), $d->route(), $d->order()); }
}

$builder = new Recorder();
$nav = new NavRecorder();
try {
    \OjCodeArena\Addon\Oj_Platform_Registration::manifest($builder);
    \OjCodeArena\Addon\Oj_Platform_Registration::manifest($nav);
} catch (Throwable $e) {
    echo 'FAIL manifest: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}
foreach ($nav->calls as $c) {
    echo implode(' ', array_map(static fn ($v) => is_array($v) ? '[' . implode(',', $v) . ']' : (string) $v, $c)) . "\n";
}

$fails = 0;
function check(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $fails += $ok ? 0 : 1;
}
$of = static fn (Recorder $r, string $type): array => array_values(array_filter($r->calls, static fn (array $c): bool => $type === $c[0]));
$settingsPath = '/oj-console/addons/oj-code-arena/settings';

$settingsCap = array_values(array_filter($of($nav, 'capability'), static fn (array $c): bool => 'oj_code_arena_settings' === $c[1]));
check('settings capability "코드 아레나 설정" for educators', 1 === count($settingsCap) && '코드 아레나 설정' === $settingsCap[0][2] && 'educator' === $settingsCap[0][3]);
$settingsPage = array_values(array_filter($of($nav, 'consolePage'), static fn (array $c): bool => $settingsPath === $c[1]));
check('console settings page requires oj_code_arena_settings', 1 === count($settingsPage) && 'oj_code_arena_settings' === $settingsPage[0][3]);
$otherPages = array_filter($of($nav, 'consolePage'), static fn (array $c): bool => $settingsPath !== $c[1]);
check('other console pages keep oj_code_arena_teach', 4 === count($otherPages) && array() === array_filter($otherPages, static fn (array $c): bool => 'oj_code_arena_teach' !== $c[3]));
check('no menu under operations', array() === array_filter(array_merge($of($nav, 'consoleMenu'), $of($builder, 'consoleMenu')), static fn (array $c): bool => 'operations' === $c[2]));
check('2.1+: one console menu (코드 아레나)', array(array('consoleMenu', '코드 아레나', 'learning_support', 'addon.oj_code_arena.teacher', 'oj_code_arena_teach')) === $of($nav, 'consoleMenu'));
$segments = $of($nav, 'consoleLocalNav');
usort($segments, static fn (array $a, array $b): int => $a[4] <=> $b[4]);
check('2.1+: local nav 수업 게임 / 문제은행 / 경기 기록 / 설정', array('수업 게임', '문제은행', '경기 기록', '설정') === array_column($segments, 1)
    && 'addon.oj_code_arena.settings' === $segments[3][3]);
check('2.0.x: separate menu "코드 아레나 설정" under learning_support', 2 === count($of($builder, 'consoleMenu'))
    && in_array(array('consoleMenu', '코드 아레나 설정', 'learning_support', 'addon.oj_code_arena.settings', 'oj_code_arena_settings'), $of($builder, 'consoleMenu'), true));

// The wp-admin options page is gone; the save and .env handlers stay.
$admin = (string) file_get_contents(OJ_CODE_ARENA_PATH . 'src/Admin/Settings_Page.php');
check('no wp-admin menu registration', 0 === preg_match('/add_(options|menu|submenu|management)_page|admin_menu/', $admin));
$handlers = substr($admin, (int) strpos($admin, 'function save'));
check('save and .env handlers check the settings capability and nonces', 2 === substr_count($handlers, 'Capabilities::can_manage_settings()')
    && false === strpos($handlers, 'manage_options')
    && false !== strpos($handlers, "check_admin_referer('oj_code_arena_save')") && false !== strpos($handlers, "check_admin_referer('oj_code_arena_env')"));
foreach (array('Admin_Controller', 'Loadtest_Controller') as $ctl) {
    $src = (string) file_get_contents(OJ_CODE_ARENA_PATH . 'src/Rest/Controllers/' . $ctl . '.php');
    check($ctl . ' permission uses the settings capability', false !== strpos($src, 'Capabilities::can_manage_settings()') && false === strpos($src, 'manage_options'));
}
$caps = (string) file_get_contents(OJ_CODE_ARENA_PATH . 'src/Support/Capabilities.php');
check('can_manage_settings = is_super_admin or the settings capability', false !== strpos($caps, 'is_super_admin() || self::has(self::SETTINGS)'));

$classes = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(OJ_CODE_ARENA_PATH . 'src')) as $file) {
    if ('php' === $file->getExtension()) {
        $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', OJ_CODE_ARENA_PATH . 'src/')), -4);
        $classes[] = 'OjCodeArena\\' . str_replace('/', '\\', $rel);
    }
}
// Edu_Games_Card implements an Edu Games interface and only loads when that plugin is active.
$classes = array_values(array_filter($classes, static fn (string $c): bool => 'OjCodeArena\Integration\Edu_Games_Card' !== $c));
$missing = array_filter($classes, static fn (string $c): bool => ! class_exists($c) && ! interface_exists($c));
echo $missing ? 'FAIL classes: ' . implode(', ', $missing) . "\n" : 'ok   all ' . count($classes) . " classes load\n";
exit($missing || $fails ? 1 : 0);
