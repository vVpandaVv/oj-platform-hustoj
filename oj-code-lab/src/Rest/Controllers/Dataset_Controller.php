<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Rest\Controllers;

use OjCodeLab\Database\Dataset_Repository;
use OjCodeLab\Datasets\Dataset_Cache;
use OjCodeLab\Datasets\Kaggle_Client;
use OjCodeLab\Datasets\Kaggle_Settings;
use OjCodeLab\Datasets\Sample_Dataset_Seeder;
use OjCodeLab\Datasets\Upload_Store;
use OjCodeLab\Datasets\Url_Source;
use OjCodeLab\Support\Capabilities;
use OjCodeLab\Support\Dataset_File;
use WP_Error;
use OjCodeLab\Support\Request_Limits;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Integrated "데이터 불러오기" interface (docs/README §데이터 불러오기): one
 * shared recommended-dataset list and one student-facing import call,
 * regardless of whether a given entry actually comes from Kaggle, a direct
 * URL (공공데이터포털/GitHub/자체 서버), or a teacher-uploaded file. A
 * student never talks to Kaggle directly or sees any credential — every
 * Kaggle call is proxied server-side through Kaggle_Client using the
 * operator-configured Kaggle_Settings.
 */
final class Dataset_Controller {
    public static function register_routes(): void {
        register_rest_route('oj-code-lab/v1', '/datasets/recommended', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'list_recommended'),
                'permission_callback' => array(__CLASS__, 'can_search'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'create_recommended'),
                'args' => self::request_args(),
                'permission_callback' => array(__CLASS__, 'can_manage_datasets'),
            ),
        ));

        register_rest_route('oj-code-lab/v1', '/datasets/recommended/upload', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'create_recommended_upload'),
                'args' => self::request_args(),
            'permission_callback' => array(__CLASS__, 'can_manage_datasets'),
        ));

        register_rest_route('oj-code-lab/v1', '/datasets/recommended/restore-samples', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'restore_samples'),
            'permission_callback' => array(__CLASS__, 'can_manage_datasets'),
        ));

        register_rest_route('oj-code-lab/v1', '/datasets/recommended/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => array(__CLASS__, 'delete_recommended'),
            'permission_callback' => array(__CLASS__, 'can_manage_datasets'),
            'args' => array('id' => array('required' => true)),
        ));

        register_rest_route('oj-code-lab/v1', '/datasets/recommended/(?P<id>\d+)/import', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'import_recommended'),
            'permission_callback' => array(__CLASS__, 'can_use'),
            'args' => array('id' => array('required' => true)),
        ));

        register_rest_route('oj-code-lab/v1', '/datasets/search', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'search'),
                'args' => self::request_args(),
            'permission_callback' => array(__CLASS__, 'can_search'),
        ));

        register_rest_route('oj-code-lab/v1', '/datasets/files', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'files'),
                'args' => self::request_args(),
            'permission_callback' => array(__CLASS__, 'can_search'),
        ));

        register_rest_route('oj-code-lab/v1', '/datasets/import', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'import'),
                'args' => self::request_args(),
            'permission_callback' => array(__CLASS__, 'can_use'),
        ));
    }

    private static function request_args(): array {
        $args = array();
        foreach (array('label' => 191, 'icon' => 8, 'source' => 20, 'kaggleOwner' => 191, 'kaggleSlug' => 191,
            'sourceUrl' => 500, 'fileName' => 255, 'q' => 200, 'owner' => 191, 'slug' => 191, 'file' => 255) as $key => $limit) {
            $args[$key] = array('type' => 'string', 'maxLength' => $limit);
        }
        return $args;
    }

    public static function can_use(): bool|WP_Error {
        return Capabilities::can_use() ? Request_Limits::check() : new WP_Error('oj_code_lab_forbidden', __('이용 권한이 없습니다.', 'oj-code-lab'), array('status' => 403));
    }

    public static function can_manage_datasets(): bool|WP_Error {
        return Capabilities::can_manage_datasets() ? Request_Limits::check() : new WP_Error('oj_code_lab_forbidden', __('추천 데이터 관리 권한이 없습니다.', 'oj-code-lab'), array('status' => 403));
    }

    public static function can_search(): bool|WP_Error {
        return (Capabilities::can_use() || Capabilities::can_manage_datasets())
            ? Request_Limits::check() : new WP_Error('oj_code_lab_forbidden', __('이용 권한이 없습니다.', 'oj-code-lab'), array('status' => 403));
    }

    public static function list_recommended(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $datasets = Dataset_Repository::all();
        if (is_wp_error($datasets)) { return $datasets; }
        if (! Capabilities::can_manage_datasets()) {
            foreach ($datasets as &$dataset) {
                // A source URL may contain a serviceKey or signed query.
                // Learners import by id and never need the original URL.
                $dataset['sourceUrl'] = null;
            }
            unset($dataset);
        }

        return rest_ensure_response($datasets);
    }

    public static function create_recommended(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $label = trim((string) $request->get_param('label'));
        $icon = trim((string) $request->get_param('icon'));
        $source = trim((string) $request->get_param('source')) ?: 'kaggle';

        if ('' === $label) {
            return new WP_Error('oj_code_lab_invalid_dataset', __('이름을 입력해 주세요.', 'oj-code-lab'), array('status' => 400));
        }

        $fields = array('label' => $label, 'icon' => $icon, 'source' => $source, 'createdBy' => get_current_user_id());

        if ('kaggle' === $source) {
            $owner = trim((string) $request->get_param('kaggleOwner'));
            $slug = trim((string) $request->get_param('kaggleSlug'));
            if ('' === $owner || '' === $slug) {
                return new WP_Error('oj_code_lab_invalid_dataset', __('Kaggle 데이터셋 정보(owner/slug)를 입력해 주세요.', 'oj-code-lab'), array('status' => 400));
            }
            $fields['kaggleOwner'] = $owner;
            $fields['kaggleSlug'] = $slug;
            $fields['fileName'] = self::optional_string($request, 'fileName');
        } elseif ('url' === $source) {
            $url = trim((string) $request->get_param('sourceUrl'));
            if ('' === $url) {
                return new WP_Error('oj_code_lab_invalid_dataset', __('데이터 URL을 입력해 주세요.', 'oj-code-lab'), array('status' => 400));
            }
            $fields['sourceUrl'] = Url_Source::normalize($url);
            $fields['fileName'] = self::optional_string($request, 'fileName');
        } else {
            return new WP_Error('oj_code_lab_invalid_dataset', __('알 수 없는 데이터 출처입니다.', 'oj-code-lab'), array('status' => 400));
        }

        $id = Dataset_Repository::create($fields);
        if (is_wp_error($id)) {
            return $id;
        }

        return rest_ensure_response(Dataset_Repository::find($id));
    }

    public static function create_recommended_upload(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $label = trim((string) $request->get_param('label'));
        $icon = trim((string) $request->get_param('icon'));
        if ('' === $label) {
            return new WP_Error('oj_code_lab_invalid_dataset', __('이름을 입력해 주세요.', 'oj-code-lab'), array('status' => 400));
        }

        $files = $request->get_file_params();
        $file = $files['file'] ?? null;
        if (! is_array($file)) {
            return new WP_Error('oj_code_lab_invalid_dataset', __('업로드할 파일을 선택해 주세요.', 'oj-code-lab'), array('status' => 400));
        }

        $stored = Upload_Store::store($file);
        if (is_wp_error($stored)) {
            return $stored;
        }

        $id = Dataset_Repository::create(array(
            'label' => $label,
            'icon' => $icon,
            'source' => 'upload',
            'uploadKey' => $stored['key'],
            'fileName' => $stored['fileName'],
            'createdBy' => get_current_user_id(),
        ));
        if (is_wp_error($id)) {
            Upload_Store::delete($stored['key']);
            return $id;
        }

        return rest_ensure_response(Dataset_Repository::find($id));
    }

    /**
     * Re-inserts any bundled sample dataset (Datasets\Sample_Dataset_Seeder)
     * that isn't currently in the recommended list — whether it was never
     * seeded or a teacher deleted it. Never duplicates a sample that's
     * still there.
     */
    public static function restore_samples(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $restored = Sample_Dataset_Seeder::restore_missing();
        if (is_wp_error($restored)) {
            return $restored;
        }

        $datasets = Dataset_Repository::all();
        if (is_wp_error($datasets)) { return $datasets; }
        return rest_ensure_response(array('restored' => $restored, 'datasets' => $datasets));
    }

    public static function delete_recommended(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $result = Dataset_Repository::delete((int) $request->get_param('id'));
        return is_wp_error($result) ? $result : rest_ensure_response(array('deleted' => true));
    }

    public static function search(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $client = self::client();
        if (is_wp_error($client)) {
            return $client;
        }

        $query = trim((string) $request->get_param('q'));
        if ('' === $query) {
            return rest_ensure_response(array());
        }

        $result = $client->search($query);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function files(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $client = self::client();
        if (is_wp_error($client)) {
            return $client;
        }

        $owner = trim((string) $request->get_param('owner'));
        $slug = trim((string) $request->get_param('slug'));
        if ('' === $owner || '' === $slug) {
            return new WP_Error('oj_code_lab_invalid_dataset_ref', __('owner와 slug가 필요합니다.', 'oj-code-lab'), array('status' => 400));
        }

        $result = $client->files($owner, $slug);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    public static function import(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $owner = trim((string) $request->get_param('owner'));
        $slug = trim((string) $request->get_param('slug'));
        $file = trim((string) $request->get_param('file'));

        if ('' === $owner || '' === $slug || '' === $file) {
            return new WP_Error('oj_code_lab_invalid_dataset_ref', __('owner, slug, file가 필요합니다.', 'oj-code-lab'), array('status' => 400));
        }

        return self::import_kaggle_file($owner, $slug, $file);
    }

    public static function import_recommended(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $dataset = Dataset_Repository::find((int) $request->get_param('id'));
        if (is_wp_error($dataset)) { return $dataset; }
        if (null === $dataset) {
            return new WP_Error('oj_code_lab_dataset_not_found', __('추천 데이터를 찾을 수 없습니다.', 'oj-code-lab'), array('status' => 404));
        }

        $result = match ($dataset['source']) {
            'kaggle' => self::import_recommended_kaggle($dataset),
            'url' => self::import_recommended_url($dataset),
            'upload' => self::import_recommended_upload($dataset),
            default => new WP_Error('oj_code_lab_invalid_dataset', __('알 수 없는 데이터 출처입니다.', 'oj-code-lab'), array('status' => 500)),
        };

        if (is_wp_error($result)) {
            return $result;
        }

        $data = $result->get_data();
        $data['label'] = $dataset['label'];

        return rest_ensure_response($data);
    }

    /** @param array<string, mixed> $dataset */
    private static function import_recommended_kaggle(array $dataset): WP_REST_Response|WP_Error {
        $file = $dataset['fileName'];
        if (null === $file) {
            // No pinned file: select the first supported file, preferring
            // plain CSV/JSONL over an archive that may be ambiguous.
            $client = self::client();
            if (is_wp_error($client)) {
                return $client;
            }
            $files = $client->files($dataset['kaggleOwner'], $dataset['kaggleSlug']);
            if (is_wp_error($files)) {
                return $files;
            }
            if (array() === $files) {
                return new WP_Error('oj_code_lab_dataset_empty', __('이 데이터셋에서 파일을 찾지 못했습니다.', 'oj-code-lab'), array('status' => 502));
            }
            $plain = current(array_filter($files, static fn (array $f): bool => 1 === preg_match('/\.(csv|jsonl|ndjson)$/iD', $f['name'])));
            $supported = $plain ?: current(array_filter($files, static fn (array $f): bool => Dataset_File::supported_name($f['name'])));
            if (! $supported) { return new WP_Error('oj_code_lab_dataset_format', '이 데이터셋에 CSV·JSONL·ZIP 파일이 없습니다.', array('status' => 415)); }
            $file = $supported['name'];
        }

        return self::import_kaggle_file($dataset['kaggleOwner'], $dataset['kaggleSlug'], $file);
    }

    /** @param array<string, mixed> $dataset */
    private static function import_recommended_url(array $dataset): WP_REST_Response|WP_Error {
        $url = (string) $dataset['sourceUrl'];

        $cached = self::cached_file($url);
        if (null !== $cached) {
            return rest_ensure_response($cached);
        }

        $fetched = Url_Source::fetch($url);
        if (is_wp_error($fetched)) {
            return $fetched;
        }

        self::cache_file($url, $fetched);
        return rest_ensure_response($fetched);
    }

    /** @param array<string, mixed> $dataset */
    private static function import_recommended_upload(array $dataset): WP_REST_Response|WP_Error {
        $key = Dataset_Repository::upload_key($dataset['id']);
        if (is_wp_error($key)) { return $key; }
        if (null === $key) {
            return new WP_Error('oj_code_lab_dataset_not_found', __('업로드된 파일을 찾을 수 없습니다.', 'oj-code-lab'), array('status' => 404));
        }

        $content = Upload_Store::read($key);
        if (is_wp_error($content)) { return $content; }
        if (null === $content) {
            return new WP_Error('oj_code_lab_dataset_not_found', __('업로드된 파일을 찾을 수 없습니다.', 'oj-code-lab'), array('status' => 404));
        }

        return rest_ensure_response(Dataset_File::normalize($content, $dataset['fileName'] ?? 'data.csv'));
    }

    private static function import_kaggle_file(string $owner, string $slug, string $file): WP_REST_Response|WP_Error {
        $cache_key = $owner . '/' . $slug . '/' . $file;
        $cached = self::cached_file($cache_key);
        if (null !== $cached) {
            return rest_ensure_response($cached);
        }

        $client = self::client();
        if (is_wp_error($client)) {
            return $client;
        }

        $result = $client->download($owner, $slug, $file);
        if (is_wp_error($result)) {
            return $result;
        }

        self::cache_file($cache_key, $result);
        return rest_ensure_response($result);
    }

    /** Cache normalized content together with its actual ZIP member name and format. */
    private static function cached_file(string $key): ?array {
        $cached = Dataset_Cache::get('dataset-file-v2:' . $key);
        if (null === $cached) { return null; }
        $file = json_decode($cached, true, 4);
        if (! is_array($file) || ! is_string($file['content'] ?? null) || ! is_string($file['fileName'] ?? null)
            || ! in_array($file['format'] ?? null, array('csv', 'jsonl'), true)) { return null; }
        $normalized = Dataset_File::normalize($file['content'], $file['fileName']);
        return is_wp_error($normalized) || $normalized['format'] !== $file['format'] ? null : $normalized;
    }

    private static function cache_file(string $key, array $file): void {
        // JSON wrapping and SQL quoting allocate additional copies. Large
        // imports still work; avoid that cache overhead on 128 MiB PHP hosts.
        if (strlen($file['content']) > min(1_048_576, intdiv(\OjCodeLab\Support\Dataset_Limits::max_bytes(), 4))) { return; }
        $encoded = wp_json_encode($file);
        // Envelope overhead may exceed the cache/packet cap; put then skips
        // caching while the already validated import still succeeds.
        if (is_string($encoded)) { Dataset_Cache::put('dataset-file-v2:' . $key, $encoded); }
    }

    private static function client(): Kaggle_Client|WP_Error {
        if (! Kaggle_Settings::is_configured()) {
            return new WP_Error('oj_code_lab_kaggle_not_configured', __('아직 Kaggle 연동이 설정되지 않았습니다. 운영자에게 문의하세요.', 'oj-code-lab'), array('status' => 503));
        }

        return new Kaggle_Client(Kaggle_Settings::username(), Kaggle_Settings::key());
    }

    private static function optional_string(WP_REST_Request $request, string $key): ?string {
        $value = $request->get_param($key);

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
