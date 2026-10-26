<?php
/**
 * Plugin Name: Edu Games
 * Description: Modular educational mini-games with scoring, statistics, and leaderboards.
 * Version: 1.6.7
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: CodingPanda
 * Text Domain: edu-games
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('EDU_GAMES_VERSION', '1.6.7');
define('EDU_GAMES_FILE', __FILE__);
define('EDU_GAMES_PATH', plugin_dir_path(__FILE__));
define('EDU_GAMES_URL', plugin_dir_url(__FILE__));

require_once EDU_GAMES_PATH . 'src/Core/Autoloader.php';

\EduGames\Core\Autoloader::register();
\EduGames\Core\Plugin::boot();

if (! function_exists('edu_games_registry')) {
    function edu_games_registry(): \EduGames\Games\Game_Module_Registry {
        return \EduGames\Core\Plugin::registry();
    }
}

if (! function_exists('edu_games_activate')) {
    function edu_games_activate(): void {
        if (
            ! defined('OJ_PLATFORM_ADDON_API_VERSION')
            || ! class_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::class)
        ) {
            wp_die(
                esc_html__('Edu Games requires OJ Platform 2.0.0-dev.30 or newer.', 'edu-games'),
                esc_html__('Plugin dependency check failed', 'edu-games'),
                array('response' => 500)
            );
        }

        \EduGames\Core\Plugin::activate();
    }
}

register_activation_hook(EDU_GAMES_FILE, 'edu_games_activate');
