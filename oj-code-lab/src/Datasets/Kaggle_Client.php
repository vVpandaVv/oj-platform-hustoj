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
 * Thin server-side proxy over Kaggle's public API (docs/README §Kaggle
 * 연동). Credentials never leave the server — a student's browser only ever
 * talks to this addon's own REST routes, which call out to Kaggle here and
 * hand back plain dataset bytes.
 *
 * Endpoint shapes follow Kaggle's official SDK and legacy REST API.
 * Public, unauthenticated search and file-list responses were checked;
 * account authentication and real downloads still require acceptance.
 * See docs/KAGGLE_CONTRACT_REVIEW_2026-09-10.md for the pinned sources,
 * response evidence, pagination bounds and remaining account checks.
 */
final class Kaggle_Client {
    private const BASE = 'https://www.kaggle.com/api/v1';
    private const FILE_PAGE_SIZE = 100;
    private const MAX_FILE_PAGES = 10;
    private const MAX_FILES = 1000;
    private const FILE_LIST_SECONDS = 20.0;
    private const MAX_PAGE_TOKEN_BYTES = 4096;

    public function __construct(private readonly string $username, private readonly string $key) {
    }

    /**
     * @return list<array{ref: string, title: string, subtitle: string, size: string}>|WP_Error
     */
    public function search(string $query, int $page = 1): array|WP_Error {
        $response = $this->request('/datasets/list', array('search' => $query, 'page' => $page));
        if (is_wp_error($response)) {
            return $response;
        }

        $items = is_array($response) ? $response : array();
        $results = array();
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ref = self::first_string($item, array('ref', 'datasetSlugNullable', 'slug'));
            if ('' === $ref) {
                continue;
            }
            $results[] = array(
                'ref' => $ref,
                'title' => self::first_string($item, array('title'), $ref),
                'subtitle' => self::first_string($item, array('subtitle', 'description')),
                'size' => self::first_string($item, array('size', 'totalBytesNullable')),
            );
        }

        return $results;
    }

    /**
     * @return list<array{name: string, size: string}>|WP_Error
     */
    public function files(string $owner, string $slug): array|WP_Error {
        $deadline = microtime(true) + self::FILE_LIST_SECONDS;
        $page_token = '';
        $seen_tokens = array();
        $files = array();
        for ($page = 1; $page <= self::MAX_FILE_PAGES; $page++) {
            $query = array('pageSize' => self::FILE_PAGE_SIZE);
            if ('' !== $page_token) { $query['pageToken'] = $page_token; }
            $response = $this->request('/datasets/list/' . rawurlencode($owner) . '/' . rawurlencode($slug), $query, $deadline);
            if (is_wp_error($response)) { return $response; }
            if (isset($response['errorMessage']) && '' !== $response['errorMessage']) {
                return new WP_Error('oj_code_lab_kaggle_file_list_error', 'Kaggle에서 파일 목록을 제공하지 못했습니다.', array('status' => 502));
            }
            if (array_key_exists('datasetFiles', $response)) {
                $list = $response['datasetFiles'];
            } elseif (array_key_exists('files', $response)) {
                $list = $response['files'];
            } elseif (array_is_list($response)) {
                $list = $response;
            } else {
                $list = null;
            }
            if (! is_array($list) || ! array_is_list($list)) {
                return self::invalid_file_list();
            }
            if (count($files) + count($list) > self::MAX_FILES) {
                return self::file_list_limit();
            }
            foreach ($list as $item) {
                if (! is_array($item)) { return self::invalid_file_list(); }
                $name = self::first_string($item, array('name', 'nameNullable', 'ref'));
                if ('' === $name || str_contains($name, "\0")) { return self::invalid_file_list(); }
                $files[] = array(
                    'name' => $name,
                    'size' => self::first_string($item, array('totalBytes', 'totalBytesNullable', 'size')),
                );
            }
            $next = $response['nextPageToken'] ?? '';
            if (! is_string($next) || strlen($next) > self::MAX_PAGE_TOKEN_BYTES
                    || ('' !== $next && 1 !== preg_match('/\A[^\x00-\x20\x7f]+\z/uD', $next))) {
                return new WP_Error('oj_code_lab_kaggle_page_token', 'Kaggle 파일 목록의 다음 페이지 정보가 올바르지 않습니다.', array('status' => 502));
            }
            if (microtime(true) >= $deadline) { return self::file_list_timeout(); }
            if ('' === $next) { return $files; }
            if (isset($seen_tokens[$next])) {
                return new WP_Error('oj_code_lab_kaggle_page_loop', 'Kaggle 파일 목록의 페이지가 반복되어 조회를 중단했습니다.', array('status' => 502));
            }
            if ($page === self::MAX_FILE_PAGES) { return self::file_list_limit(); }
            $seen_tokens[$next] = true;
            $page_token = $next;
        }
        return self::file_list_limit();
    }

    private static function invalid_file_list(): WP_Error {
        return new WP_Error('oj_code_lab_kaggle_bad_file_list', 'Kaggle 파일 목록 응답 형식이 올바르지 않습니다.', array('status' => 502));
    }

    private static function file_list_limit(): WP_Error {
        return new WP_Error('oj_code_lab_kaggle_file_list_limit', 'Kaggle 파일 목록 조회 한도(10페이지 또는 1,000개 파일)를 초과했습니다.', array('status' => 413));
    }

    private static function file_list_timeout(): WP_Error {
        return new WP_Error('oj_code_lab_kaggle_file_list_timeout', 'Kaggle 파일 목록 조회 제한 시간(20초)을 초과했습니다. 잠시 후 다시 시도해 주세요.', array('status' => 504));
    }

    /**
     * @return array{content: string, fileName: string, format: string}|WP_Error
     */
    public function download(string $owner, string $slug, string $file): array|WP_Error {
        $url = self::BASE . '/datasets/download/' . rawurlencode($owner) . '/' . rawurlencode($slug) . '/' . rawurlencode($file);
        $max_bytes = Dataset_Limits::max_bytes();

        $response = $this->safe_get($url, array(
            'timeout' => 30,
            'headers' => array('Authorization' => $this->auth_header()),
            // Aborts the transfer once this many bytes have arrived instead
            // of buffering an arbitrarily large file into PHP memory before
            // ever checking its size — the transports WordPress ships with
            // (cURL/streams) both enforce this during download, not after.
            'limit_response_size' => $max_bytes + 1,
        ));

        if (is_wp_error($response)) {
            return Dataset_Limits::response_hit_size_limit($response)
                ? new WP_Error('oj_code_lab_dataset_too_large', Dataset_Limits::too_large_message())
                : $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('oj_code_lab_kaggle_http_error', sprintf('Kaggle API 응답 오류 (HTTP %d)', $code));
        }

        // Normalized before the size check — see Url_Source::fetch() for
        // why (EUC-KR/CP949 → UTF-8 conversion can grow the byte count).
        $body = wp_remote_retrieve_body($response);
        // A transport may return HTTP 200 with a truncated body. The extra
        // sentinel byte distinguishes an exact-size file from an oversized one.
        if (strlen($body) > $max_bytes) {
            return new WP_Error('oj_code_lab_dataset_too_large', Dataset_Limits::too_large_message());
        }
        return Dataset_File::normalize($body, $file);
    }

    /**
     * @param array<string, scalar> $query
     * @return array<int|string, mixed>|WP_Error
     */
    private function request(string $path, array $query, ?float $deadline = null): array|WP_Error {
        $url = self::BASE . $path;
        if (array() !== $query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $response = $this->safe_get($url, array(
            'timeout' => 20,
            'limit_response_size' => 2_000_001,
            'headers' => array(
                'Authorization' => $this->auth_header(),
                'Accept' => 'application/json',
            ),
        ), $deadline);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('oj_code_lab_kaggle_http_error', sprintf('Kaggle API 응답 오류 (HTTP %d)', $code));
        }

        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > 2_000_000) { return new WP_Error('oj_code_lab_kaggle_bad_response', 'Kaggle 목록 응답이 너무 큽니다.', array('status' => 502)); }
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || (array() === $decoded && str_starts_with(ltrim($body), '{'))) {
            return new WP_Error('oj_code_lab_kaggle_bad_response', __('Kaggle 응답을 해석하지 못했습니다.', 'oj-code-lab'));
        }

        return $decoded;
    }

    private function safe_get(string $url, array $args, ?float $deadline = null): array|WP_Error {
        $args['redirection'] = 0;
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            if (null !== $deadline) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) { return self::file_list_timeout(); }
                $args['timeout'] = min((float) ($args['timeout'] ?? 20), $remaining);
            }
            $response = wp_safe_remote_get($url, $args);
            if (null !== $deadline && microtime(true) >= $deadline) { return self::file_list_timeout(); }
            if (is_wp_error($response)) { return new WP_Error('oj_code_lab_remote_failed', 'Kaggle에 연결하지 못했습니다. 잠시 후 다시 시도해 주세요.', array('status' => 502)); }
            $code = wp_remote_retrieve_response_code($response);
            if (! in_array($code, array(301, 302, 303, 307, 308), true)) { return $response; }
            $target = \WP_Http::make_absolute_url((string) wp_remote_retrieve_header($response, 'location'), $url);
            if ('https' !== strtolower((string) parse_url($target, PHP_URL_SCHEME))) { break; }
            $old_origin = array(parse_url($url, PHP_URL_HOST), parse_url($url, PHP_URL_PORT) ?: 443);
            $new_origin = array(parse_url($target, PHP_URL_HOST), parse_url($target, PHP_URL_PORT) ?: 443);
            if ($old_origin !== $new_origin) { unset($args['headers']['Authorization']); }
            $url = $target;
        }
        return new WP_Error('oj_code_lab_remote_redirect', 'Kaggle 다운로드 리다이렉트를 처리하지 못했습니다.', array('status' => 502));
    }

    private function auth_header(): string {
        return 'Basic ' . base64_encode($this->username . ':' . $this->key);
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $keys
     */
    private static function first_string(array $item, array $keys, string $default = ''): string {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && '' !== (string) $item[$key]) {
                return (string) $item[$key];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $keys
     * @return list<mixed>|null
     */
    private static function first_array(array $item, array $keys): ?array {
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_array($item[$key])) {
                return $item[$key];
            }
        }

        return null;
    }
}
