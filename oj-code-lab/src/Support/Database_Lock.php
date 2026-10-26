<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Support;
final class Database_Lock {
    private static function key(string $purpose): string {
        global $wpdb;
        return 'ocl_' . hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . ':' . $wpdb->prefix . ':' . $purpose);
    }
    public static function acquire(string $purpose): bool {
        global $wpdb;
        return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)', substr(self::key($purpose), 0, 64)));
    }
    public static function release(string $purpose): void {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', substr(self::key($purpose), 0, 64)));
    }
}
