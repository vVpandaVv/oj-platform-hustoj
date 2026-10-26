<?php
/**
 * Plugin Name: Focus Tracker
 * Description: Tracks tab focus and in-page learning activity while students work on OJ problems, and scores it as an oj-platform addon.
 * Version: 1.0.1
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Requires Plugins: oj-platform
 * Author: CodingPanda
 * Text Domain: focus-tracker
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('FOCUS_TRACKER_VERSION', '1.0.1');
define('FOCUS_TRACKER_FILE', __FILE__);
define('FOCUS_TRACKER_PATH', plugin_dir_path(__FILE__));
define('FOCUS_TRACKER_URL', plugin_dir_url(__FILE__));

require_once FOCUS_TRACKER_PATH . 'src/Core/Autoloader.php';

\FocusTracker\Core\Autoloader::register();
\FocusTracker\Core\Plugin::boot();

if (! function_exists('focus_tracker_activate')) {
    function focus_tracker_activate(): void {
        if (
            ! defined('OJ_PLATFORM_ADDON_API_VERSION')
            || version_compare((string) OJ_PLATFORM_ADDON_API_VERSION, '1.4.0', '<')
            || ! class_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::class)
            || ! interface_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonIntegrationManifestBuilderV1::class)
            || ! interface_exists(\CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckRuntimeContextV1::class)
            || ! defined('OJ_PLATFORM_VERSION')
            || version_compare((string) OJ_PLATFORM_VERSION, '2.3.22', '<')
        ) {
            wp_die(
                esc_html__('Focus Tracker에는 OJ Platform 2.3.22 이상과 Addon API 1.4가 필요합니다.', 'focus-tracker'),
                esc_html__('Plugin dependency check failed', 'focus-tracker'),
                array('response' => 500)
            );
        }

        if (! \FocusTracker\Database\Installer::install()) {
            wp_die(esc_html__('Focus Tracker 데이터베이스 설치에 실패했습니다. 데이터베이스 권한을 확인한 뒤 다시 활성화해 주세요.', 'focus-tracker'));
        }
        \FocusTracker\Jobs\Retention_Cleanup::maybe_schedule();
    }
}

if (! function_exists('focus_tracker_deactivate')) {
    function focus_tracker_deactivate(): void {
        if (! \FocusTracker\Support\Student_Participation_Defaults::deactivate()) {
            wp_die(esc_html__('학생 기본 추적 권한을 정리하지 못했습니다. 데이터베이스 상태를 확인한 뒤 다시 비활성화해 주세요.', 'focus-tracker'), '', array('response' => 503));
        }
        \FocusTracker\Jobs\Retention_Cleanup::unschedule();
    }
}

register_activation_hook(FOCUS_TRACKER_FILE, 'focus_tracker_activate');
register_deactivation_hook(FOCUS_TRACKER_FILE, 'focus_tracker_deactivate');
