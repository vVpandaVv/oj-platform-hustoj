<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Support;

use WP_Error;

/**
 * Single place governing how large one dataset file is allowed to be —
 * Kaggle downloads, direct-URL fetches, and teacher uploads all share this
 * (docs/README §데이터 불러오기). Filterable rather than a hardcoded
 * constant so an operator whose MySQL is configured with a larger
 * max_allowed_packet than the shared-hosting default can raise it without
 * editing plugin code.
 */
final class Dataset_Limits {
    private const DEFAULT_MAX_BYTES = 16_000_000;

    public static function configured_max_bytes(): int {
        $value = (int) apply_filters('oj_code_lab_max_dataset_bytes', self::DEFAULT_MAX_BYTES);
        return $value > 0 ? $value : self::DEFAULT_MAX_BYTES;
    }

    public static function max_bytes(): int {
        $configured = self::configured_max_bytes();
        $limit = Database_Packet::payload_limit($configured);
        // Keep read/import UI usable during a transient diagnostic failure.
        // Upload storage independently requires a successful limit lookup before writing.
        return is_wp_error($limit) ? $configured : $limit;
    }

    public static function max_megabytes_label(?int $bytes = null): string {
        return (string) round((null !== $bytes ? $bytes : self::max_bytes()) / 1_000_000, 1);
    }

    public static function too_large_message(?int $limit_bytes = null): string {
        return sprintf(
            /* translators: %s: maximum size in megabytes */
            __('파일이 너무 큽니다 (최대 %sMB). 서버의 저장 한도와 설정된 파일 상한을 함께 적용합니다.', 'oj-code-lab'),
            self::max_megabytes_label($limit_bytes)
        );
    }

    /**
     * The PHP-level ceiling (min of upload_max_filesize/post_max_size)
     * that already applies to any $_FILES upload before this addon's own
     * Dataset_Limits::max_bytes() cap is even checked — used to give an
     * accurate message when an upload is rejected at that lower layer.
     */
    public static function php_upload_max_bytes(): int {
        return (int) wp_max_upload_size();
    }

    /**
     * wp_remote_get()'s 'limit_response_size' arg makes the underlying
     * transport (cURL or streams — whichever WordPress picked) abort
     * mid-download once past that many bytes, surfaced as a generic
     * WP_Error rather than a dedicated error code. Matching on the message
     * text both transports use is the only way to tell "hit the size cap"
     * apart from "the network genuinely failed" — if a future WordPress
     * core version changes that text, the caller still returns a correct
     * (if less specific) network-failure error, so this only affects which
     * message the student sees, never whether oversized files get rejected.
     */
    public static function response_hit_size_limit(WP_Error $error): bool {
        foreach ($error->get_error_messages() as $message) {
            if (false !== stripos($message, 'too much data') || false !== stripos($message, 'response size')) {
                return true;
            }
        }

        return false;
    }
}
