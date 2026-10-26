<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Support;
use WP_Error;
final class Request_Limits {
    public static function check(): bool|WP_Error {
        if (! Database_Lock::acquire('dataset-rate')) { return self::denied(); }
        try {
            $minute = intdiv(time(), 60);
            $keys = array('oj_code_lab_rate_user_' . get_current_user_id(), 'oj_code_lab_rate_site');
            $limits = array(max(1, (int) apply_filters('oj_code_lab_requests_per_minute', 30)), max(1, (int) apply_filters('oj_code_lab_site_requests_per_minute', 300)));
            $buckets = array();
            foreach ($keys as $i => $key) {
                $bucket = get_transient($key);
                $count = is_array($bucket) && ($bucket['minute'] ?? -1) === $minute ? (int) $bucket['count'] : 0;
                if ($count >= $limits[$i]) { return self::denied(); }
                $buckets[$key] = array('minute' => $minute, 'count' => $count + 1);
            }
            foreach ($buckets as $key => $bucket) { set_transient($key, $bucket, 120); }
            return true;
        } finally { Database_Lock::release('dataset-rate'); }
    }
    private static function denied(): WP_Error {
        return new WP_Error('oj_code_lab_rate_limit', __('데이터 요청이 많습니다. 잠시 후 다시 시도해 주세요.', 'oj-code-lab'), array('status' => 429, 'retryAfter' => 60));
    }
}
