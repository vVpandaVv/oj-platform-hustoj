<?php
/**
 * Plugin Name: Learning Materials
 * Description: Share learning files and Google document links with the whole platform or specific groups, registered as an oj-platform addon.
 * Version: 1.1.2
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Author: CodingPanda
 * Text Domain: learning-materials
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('LEARNING_MATERIALS_VERSION', '1.1.2');
define('LEARNING_MATERIALS_FILE', __FILE__);
define('LEARNING_MATERIALS_PATH', plugin_dir_path(__FILE__));
define('LEARNING_MATERIALS_URL', plugin_dir_url(__FILE__));

require_once LEARNING_MATERIALS_PATH . 'src/Core/Autoloader.php';

\LearningMaterials\Core\Autoloader::register();
\LearningMaterials\Core\Plugin::boot();

if (! function_exists('learning_materials_activate')) {
    function learning_materials_activate(): void {
        if (
            ! defined('OJ_PLATFORM_VERSION')
            || ! defined('OJ_PLATFORM_ADDON_API_VERSION')
            || version_compare((string) OJ_PLATFORM_VERSION, '2.3.0', '<')
            || version_compare((string) OJ_PLATFORM_ADDON_API_VERSION, '1.4.0', '<')
            || ! interface_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonActivityProviderV1::class)
        ) {
            wp_die(
                esc_html__('Learning Materials requires OJ Platform 2.3.0 or newer with Addon API 1.4.', 'learning-materials'),
                esc_html__('Plugin dependency check failed', 'learning-materials'),
                array('response' => 500)
            );
        }

        \LearningMaterials\Database\Installer::install();
    }
}

register_activation_hook(LEARNING_MATERIALS_FILE, 'learning_materials_activate');
