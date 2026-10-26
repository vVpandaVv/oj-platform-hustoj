<?php
declare(strict_types=1);

namespace FocusTracker\Privacy;

use WP_Error;
use FocusTracker\Support\Clock;

/** Serializes learner writes with erasure, including derived-data readers. */
final class Privacy_Guard {
    private static ?WP_Error $error = null;

    /** Caller owns the SQL transaction; acquire before other row locks. */
    public static function lock(int $user_id): bool {
        self::$error = null;
        if (! self::ensure($user_id)) { self::$error = self::failed(); return false; }
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE", self::key($user_id)));
        if ($value === null || '' !== (string) $wpdb->last_error) { self::$error = self::failed(); return false; }
        if (self::status((string) $value) !== 'open') {
            self::$error = new WP_Error('focus_tracker_privacy_locked', '개인정보 삭제 중이거나 삭제된 계정이어서 학습 기록 처리를 중단했습니다.', array('status' => 423));
            return false;
        }
        return true;
    }

    /** Erasure can resume an existing erasing/deleted row under the same lock. */
    public static function lock_for_erasure(int $user_id): bool {
        self::$error = null;
        if (! self::ensure($user_id)) { return false; }
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE", self::key($user_id)));
        return $value !== null && '' === (string) $wpdb->last_error;
    }

    public static function state(int $user_id): string {
        global $wpdb;
        self::$error = null;
        try {
            $wpdb->last_error = '';
            $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::key($user_id)));
            if ('' !== (string) $wpdb->last_error) { self::$error = self::failed(); return 'erasing'; }
            return $value === null ? 'open' : self::status((string) $value);
        } catch (\Throwable $error) { self::$error = self::failed(); return 'erasing'; }
    }

    /** Inclusive erasure watermark rejects old browser outbox packets forever. */
    public static function cutoff(int $user_id): int {
        global $wpdb;
        try {
            $wpdb->last_error = '';
            $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::key($user_id)));
            if ('' !== (string) $wpdb->last_error) { return PHP_INT_MAX; }
            if ($value === null || in_array($value, array('open', 'erasing', 'deleted'), true)) { return 0; }
            return preg_match('/^(?:open|erasing):([0-9]{1,16})$/D', (string) $value, $match) ? (int) $match[1] : PHP_INT_MAX;
        } catch (\Throwable $error) { return PHP_INT_MAX; }
    }

    /** Only after lock_for_erasure(), within the caller's transaction. */
    public static function set_state(int $user_id, string $state): bool {
        if (! in_array($state, array('open', 'erasing', 'deleted'), true)) { return false; }
        global $wpdb;
        $cutoff = self::cutoff($user_id);
        if ($cutoff === PHP_INT_MAX) { return false; }
        $value = $state === 'deleted' ? 'deleted' : $state . ':' . ($state === 'open' ? max(Clock::now_ms(), $cutoff + 1) : $cutoff);
        $saved = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $value, self::key($user_id)));
        wp_cache_delete(self::key($user_id), 'options');
        return $saved !== false;
    }

    public static function run(int $user_id, callable $callback): mixed { return self::run_many(array($user_id), $callback); }

    public static function run_many(array $user_ids, callable $callback): mixed {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $user_ids)));
        sort($ids, SORT_NUMERIC);
        if (false === $wpdb->query('START TRANSACTION')) { return self::failed(); }
        try {
            foreach ($ids as $id) {
                if (! self::lock($id)) { $wpdb->query('ROLLBACK'); return self::error() ?? self::failed(); }
            }
            $result = $callback();
            if ($result instanceof WP_Error) { $wpdb->query('ROLLBACK'); return $result; }
            if (false === $wpdb->query('COMMIT')) { $wpdb->query('ROLLBACK'); return self::failed(); }
            return $result;
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); return self::failed(); }
    }

    public static function error(): ?WP_Error { return self::$error; }

    private static function status(string $value): string {
        if ($value === 'open' || preg_match('/^open:[0-9]{1,16}$/D', $value)) { return 'open'; }
        if ($value === 'deleted') { return 'deleted'; }
        return 'erasing';
    }

    private static function ensure(int $user_id): bool {
        if ($user_id < 1) { return false; }
        global $wpdb;
        $key = self::key($user_id);
        return false !== $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, 'open', 'no') ON DUPLICATE KEY UPDATE option_value = option_value", $key));
    }

    private static function key(int $user_id): string {
        $salt = function_exists('wp_salt') ? wp_salt('auth') : 'focus-tracker-local-fixture';
        // The tombstone stores no raw account ID, timestamp, code or learning
        // data. It rejects requests authenticated before core removed a user.
        return 'focus_tracker_privacy_' . hash_hmac('sha256', (string) $user_id, $salt);
    }

    private static function failed(): WP_Error { return new WP_Error('focus_tracker_privacy_guard_failed', '개인정보 보호 상태를 확인하지 못해 기록 처리를 중단했습니다.', array('status' => 503)); }
}
