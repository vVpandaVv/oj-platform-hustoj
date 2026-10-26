<?php
declare(strict_types=1);

namespace TeachingTools\Admin;

use TeachingTools\Addon\Oj_Platform_Registration;
use TeachingTools\Support\Capabilities;
use TeachingTools\Support\Realtime_Settings;

final class Realtime_Settings_Page {
    public static function register(): void {
        add_action('admin_post_teaching_tools_realtime_save', array(__CLASS__, 'save'));
        add_filter('plugin_action_links_' . plugin_basename(TEACHING_TOOLS_FILE), array(__CLASS__, 'action_links'));
    }

    /** @param array<string, string> $links */
    public static function action_links(array $links): array {
        array_unshift($links, '<a href="' . esc_url(self::console_url()) . '">' . esc_html__('실시간 설정', 'teaching-tools') . '</a>');

        return $links;
    }

    /** @param array<string, string> $args */
    public static function console_url(array $args = array()): string {
        return add_query_arg($args, home_url(Oj_Platform_Registration::SETTINGS_PATH));
    }

    public static function save(): void {
        if (! Capabilities::can_manage_settings()) {
            wp_die(esc_html__('교실 도구 설정 권한이 없습니다.', 'teaching-tools'), '', array('response' => 403));
        }

        check_admin_referer('teaching_tools_realtime_save');
        $values = array(
            'enabled' => ! empty($_POST['enabled']),
            'public_url' => isset($_POST['public_url'])
                ? sanitize_text_field(wp_unslash($_POST['public_url']))
                : '',
        );
        if (! empty($_POST['new_secret'])) {
            $values['secret'] = Realtime_Settings::new_secret();
        }
        Realtime_Settings::save($values);
        update_option('teaching_tools_retention_days', max(1, min(365, absint($_POST['retention_days'] ?? 30))));
        update_option('teaching_tools_active_session_hours', max(1, min(168, absint($_POST['active_session_hours'] ?? 12))));

        wp_safe_redirect(self::console_url(array('saved' => '1')));
        exit;
    }
}
