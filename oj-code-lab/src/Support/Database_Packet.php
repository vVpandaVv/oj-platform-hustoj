<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Support;
use WP_Error;

/** Bound raw values before wpdb can double their size while escaping SQL. */
final class Database_Packet {
    public const SQL_OVERHEAD_BYTES = 65536;

    public static function payload_limit(int $configured_bytes): int|WP_Error {
        global $wpdb;
        // Read the current connection each time: reconnects may inherit a new limit.
        $value = $wpdb->get_var('SELECT @@SESSION.max_allowed_packet');
        if ('' !== $wpdb->last_error || ! is_scalar($value) || ! preg_match('/^[0-9]+$/D', (string) $value)) {
            return new WP_Error('oj_code_lab_db_limit_unavailable', __('저장 용량 한도를 확인하지 못했습니다. 내용을 유지한 채 잠시 후 다시 시도해 주세요.', 'oj-code-lab'), array('status' => 503));
        }
        $safe_bytes = intdiv(max(0, (int) $value - self::SQL_OVERHEAD_BYTES), 2);
        return min(max(0, $configured_bytes), $safe_bytes);
    }
}
