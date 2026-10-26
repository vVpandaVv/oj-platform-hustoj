<?php
/**
 * Plugin Name: ReadContents Library Addon
 * Plugin URI: https://example.com/contents-editor
 * Description: OJ Platform에서 읽기자료를 제작합니다. 플러그인을 삭제하면 등록된 모든 읽기자료 본문과 목차가 영구 삭제됩니다.
 * Version: 3.3.10
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Contents Editor Team
 * Author URI: https://example.com
 * Text Domain: contents-editor
 * Domain Path: /languages
 *
 * @package ContentsEditor
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('CONTENTS_EDITOR_VERSION', '3.3.10');
define('CONTENTS_EDITOR_MINIMUM_PHP', '8.1');
define('CONTENTS_EDITOR_FILE', __FILE__);
define('CONTENTS_EDITOR_PATH', plugin_dir_path(__FILE__));
define('CONTENTS_EDITOR_URL', plugin_dir_url(__FILE__));
define('CONTENTS_EDITOR_CAPABILITY', 'manage_options');

// The oj-platform addon layer (src/ReadContents/Library/*) was ported in from the
// former readcontents-library plugin and still references these constant names
// directly (manifest version strings, asset URLs, the legacy data/ path). Aliasing
// them here avoids rewriting every call site across the ported files.
define('READCONTENTS_LIBRARY_VERSION', CONTENTS_EDITOR_VERSION);
define('READCONTENTS_LIBRARY_FILE', CONTENTS_EDITOR_FILE);
define('READCONTENTS_LIBRARY_PATH', CONTENTS_EDITOR_PATH);
define('READCONTENTS_LIBRARY_URL', CONTENTS_EDITOR_URL);

if (version_compare(PHP_VERSION, CONTENTS_EDITOR_MINIMUM_PHP, '<')) {
    add_action(
        'admin_notices',
        static function (): void {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: 1: required PHP version, 2: current PHP version. */
                        __('Contents Editor requires PHP %1$s or higher. Current PHP version is %2$s.', 'contents-editor'),
                        CONTENTS_EDITOR_MINIMUM_PHP,
                        PHP_VERSION
                    )
                )
            );
        }
    );

    return;
}

$composer_autoload = CONTENTS_EDITOR_PATH . 'vendor/autoload.php';

if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
} else {
    spl_autoload_register(
        static function (string $class): void {
            $prefixes = [
                'ContentsEditor\\' => 'src/',
                // Ported oj-platform addon layer; see the note above the
                // READCONTENTS_LIBRARY_* constant aliases.
                'ReadContents\\Library\\' => 'src/ReadContents/Library/',
            ];

            foreach ($prefixes as $prefix => $base) {
                if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                    continue;
                }

                $relative = substr($class, strlen($prefix));
                $path = CONTENTS_EDITOR_PATH . $base . str_replace('\\', '/', $relative) . '.php';

                if (file_exists($path)) {
                    require_once $path;
                }

                return;
            }
        }
    );
}

register_activation_hook(CONTENTS_EDITOR_FILE, [ContentsEditor\Core\Activator::class, 'activate']);
register_activation_hook(CONTENTS_EDITOR_FILE, [ReadContents\Library\Editor\Content\ContentPostType::class, 'registerPostType']);
register_deactivation_hook(CONTENTS_EDITOR_FILE, [ContentsEditor\Core\Deactivator::class, 'deactivate']);

// oj-platform fires 'oj_platform_register_addons' from its OWN 'plugins_loaded'
// handler at priority 1 (see Kernel\Bootstrap::start()). Registering this
// listener from inside another 'plugins_loaded' callback (default priority 10)
// runs too late -- the action has already fired by then, so the addon never
// gets registered at all. It must be attached here, at top-level file-load
// time, exactly like every other addon root file does (and like this plugin's
// own 3.0.1 readcontents-library.php did).
(new ReadContents\Library\Editor\Rest\RestServiceProvider())->register();
// Reading material is imported explicitly as a separate content package.

add_action(
    'oj_platform_register_addons',
    static function ($gateway): void {
        ReadContents\Library\Addon\ReadContentsLibraryAddon::register($gateway);
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        ContentsEditor\Core\Plugin::instance()->boot();
    }
);
