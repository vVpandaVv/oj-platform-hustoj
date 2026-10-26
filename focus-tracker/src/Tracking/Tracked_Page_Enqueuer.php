<?php
declare(strict_types=1);

namespace FocusTracker\Tracking;

use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;
use FocusTracker\Addon\Runtime_Bridge;

/** Shared configuration for platform site assets and WordPress theme pages. */
final class Tracked_Page_Enqueuer {
    public static function maybe_enqueue(): void {
        $config = self::config(get_current_user_id());
        if (empty($config)) {
            return;
        }

        wp_enqueue_script('focus-tracker-activity-gate', FOCUS_TRACKER_URL . 'resources/js/activity-gate.js', array(), FOCUS_TRACKER_VERSION, true);
        wp_enqueue_style('focus-tracker-activity-status', FOCUS_TRACKER_URL . 'resources/css/activity-status.css', array(), FOCUS_TRACKER_VERSION);
        wp_enqueue_script(
            'focus-tracker-tracker',
            FOCUS_TRACKER_URL . 'resources/js/tracker.js',
            array('focus-tracker-activity-gate'),
            FOCUS_TRACKER_VERSION,
            true
        );

        wp_localize_script('focus-tracker-tracker', 'FocusTrackerConfig', $config);
        if (! empty($config['checks']['enabled'])) {
            wp_enqueue_style('focus-tracker-checks', FOCUS_TRACKER_URL . 'resources/css/checks.css', array(), FOCUS_TRACKER_VERSION);
            wp_enqueue_script('focus-tracker-checks', FOCUS_TRACKER_URL . 'resources/js/checks.js', array('focus-tracker-tracker'), FOCUS_TRACKER_VERSION, true);
        }
    }

    /** @return array<string, mixed> Empty disables tracking for this request. */
    public static function config(int $actor_user_id): array {
        if ($actor_user_id < 1 || $actor_user_id !== get_current_user_id() || ! Capabilities::can_participate()) {
            return array();
        }

        $path = self::current_route_path();
        if (! self::current_path_is_tracked($path)) {
            return array();
        }

        return array(
            'restUrl' => esc_url_raw(rest_url('focus-tracker/v1/events')),
            'nonce' => wp_create_nonce('wp_rest'),
            'userId' => $actor_user_id,
            'serverNowMs' => Clock::now_ms(),
            'problemId' => self::current_problem_id($path),
            'heartbeatMs' => 15000,
            'flushMs' => 7000,
            'activityWindowSeconds' => (int) Settings::get('activity_window_seconds'),
            'noticeText' => Settings::get('checks_enabled') && null !== Runtime_Bridge::learning_checks()
                ? __('소속 그룹·학급의 지정 활동 시간에만 기록하고 학습 확인 문항을 제시합니다. 활동 중 초안을 서버에서 일시 분석하고 문항에 필요한 일부만 저장합니다.', 'focus-tracker')
                : __('소속 그룹·학급의 지정 활동 시간에만 화면 상태와 학습 활동을 기록합니다.', 'focus-tracker'),
            'activityGate' => apply_filters('focus_tracker_activity_gate_client_config', array(
                'url' => esc_url_raw(rest_url('focus-tracker/v1/tracking/state')),
                'pollMs' => 5000,
                'timeZone' => wp_timezone()->getName(),
            )),
            'checks' => apply_filters('focus_tracker_checks_client_config', array(
                'enabled' => (bool) Settings::get('checks_enabled') && null !== Runtime_Bridge::learning_checks() && null !== self::current_problem_id($path),
                'nextUrl' => esc_url_raw(rest_url('focus-tracker/v1/checks/next')),
                'responseUrl' => esc_url_raw(rest_url('focus-tracker/v1/checks')),
                'initialDelayMs' => 45000, 'pollMs' => 60000, 'maxDraftBytes' => 65536,
            )),
        );
    }

    private static function current_path_is_tracked(string $path): bool {
        /**
         * Filters the regular expressions matched against the current
         * request path to decide whether the focus tracker should load.
         * Defaults to the admin-configurable Support\Settings value.
         *
         * @param list<string> $patterns
         */
        $patterns = apply_filters('focus_tracker_tracked_url_patterns', Settings::get('tracked_url_patterns'));

        foreach ((array) $patterns as $pattern) {
            if (is_string($pattern) && 1 === preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /** Mirrors the platform transport's public route normalization. */
    private static function current_route_path(): string {
        $route = $_GET['oj_route'] ?? null;
        if (! is_scalar($route) && function_exists('get_query_var')) {
            $route = get_query_var('oj_route', null);
        }
        if (is_scalar($route) && '' !== (string) $route) {
            return self::normalize_path((string) wp_unslash($route));
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $home_path = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ('' !== $home_path && ($path === $home_path || str_starts_with($path, $home_path . '/'))) {
            $path = substr($path, strlen($home_path));
        }

        return self::normalize_path($path);
    }

    private static function normalize_path(string $path): string {
        if (strlen($path) > 2048 || strpbrk($path, "\0\\?#") !== false || str_contains($path, '//')) {
            return '/_oj_invalid_route_';
        }
        $path = '/' . ltrim($path, '/');

        return '/' === $path ? $path : rtrim($path, '/');
    }

    private static function current_problem_id(string $path): ?int {
        if (1 === preg_match('#^/(?:problems|submit)/(\d+)(?:/|$)#', $path, $matches)) {
            return (int) $matches[1];
        }

        $query_id = $_GET['problem_id'] ?? null;
        if ('/submit' === $path && is_scalar($query_id) && ctype_digit((string) $query_id) && (int) $query_id > 0) {
            return (int) $query_id;
        }

        return null;
    }
}
