<?php
declare(strict_types=1);

namespace FocusTracker\Admin;

use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Settings;

/**
 * Save handler for the site-wide defaults (see docs/DESIGN.md §10 Phase 2):
 * default scoring weights, event retention, activity-window bucket size, and
 * the URL patterns tracker.js loads on.
 *
 * The form itself lives in the OJ console (Addon\Console_Settings_Page_Handler,
 * /oj-console/addons/focus-tracker/settings); there is no wp-admin screen any
 * more. Saving goes through admin-post.php with a nonce and is allowed for
 * site administrators and for users holding the addon's settings capability
 * (Capabilities::can_manage_settings()).
 */
final class Settings_Page {
    public const ACTION = 'focus_tracker_settings_save';
    public const NONCE_ACTION = 'focus_tracker_settings_save';
    public const NONCE_FIELD = 'focus_tracker_settings_nonce';
    public const CONSOLE_PATH = '/oj-console/addons/focus-tracker/settings';

    public static function register(): void {
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle_save'));
        add_filter('plugin_action_links_' . plugin_basename(FOCUS_TRACKER_FILE), array(__CLASS__, 'action_links'));
    }

    public static function console_url(): string {
        return home_url(self::CONSOLE_PATH);
    }

    /**
     * @param array<int|string, string> $links
     * @return array<int|string, string>
     */
    public static function action_links(array $links): array {
        if (Capabilities::can_manage_settings()) {
            array_unshift(
                $links,
                '<a href="' . esc_url(self::console_url()) . '">' . esc_html__('설정', 'focus-tracker') . '</a>'
            );
        }

        return $links;
    }

    public static function handle_save(): void {
        if (! Capabilities::can_manage_settings()) {
            wp_die(
                esc_html__('집중도 평가 설정을 바꿀 권한이 없습니다.', 'focus-tracker'),
                esc_html__('권한 없음', 'focus-tracker'),
                array('response' => 403)
            );
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        try { $error = self::save(wp_unslash($_POST)); }
        catch (\RuntimeException $error) {
            wp_die(esc_html__('설정을 저장하지 못했습니다. 저장소 상태를 확인하고 다시 시도해 주세요.', 'focus-tracker'), esc_html__('설정 저장 실패', 'focus-tracker'), array('response' => 503, 'back_link' => true));
        }
        if ($error !== null) {
            wp_die(esc_html($error), esc_html__('보관 기간을 확인해 주세요', 'focus-tracker'), array('response' => 400, 'back_link' => true));
        }

        wp_safe_redirect(add_query_arg('ftk_notice', 'saved', self::console_url()));
        exit;
    }

    /** @param array<string, mixed> $post */
    private static function save(array $post): ?string {
        $retention = array(
            'retention_days' => $post['retention_days'] ?? Settings::get('retention_days'),
            'check_retention_days' => $post['check_retention_days'] ?? Settings::get('check_retention_days'),
            'aggregate_retention_days' => $post['aggregate_retention_days'] ?? Settings::get('aggregate_retention_days'),
        );
        $error = Settings::retention_error($retention);
        if ($error !== null) { return $error; }
        $patterns = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) ($post['tracked_url_patterns'] ?? '')) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            // Reject anything that doesn't compile as a PCRE pattern rather
            // than silently storing a value that would warning-spam every
            // page load once used in preg_match().
            if (false === @preg_match($line, '')) {
                continue;
            }

            $patterns[] = $line;
        }

        if (empty($patterns)) {
            $patterns = Settings::defaults()['tracked_url_patterns'];
        }

        Settings::update(array_merge($retention, array(
            'weight_focus' => min(100.0, max(0.0, (float) ($post['weight_focus'] ?? 0))),
            'weight_selfreg' => min(100.0, max(0.0, (float) ($post['weight_selfreg'] ?? 0))),
            'weight_activity' => min(100.0, max(0.0, (float) ($post['weight_activity'] ?? 0))),
            'activity_window_seconds' => max(5, (int) ($post['activity_window_seconds'] ?? 30)),
            'tracked_url_patterns' => $patterns,
            'checks_enabled' => ! empty($post['checks_enabled']),
            'check_cooldown_seconds' => max(60, min(3600, (int) ($post['check_cooldown_seconds'] ?? 300))),
            'check_daily_limit' => max(1, min(20, (int) ($post['check_daily_limit'] ?? 3))),
        )));
        return null;
    }
}
