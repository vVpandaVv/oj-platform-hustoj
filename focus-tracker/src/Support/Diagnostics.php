<?php
declare(strict_types=1);

namespace FocusTracker\Support;

/** Fixed-cardinality, minute-sampled observations. Never accepts identifiers or text. */
final class Diagnostics {
    private const PREFIX = 'focus_tracker_diagnostic_sampled_';
    private const OPERATIONS = array('ingest', 'check', 'gate');
    private const REASONS = array('success', 'error', 'inactive', 'disabled', 'unsupported', 'no_candidate', 'access', 'store', 'cooldown', 'limited', 'invalid');

    public static function record(string $operation, string $reason, ?int $now_ms = null): void {
        if (! in_array($operation, self::OPERATIONS, true) || ! in_array($reason, self::REASONS, true)) { return; }
        global $wpdb;
        try {
            // One observation per operation/reason/minute across all learners.
            // The cheap cached read avoids two contended writes on every poll.
            $now = $now_ms ?? Clock::now_ms();
            $bucket = intdiv($now, 60000);
            $sample_key = self::PREFIX . $operation . '_' . $reason . '_minute';
            $previous = get_option($sample_key, false);
            if ($previous !== false && (int) $previous >= $bucket) { return; }
            if ($previous === false) {
                if (! add_option($sample_key, (string) $bucket, '', false)) { return; }
            } else {
                $changed = $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->options . ' SET option_value = %s WHERE option_name = %s AND option_value = %s', (string) $bucket, $sample_key, (string) $previous));
                if ($changed !== 1) { return; }
                wp_cache_delete($sample_key, 'options');
            }
            $key = self::PREFIX . $operation . '_' . $reason;
            $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1", $key));
            $field = in_array($reason, array('success', 'inactive', 'cooldown', 'no_candidate', 'disabled', 'unsupported'), true) ? 'last_success' : 'last_error';
            $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = IF(CAST(option_value AS UNSIGNED) < CAST(VALUES(option_value) AS UNSIGNED), VALUES(option_value), option_value)", self::PREFIX . $operation . '_' . $field, (string) $now));
        } catch (\Throwable $error) { /* Diagnostics must never change an ACK. */ }
    }

    public static function snapshot(): array {
        global $wpdb;
        $out = array('updatedAtMs' => null, 'available' => true, 'samplePeriodSeconds' => 60, 'operations' => array());
        try {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ESCAPE '!'", 'focus!_tracker!_diagnostic!_sampled!_%'), ARRAY_A);
            if (! is_array($rows) || '' !== (string) $wpdb->last_error) { throw new \RuntimeException('diagnostic_read'); }
            $values = array_column($rows, 'option_value', 'option_name');
            foreach (self::OPERATIONS as $operation) {
                $counts = array();
                foreach (self::REASONS as $reason) { $counts[$reason] = max(0, (int) ($values[self::PREFIX . $operation . '_' . $reason] ?? 0)); }
                $success = (int) ($values[self::PREFIX . $operation . '_last_success'] ?? 0);
                $error = (int) ($values[self::PREFIX . $operation . '_last_error'] ?? 0);
                $out['operations'][$operation] = array('counts' => $counts, 'lastSuccessAtMs' => $success ?: null, 'lastErrorAtMs' => $error ?: null);
                $out['updatedAtMs'] = max((int) $out['updatedAtMs'], $success, $error) ?: null;
            }
        } catch (\Throwable $error) { $out['available'] = false; }
        return $out;
    }

    public static function reset(): void {
        // Use the option API for every fixed key so a persistent option cache
        // cannot retain a minute claim after its database row was removed.
        foreach (array(self::PREFIX, 'focus_tracker_diagnostic_') as $prefix) {
            foreach (self::OPERATIONS as $operation) {
                $keys = array($prefix . $operation . '_last_success', $prefix . $operation . '_last_error');
                foreach (self::REASONS as $reason) {
                    $keys[] = $prefix . $operation . '_' . $reason;
                    if ($prefix === self::PREFIX) { $keys[] = $prefix . $operation . '_' . $reason . '_minute'; }
                }
                foreach ($keys as $key) {
                    delete_option($key);
                    // Also clear a stale entry left by an earlier bulk delete,
                    // even when delete_option() finds no remaining DB row.
                    wp_cache_delete($key, 'options');
                }
            }
        }
    }
}
