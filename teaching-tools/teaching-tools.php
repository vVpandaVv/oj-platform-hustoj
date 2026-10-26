<?php
/**
 * Plugin Name: Teaching Tools
 * Description: Classroom teaching-support tools including live responses, boards, signals, timers, seating, and activities for OJ Platform.
 * Version: 1.2.8
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Author: CodingPanda
 * Text Domain: teaching-tools
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('TEACHING_TOOLS_VERSION', '1.2.8');
define('TEACHING_TOOLS_FILE', __FILE__);
define('TEACHING_TOOLS_PATH', plugin_dir_path(__FILE__));
define('TEACHING_TOOLS_URL', plugin_dir_url(__FILE__));

require_once TEACHING_TOOLS_PATH . 'src/Core/Autoloader.php';

\TeachingTools\Core\Autoloader::register();
\TeachingTools\Core\Plugin::boot();

if (! function_exists('teaching_tools_activate')) {
    function teaching_tools_activate(): void {
        if (
            ! defined('OJ_PLATFORM_VERSION')
            || ! defined('OJ_PLATFORM_ADDON_API_VERSION')
            || version_compare((string) OJ_PLATFORM_VERSION, '2.3.0', '<')
            || version_compare((string) OJ_PLATFORM_ADDON_API_VERSION, '1.4.0', '<')
            || ! interface_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationGatewayV1::class)
        ) {
            wp_die(
                esc_html__('Teaching Tools requires OJ Platform 2.3.0 or newer with Addon API 1.4.', 'teaching-tools'),
                esc_html__('Plugin dependency check failed', 'teaching-tools'),
                array('response' => 500)
            );
        }

        \TeachingTools\Database\Installer::install();
        \TeachingTools\Support\Realtime_Settings::ensure_secret();
        \TeachingTools\Jobs\Session_Cleanup::maybe_schedule();
    }
}

if (! function_exists('teaching_tools_deactivate')) {
    function teaching_tools_deactivate(): void {
        \TeachingTools\Jobs\Session_Cleanup::unschedule();
    }
}

register_activation_hook(TEACHING_TOOLS_FILE, 'teaching_tools_activate');
register_deactivation_hook(TEACHING_TOOLS_FILE, 'teaching_tools_deactivate');
