<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Datasets;
use OjCodeLab\Database\Installer;
use OjCodeLab\Support\Database_Lock;
use OjCodeLab\Support\Database_Packet;
final class Dataset_Cache {
    public const TTL_SECONDS = 86400;
    public static function get(string $key): ?string {
        global $wpdb;
        $table = Installer::dataset_cache_table();
        $body = $wpdb->get_var($wpdb->prepare("SELECT content FROM {$table} WHERE cache_key = %s AND created_at > %s", sha1($key), gmdate('Y-m-d H:i:s', time() - self::TTL_SECONDS)));
        return null === $body ? null : (string) $body;
    }
    public static function put(string $key, string $content): void {
        global $wpdb;
        $packet_limit = Database_Packet::payload_limit(PHP_INT_MAX);
        // A skipped cache refresh must preserve any existing entry and connection.
        if (is_wp_error($packet_limit) || strlen($content) > $packet_limit) { return; }
        $limit = max(1, (int) apply_filters('oj_code_lab_cache_max_bytes', 128_000_000));
        if (strlen($content) > $limit || ! Database_Lock::acquire('dataset-cache')) { return; }
        try {
            $table = Installer::dataset_cache_table();
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at <= %s", gmdate('Y-m-d H:i:s', time() - self::TTL_SECONDS)));
            $used = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(OCTET_LENGTH(content)), 0) FROM {$table} WHERE cache_key <> %s", sha1($key)));
            if ('' !== $wpdb->last_error || (int) $used + strlen($content) > $limit) { return; }
            $wpdb->query($wpdb->prepare("INSERT INTO {$table} (cache_key, content, created_at) VALUES (%s, %s, %s)
                ON DUPLICATE KEY UPDATE content = VALUES(content), created_at = VALUES(created_at)", sha1($key), $content, gmdate('Y-m-d H:i:s')));
        } finally { Database_Lock::release('dataset-cache'); }
    }
}
