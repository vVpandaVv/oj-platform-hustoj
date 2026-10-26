<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Kernel/Setup/WordPress/ServerInstallScreen.php';
require_once dirname(__DIR__, 2) . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register(dirname(__DIR__, 2));

function serverAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

define('ABSPATH', '/home/judge/src/web/');
define('OJ_PLATFORM_PATH', dirname(__DIR__, 2) . '/');
$catalog = dirname(__DIR__, 4) . '/_notes/tmp/server-catalog-' . bin2hex(random_bytes(4));
define('WP_PLUGIN_DIR', $catalog);
foreach (['oj-ai-dashboard', 'oj-ml-playgrounds', 'readcontents-library'] as $plugin) {
    mkdir($catalog . '/' . $plugin . '/resources/server', 0700, true);
    file_put_contents($catalog . '/' . $plugin . '/resources/server/install.sh', '# fixture');
}
$allowed = true;
function current_user_can(string $cap): bool { return $GLOBALS['allowed'] && $cap === 'install_plugins'; }
function wp_die(string $message): never { throw new RuntimeException($message); }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html(string $value): string { return esc_attr($value); }
function esc_textarea(string $value): string { return esc_attr($value); }
$_GET['task'] = 'ml';
ob_start();
\CodingPanda\OjPlatform\Kernel\Setup\WordPress\ServerInstallScreen::render();
$html = (string) ob_get_clean();
serverAssert(str_contains($html, 'id="server-ml"'), 'ML preactivation preparation missing');
serverAssert(str_contains($html, 'sudo bash ') && str_contains($html, 'resources/server/install.sh'), 'Command missing');
serverAssert(str_contains($html, '--apply'), 'Explicit apply missing');
serverAssert(str_contains($html, 'data-ojp-copy-target="server-command-ml"'), 'Copy action missing');
serverAssert(substr_count($html, '<details open>') === 1, 'Only selected task should expand');
serverAssert(str_contains($html, 'oj-ml-playgrounds/resources/server/install.sh'), 'ML command must use its own installer');
serverAssert(str_contains($html, 'oj-ai-dashboard/resources/server/install.sh'), 'AI command must use its own installer');
$GLOBALS['activePlugins'] = [];
function get_option(string $key, $default = []) { return $key === 'active_plugins' ? ($GLOBALS['activePlugins'] ?? []) : $default; }
function home_url(string $path): string { return '/site' . $path; }
$pending = \CodingPanda\OjPlatform\Kernel\Setup\WordPress\ServerInstallScreen::pending();
$mlPending = array_values(array_filter($pending, static fn ($item) => $item['id'] === 'oj-ml-playgrounds'));
serverAssert(count($mlPending) === 1 && str_contains($mlPending[0]['url'], 'admin.php?page='), 'Inactive unprepared plugin must have a recovery entry');
$GLOBALS['activePlugins'] = ['oj-ml-playgrounds/oj-ml-playgrounds.php'];
$pending = \CodingPanda\OjPlatform\Kernel\Setup\WordPress\ServerInstallScreen::pending();
$mlPending = array_values(array_filter($pending, static fn ($item) => $item['id'] === 'oj-ml-playgrounds'));
serverAssert($mlPending[0]['url'] === '/site/oj-console/addons/oj-ml-playgrounds/settings', 'Active plugin notice must point to its console settings');
mkdir($catalog . '/private-storage');
define('OJ_ML_PLAYGROUNDS_PRIVATE_DIR', $catalog . '/private-storage');
$pending = \CodingPanda\OjPlatform\Kernel\Setup\WordPress\ServerInstallScreen::pending();
serverAssert(count(array_filter($pending, static fn ($item) => $item['id'] === 'oj-ml-playgrounds')) === 0, 'Prepared storage must remove the notice');
rmdir($catalog . '/private-storage');
function user_can(int $userId, string $cap): bool { return $GLOBALS['allowed'] && $userId === 1 && $cap === 'install_plugins'; }
$context = new \CodingPanda\OjPlatform\Modules\AdminConsole\Domain\DashboardContext(
    new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity(1, 'admin', '', '관리자'), new DateTimeImmutable());
$provider = new \CodingPanda\OjPlatform\Kernel\Integration\Home\ServerPreparationDashboardProvider(
    new class implements \CodingPanda\OjPlatform\Modules\Permission\Contract\CapabilityChecker {
        public function userCan(int $id, string $cap): bool { return user_can($id, $cap); }
        public function isSuperAdmin(int $id): bool { return false; }
    }
);
serverAssert($provider->load($context) !== null, 'Console must show unresolved server preparation');
$allowed = false;
serverAssert($provider->load($context) === null, 'Console server preparation must be administrator-only');
$allowed = true;
$hooks = [];
function add_action($name, $callback): void { $GLOBALS['hooks'][$name] = $callback; }
function add_filter($name, $callback): void { $GLOBALS['hooks'][$name] = $callback; }
function add_submenu_page($parent, ...$rest): void { $GLOBALS['menuParent'] = $parent; }
define('OJ_PLATFORM_FILE', OJ_PLATFORM_PATH . 'oj-platform.php');
define('OJ_PLATFORM_VERSION', 'test');
function plugins_url(string $path, string $file): string { return '/plugins/oj-platform/' . $path; }
function wp_enqueue_style(string $handle, string $url, array $deps, string $version): void { $GLOBALS['styles'][$handle] = $url; }
function wp_enqueue_script(string $handle, string $url, array $deps, string $version, bool $footer): void {}
\CodingPanda\OjPlatform\Kernel\Setup\WordPress\ServerInstallScreen::register();
$GLOBALS['styles'] = [];
$hooks['admin_enqueue_scripts']('plugins.php');
serverAssert(($GLOBALS['styles']['oj-platform-plugin-list'] ?? null) === '/plugins/oj-platform/resources/admin/plugin-list.css', 'Plugin list must load the scoped dependency display stylesheet');
$GLOBALS['styles'] = [];
$hooks['admin_enqueue_scripts']('index.php');
serverAssert($GLOBALS['styles'] === [], 'Other admin screens must not load the plugin list stylesheet');
$hooks['admin_enqueue_scripts']('admin_page_oj-server-install');
serverAssert(isset($GLOBALS['styles']['oj-server-install']) && !isset($GLOBALS['styles']['oj-platform-plugin-list']), 'Server installation assets must remain separate');
$hooks['admin_menu']();
serverAssert(array_key_exists('menuParent', $GLOBALS) && $GLOBALS['menuParent'] === '', 'Recovery page must not create a permanent admin menu');
unlink($catalog . '/oj-ml-playgrounds/resources/server/install.sh');
ob_start();
\CodingPanda\OjPlatform\Kernel\Setup\WordPress\ServerInstallScreen::render();
$withoutMl = (string) ob_get_clean();
serverAssert(!str_contains($withoutMl, 'id="server-ml"'), 'Uninstalled ML must not appear');
foreach (['oj-ai-dashboard', 'oj-ml-playgrounds', 'readcontents-library'] as $plugin) {
    $path = $catalog . '/' . $plugin . '/resources/server';
    if (is_file($path . '/install.sh')) { unlink($path . '/install.sh'); }
    rmdir($path); rmdir(dirname($path)); rmdir(dirname($path, 2));
}
rmdir($catalog);
$allowed = false;
$rejected = false;
try {
    \CodingPanda\OjPlatform\Kernel\Setup\WordPress\ServerInstallScreen::render();
} catch (RuntimeException) {
    $rejected = true;
}
serverAssert($rejected, 'Unauthorized user must not get commands');
function admin_url(string $path): string { return '/wp-admin/' . $path; }
$installLink = new \CodingPanda\OjPlatform\Modules\Settings\Infrastructure\WordPress\WordPressServerInstallationLink();
serverAssert($installLink->url() === null, 'Operations settings must hide the install link without installation permission');
$allowed = true;
serverAssert($installLink->url() === '/wp-admin/admin.php?page=oj-server-install', 'Authorized install link must use the WordPress adapter');
fwrite(STDOUT, "Server preparation: config safety, rerun, commands and access passed.\n");
