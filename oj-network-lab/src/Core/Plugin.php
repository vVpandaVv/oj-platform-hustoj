<?php
declare(strict_types=1);
namespace OjNetworkLab\Core;
use CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeContextV1;
use OjNetworkLab\Addon\Registration;
use OjNetworkLab\Storage\Installer;
use OjNetworkLab\Support\Settings;
use OjNetworkLab\Support\Privacy;
final class Plugin
{
    public static ?AddonRuntimeContextV1 $runtime = null;
    public static function boot(): void
    {
        Registration::register(); Settings::register(); Privacy::register();
        add_action('plugins_loaded', [Installer::class, 'maybeInstall'], 12);
        add_action('oj_network_lab_cleanup', [self::class, 'cleanup']);
    }
    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) { wp_die('OJ Network Lab은 사이트별로 활성화해 주세요.'); }
        if (PHP_VERSION_ID < 80300 || !defined('OJ_PLATFORM_VERSION') || version_compare(OJ_PLATFORM_VERSION, '2.5.9', '<')
            || !defined('OJ_PLATFORM_ADDON_API_VERSION') || version_compare(OJ_PLATFORM_ADDON_API_VERSION, '1.9.0', '<')) {
            wp_die('OJ Network Lab에는 PHP 8.3, OJ Platform 2.5.9 이상과 Addon API 1.9 이상이 필요합니다.');
        }
        if (!Installer::install()) { wp_die('네트워크 실습 저장소를 만들지 못했습니다. DB 테이블 생성 권한을 확인하세요.'); }
        if (!wp_next_scheduled('oj_network_lab_cleanup')) { wp_schedule_event(time() + 3600, 'daily', 'oj_network_lab_cleanup'); }
    }
    public static function deactivate(): void { wp_clear_scheduled_hook('oj_network_lab_cleanup'); }
    public static function cleanup(): void
    {
        $days = Settings::retention(); if (!$days) { return; }
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Installer::table() . ' WHERE updated_at < %s LIMIT 500', gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
    }
    public static function audit(string $event, int $id = 0): void
    {
        if (function_exists('oj_log_audit_event')) { oj_log_audit_event('network_lab.' . $event, '네트워크 실습 기록 변경', ['project_id' => $id, 'user_id' => get_current_user_id()], 'info'); }
        do_action('oj_network_lab_audit', $event, ['project_id' => $id, 'user_id' => get_current_user_id()]);
    }
}
