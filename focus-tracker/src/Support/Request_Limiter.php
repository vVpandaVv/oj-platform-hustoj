<?php
declare(strict_types=1);

namespace FocusTracker\Support;

use WP_Error;
use WP_REST_Request;

/** Database-atomic, per-account request budgets, independent of issuance quotas. */
final class Request_Limiter {
    private const PREFIX = 'focus_tracker_limit_';
    private const UNIT = 1000000;
    private const LIMITS = array('check_next' => 60, 'check_response' => 120, 'check_summary' => 60, 'ingest' => 180, 'gate' => 180);

    public static function register(): void {
        add_action('focus_tracker_cleanup', array(__CLASS__, 'cleanup'));
        add_filter('rest_post_dispatch', array(__CLASS__, 'retry_header'), 10, 3);
    }

    public static function retry_header($response, $server = null, $request = null) {
        if (is_object($response) && method_exists($response, 'get_data') && method_exists($response, 'header')) {
            $data = $response->get_data();
            if (is_array($data) && ($data['code'] ?? '') === 'focus_tracker_rate_limited') {
                $retry = max(1, min(60, (int) ($data['data']['retryAfterSeconds'] ?? 60)));
                $response->header('Retry-After', (string) $retry);
            }
        }
        return $response;
    }

    public static function check(int $user_id, string $channel, ?int $now_ms = null): ?WP_Error {
        global $wpdb;
        if ($user_id < 1 || ! isset(self::LIMITS[$channel])) { return self::failed(); }
        if (\FocusTracker\Privacy\Privacy_Guard::state($user_id) !== 'open') {
            return \FocusTracker\Privacy\Privacy_Guard::error() ?? new WP_Error('focus_tracker_privacy_locked', '개인정보 삭제 중이거나 삭제된 계정이어서 학습 기록 처리를 중단했습니다.', array('status' => 423));
        }
        $now = intdiv($now_ms ?? Clock::now_ms(), 1000);
        $base = intdiv($now, 60) * self::UNIT;
        $key = self::key($user_id, $channel);
        $table = $wpdb->options;
        $sql = $wpdb->prepare("UPDATE {$table} SET option_value = CASE WHEN CAST(option_value AS UNSIGNED) < %d THEN %d ELSE CAST(option_value AS UNSIGNED) + 1 END WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $base, $base + 1, $key, $base + self::LIMITS[$channel]);
        $changed = $wpdb->query($sql);
        if ($changed === false) { return self::failed(); }
        if ($changed > 0) { return null; }
        // Unique option_name insertion is atomic. No read/modify/write
        // counter or transient/object-cache race is used.
        if (false === $wpdb->query($wpdb->prepare("INSERT INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = option_value", $key, (string) $base))) { return self::failed(); }
        $changed = $wpdb->query($sql);
        if ($changed === false) { return self::failed(); }
        if ($changed > 0) { return null; }
        $retry = 60 - ($now % 60);
        return new WP_Error('focus_tracker_rate_limited', '요청이 많습니다. 잠시 후 다시 시도해 주세요.', array('status' => 429, 'retryAfterSeconds' => $retry, 'headers' => array('Retry-After' => (string) $retry)));
    }

    /** Check raw bytes before JSON parsing. Fixture fallback remains bounded. */
    public static function body_limit(WP_REST_Request $request, int $maximum): ?WP_Error {
        $raw = method_exists($request, 'get_body') ? $request->get_body() : json_encode($request->get_json_params());
        if (is_string($raw) && strlen($raw) > $maximum) {
            return new WP_Error('focus_tracker_payload_too_large', '요청 크기가 허용 범위를 초과했습니다.', array('status' => 413));
        }
        return null;
    }

    public static function cleanup(): void {
        global $wpdb;
        $cutoff = (intdiv(time(), 60) - 2) * self::UNIT;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s ESCAPE '!' AND CAST(option_value AS UNSIGNED) < %d", 'focus!_tracker!_limit!_%', $cutoff));
    }

    public static function erase_user(int $user_id): bool {
        global $wpdb;
        foreach (array_keys(self::LIMITS) as $channel) {
            if (false === $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", self::key($user_id, $channel)))) { return false; }
            wp_cache_delete(self::key($user_id, $channel), 'options');
        }
        return true;
    }

    private static function key(int $user_id, string $channel): string { return self::PREFIX . $channel . '_' . $user_id; }
    private static function failed(): WP_Error { return new WP_Error('focus_tracker_limit_unavailable', '요청을 안전하게 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.', array('status' => 503)); }
}
