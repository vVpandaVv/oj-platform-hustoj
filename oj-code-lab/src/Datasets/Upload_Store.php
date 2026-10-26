<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Datasets;

use OjCodeLab\Database\Installer;
use OjCodeLab\Support\Dataset_File;
use OjCodeLab\Support\Dataset_Limits;
use WP_Error;
use OjCodeLab\Support\Database_Lock;
use OjCodeLab\Support\Database_Packet;

/**
 * Stores a teacher-uploaded dataset file ("자체 서버 데이터") in the
 * database rather than a private filesystem directory (docs/README §데이터
 * 불러오기) — one less thing for an operator to provision, and classroom
 * CSVs (see Support\Dataset_Limits) are a trivial amount of row storage. Files are
 * addressed only by an opaque generated key; the original filename is kept
 * solely as display text (Dataset_Repository's file_name column).
 */
final class Upload_Store {
    /**
     * @param array{name: string, tmp_name: string, error: int, size: int} $file one entry from $_FILES
     * @return array{key: string, fileName: string}|WP_Error
     */
    public static function store(array $file): array|WP_Error {
        // UPLOAD_ERR_INI_SIZE/UPLOAD_ERR_FORM_SIZE happen when PHP's own
        // upload_max_filesize/post_max_size (checked before this addon's
        // code ever runs) already rejected an oversized file — call that
        // out specifically rather than a generic upload-failed message.
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (in_array($error, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
            return new WP_Error(
                'oj_code_lab_dataset_too_large',
                Dataset_Limits::too_large_message(Dataset_Limits::php_upload_max_bytes()),
                array('status' => 413, 'maxBytes' => Dataset_Limits::php_upload_max_bytes())
            );
        }
        if (UPLOAD_ERR_OK !== $error) {
            return new WP_Error('oj_code_lab_upload_failed', __('파일 업로드에 실패했습니다.', 'oj-code-lab'), array('status' => 400));
        }

        $limit = Dataset_Limits::max_bytes();
        if (($file['size'] ?? 0) > $limit) {
            return new WP_Error('oj_code_lab_dataset_too_large', Dataset_Limits::too_large_message($limit), array('status' => 413, 'maxBytes' => $limit));
        }

        if (! is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('oj_code_lab_upload_failed', __('업로드된 파일을 확인하지 못했습니다.', 'oj-code-lab'), array('status' => 400));
        }

        $content = file_get_contents($file['tmp_name'], false, null, 0, $limit + 1);
        if (false === $content) {
            return new WP_Error('oj_code_lab_upload_failed', __('파일을 읽지 못했습니다.', 'oj-code-lab'), array('status' => 500));
        }

        // Excel's "CSV로 저장" on Korean Windows still commonly exports
        // EUC-KR/CP949, not UTF-8 — normalize so pd.read_csv() just works
        // for the student without them needing to know an encoding to pass.
        $normalized = Dataset_File::normalize($content, (string) $file['name']);
        if (is_wp_error($normalized)) { return $normalized; }

        $key = self::store_bytes($normalized['content']);
        if (is_wp_error($key)) {
            return $key;
        }

        return array('key' => $key, 'fileName' => sanitize_file_name($normalized['fileName']));
    }

    /**
     * Like store(), but for content this addon already has in memory
     * rather than an incoming $_FILES entry — used only to seed the
     * bundled sample datasets (Datasets\Sample_Dataset_Seeder).
     */
    public static function store_bytes(string $content): string|WP_Error {
        // Also covers UTF-8 expansion after a Korean CSV upload.
        $limit = Database_Packet::payload_limit(Dataset_Limits::configured_max_bytes());
        if (is_wp_error($limit)) { return $limit; }
        if (strlen($content) > $limit) {
            return new WP_Error('oj_code_lab_dataset_too_large', Dataset_Limits::too_large_message($limit), array('status' => 413, 'maxBytes' => $limit));
        }
        global $wpdb;
        if (! Database_Lock::acquire('uploads')) {
            return new WP_Error('oj_code_lab_upload_busy', '다른 업로드를 처리 중입니다. 다시 시도해 주세요.', array('status' => 503));
        }
        try {
            $table = Installer::uploads_table();
            $used = $wpdb->get_var("SELECT COALESCE(SUM(OCTET_LENGTH(content)), 0) FROM {$table}");
            if ('' !== $wpdb->last_error) { return new WP_Error('oj_code_lab_upload_save_failed', '업로드 용량을 확인하지 못했습니다.', array('status' => 500)); }
            if ((int) $used + strlen($content) > max(1, (int) apply_filters('oj_code_lab_uploads_max_bytes', 256_000_000))) {
                return new WP_Error('oj_code_lab_upload_quota', '사이트 업로드 저장 공간이 가득 찼습니다. 불필요한 추천 데이터를 삭제해 주세요.', array('status' => 413));
            }
            $key = wp_generate_uuid4();
            $result = $wpdb->query($wpdb->prepare("INSERT INTO {$table} (upload_key, content, created_at) VALUES (%s, %s, %s)", $key, $content, gmdate('Y-m-d H:i:s')));
            if (false === $result) { return new WP_Error('oj_code_lab_upload_save_failed', '파일을 저장하지 못했습니다. 다시 시도해 주세요.', array('status' => 500)); }
            return $key;
        } finally { Database_Lock::release('uploads'); }
    }

    public static function read(string $key): string|WP_Error|null {
        global $wpdb;

        $table = Installer::uploads_table();
        $content = $wpdb->get_var($wpdb->prepare("SELECT content FROM {$table} WHERE upload_key = %s", $key));

        if ('' !== $wpdb->last_error) { return new WP_Error('oj_code_lab_upload_read_failed', __('업로드된 파일을 읽지 못했습니다. 잠시 후 다시 시도해 주세요.', 'oj-code-lab'), array('status' => 500)); }
        return null === $content ? null : (string) $content;
    }

    public static function delete(string $key): void {
        global $wpdb;

        $table = Installer::uploads_table();
        $wpdb->delete($table, array('upload_key' => $key), array('%s'));
    }
}
