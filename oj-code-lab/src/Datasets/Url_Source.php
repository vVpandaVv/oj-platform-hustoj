<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Datasets;

use OjCodeLab\Support\Dataset_File;
use OjCodeLab\Support\Dataset_Limits;
use WP_Error;

/**
 * Fetches a plain CSV/file from a direct download URL a teacher pasted in
 * (docs/README §데이터 불러오기) — covers 공공데이터포털 file-data download
 * links, GitHub-hosted CSVs, and anything already hosted on the school's
 * own server. No credentials involved: unlike Kaggle, these URLs are either
 * public or already carry any auth they need (e.g. a data.go.kr
 * serviceKey embedded in the query string by the teacher).
 */
final class Url_Source {
    /**
     * Rewrites a GitHub "blob" page URL (what people actually copy from
     * their browser) to its raw-content equivalent. Anything else is
     * returned unchanged.
     */
    public static function normalize(string $url): string {
        $pattern = '#^https://github\.com/([^/]+)/([^/]+)/blob/(.+)$#';
        if (preg_match($pattern, $url, $matches)) {
            return sprintf('https://raw.githubusercontent.com/%s/%s/%s', $matches[1], $matches[2], $matches[3]);
        }

        return $url;
    }

    /**
     * @return array{content: string, fileName: string, format: string}|WP_Error
     */
    public static function fetch(string $url): array|WP_Error {
        $url = self::normalize($url);

        $error = self::validate($url);
        if (null !== $error) {
            return $error;
        }

        $max_bytes = Dataset_Limits::max_bytes();
        // WordPress also validates DNS and every redirect target.
        $response = wp_safe_remote_get($url, array(
            'timeout' => 30,
            'redirection' => 3,
            // See Kaggle_Client::download() — aborts mid-transfer instead
            // of buffering an oversized file fully before checking its size.
            'limit_response_size' => $max_bytes + 1,
        ));
        if (is_wp_error($response)) {
            return Dataset_Limits::response_hit_size_limit($response)
                ? new WP_Error('oj_code_lab_dataset_too_large', Dataset_Limits::too_large_message())
                : new WP_Error('oj_code_lab_remote_failed', '데이터 서버에 연결하지 못했습니다. 잠시 후 다시 시도해 주세요.', array('status' => 502));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('oj_code_lab_url_http_error', sprintf('데이터 URL 응답 오류 (HTTP %d)', $code));
        }

        // Normalized before the size check: converting legacy Korean
        // EUC-KR/CP949 CSVs to UTF-8 can grow the byte count (Korean text
        // roughly 2 bytes/char in CP949 vs. 3 in UTF-8), so the cap must
        // apply to what actually ends up stored, not the raw download.
        $body = wp_remote_retrieve_body($response);
        // A transport may return HTTP 200 with a truncated body. The extra
        // sentinel byte distinguishes an exact-size file from an oversized one.
        if (strlen($body) > $max_bytes) {
            return new WP_Error('oj_code_lab_dataset_too_large', Dataset_Limits::too_large_message());
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = '' !== $path ? rawurldecode(basename($path)) : 'data';

        return Dataset_File::normalize($body, $name);
    }

    private static function validate(string $url): ?WP_Error {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, array('http', 'https'), true) || '' === $host) {
            return new WP_Error('oj_code_lab_invalid_url', __('http(s) 주소만 사용할 수 있습니다.', 'oj-code-lab'), array('status' => 400));
        }

        // Defense in depth against a pasted-URL SSRF footgun: this endpoint
        // is gated behind the teacher "manage datasets" capability, but a
        // compromised/tricked teacher account should still not be able to
        // use it to probe the server's own private network.
        $blocked = array('localhost', '127.0.0.1', '0.0.0.0', '::1');
        if (in_array($host, $blocked, true) || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.)/', $host)) {
            return new WP_Error('oj_code_lab_invalid_url', __('내부 네트워크 주소는 사용할 수 없습니다.', 'oj-code-lab'), array('status' => 400));
        }

        return null;
    }
}
