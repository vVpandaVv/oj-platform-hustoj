<?php
/**
 * Plugin Name: OJ Code Arena
 * Description: Real-time team block-capture coding game for OJ Platform. Works right after activation with its built-in engine; a separate Node.js game server is optional for larger classes.
 * Version: 1.3.4
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: CodingPanda
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oj-code-arena
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('OJ_CODE_ARENA_VERSION', '1.3.4');
define('OJ_CODE_ARENA_FILE', __FILE__);
define('OJ_CODE_ARENA_PATH', plugin_dir_path(__FILE__));
define('OJ_CODE_ARENA_URL', plugin_dir_url(__FILE__));

require_once OJ_CODE_ARENA_PATH . 'src/Core/Autoloader.php';

\OjCodeArena\Core\Autoloader::register();
\OjCodeArena\Core\Plugin::boot();

if (! function_exists('oj_code_arena_activate')) {
    function oj_code_arena_activate(): void {
        if (
            ! defined('OJ_PLATFORM_ADDON_API_VERSION')
            || ! class_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::class)
        ) {
            wp_die(
                esc_html__('OJ Code Arena requires OJ Platform 2.0.0-dev.30 or newer (Addon API v1).', 'oj-code-arena'),
                esc_html__('Plugin dependency check failed', 'oj-code-arena'),
                array('response' => 500)
            );
        }

        \OjCodeArena\Database\Installer::install();
        \OjCodeArena\Support\Defaults::apply();
        update_option(\OjCodeArena\Admin\Settings_Page::WELCOME, 1, false);
    }
}

if (! function_exists('oj_code_arena_deactivate')) {
    function oj_code_arena_deactivate(): void {
        \OjCodeArena\Jobs\Cleanup::unschedule();
    }
}

register_activation_hook(OJ_CODE_ARENA_FILE, 'oj_code_arena_activate');
register_deactivation_hook(OJ_CODE_ARENA_FILE, 'oj_code_arena_deactivate');
