<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);
namespace OjCodeLab\Database;
use WP_Error;
use OjCodeLab\Support\Database_Packet;

final class Notebook_Repository {
    public const MAX_PAYLOAD_BYTES = 16_000_000;
    public const FORMAT_VERSION = 2;
    public static function find_for_user(int $user_id): array|WP_Error {
        global $wpdb;
        $table = Installer::notebooks_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT cells, updated_at, revision FROM {$table} WHERE user_id = %d", $user_id), ARRAY_A);
        if ('' !== $wpdb->last_error) {
            return self::failure('read');
        }
        if (null === $row) {
            return array('cells' => array(), 'updatedAt' => null, 'revision' => 0, 'formatVersion' => self::FORMAT_VERSION);
        }
        $cells = json_decode((string) $row['cells'], true);
        if (! is_array($cells) || ! array_is_list($cells)) {
            return new WP_Error('oj_code_lab_notebook_invalid_data', __('저장된 노트북을 해석하지 못했습니다. 운영자에게 문의해 주세요.', 'oj-code-lab'), array('status' => 500));
        }
        $cells = self::normalize_cell_types($cells, 500);
        if (is_wp_error($cells)) { return $cells; }
        return array('cells' => $cells, 'updatedAt' => $row['updated_at'], 'revision' => (int) $row['revision'], 'formatVersion' => self::FORMAT_VERSION);
    }

    /** Atomically compare and advance the version. A client must send its last read revision. */
    public static function save_for_user(int $user_id, array $cells, int $expected_revision): array|WP_Error {
        global $wpdb;
        $table = Installer::notebooks_table();
        $now = current_time('mysql');
        $cells = self::normalize_cell_types($cells, 400);
        if (is_wp_error($cells)) { return $cells; }
        $encoded = wp_json_encode(array_values($cells));
        if (false === $encoded || $expected_revision < 0) {
            return self::failure('save');
        }
        $limit = Database_Packet::payload_limit(self::MAX_PAYLOAD_BYTES);
        if (is_wp_error($limit)) { return $limit; }
        if (strlen($encoded) > $limit) {
            return new WP_Error('oj_code_lab_payload_too_large', sprintf(
                __('현재 서버에서 저장할 내용은 최대 약 %sMB입니다. 내용을 다운로드하거나 큰 실행 결과를 줄인 뒤 다시 시도해 주세요.', 'oj-code-lab'),
                round($limit / 1_000_000, 1)
            ), array('status' => 413, 'maxBytes' => $limit));
        }
        $has_error = 0;
        foreach ($cells as $cell) {
            if ('text' === $cell['type']) { continue; }
            foreach ($cell['outputs'] ?? array() as $output) {
                if ('error' === ($output['type'] ?? null)) { $has_error = 1; break 2; }
            }
        }
        if (0 === $expected_revision) {
            // IGNORE only handles the competing first insert; it never updates another tab's row.
            $result = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (user_id, cells, updated_at, revision, cell_count, has_error) VALUES (%d, %s, %s, 1, %d, %d)",
                $user_id, $encoded, $now, count($cells), $has_error
            ));
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET cells = %s, updated_at = %s, revision = revision + 1, cell_count = %d, has_error = %d WHERE user_id = %d AND revision = %d",
                $encoded, $now, count($cells), $has_error, $user_id, $expected_revision
            ));
        }
        if (false === $result) { return self::failure('save'); }
        if (0 === $result) {
            return new WP_Error('oj_code_lab_notebook_conflict', __('다른 탭이나 기기에서 노트북이 변경되었습니다. 현재 내용을 다운로드한 뒤 최신 노트북을 불러와 주세요.', 'oj-code-lab'), array('status' => 409));
        }
        return array('updatedAt' => $now, 'revision' => $expected_revision + 1, 'formatVersion' => self::FORMAT_VERSION);
    }

    /** Legacy cells are code; text never carries executable outputs. No database rewrite is needed on read. */
    private static function normalize_cell_types(array $cells, int $status): array|WP_Error {
        foreach ($cells as &$cell) {
            if (! is_array($cell) || (array_key_exists('type', $cell) && ! in_array($cell['type'], array('code', 'text'), true))) {
                return new WP_Error('oj_code_lab_invalid_cell_type', __('지원하지 않는 셀 종류가 포함되어 있습니다. 내용을 유지한 채 운영자에게 문의해 주세요.', 'oj-code-lab'), array('status' => $status));
            }
            $cell['type'] = $cell['type'] ?? 'code';
            if ('text' === $cell['type']) { $cell['outputs'] = array(); }
        }
        unset($cell);
        return array_values($cells);
    }

    /** Summary-only projection: never hydrate every learner's image payload. */
    public static function summaries_for_users(array $user_ids): array|WP_Error {
        if (array() === $user_ids) { return array(); }
        global $wpdb;
        $table = Installer::notebooks_table();
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, cell_count, has_error, updated_at FROM {$table} WHERE user_id IN ({$placeholders})", $user_ids), ARRAY_A);
        if ('' !== $wpdb->last_error) { return self::failure('read'); }
        $result = array();
        foreach ($rows as $row) {
            $result[(int) $row['user_id']] = array(
                'cellCount' => null === $row['cell_count'] ? null : (int) $row['cell_count'],
                'hasError' => null === $row['has_error'] ? null : (bool) $row['has_error'],
                'updatedAt' => $row['updated_at'],
            );
        }
        return $result;
    }

    public static function backfill_summaries(): void {
        global $wpdb;
        $table = Installer::notebooks_table();
        // Bounded, server-side migration; does not send large notebooks into PHP.
        $wpdb->query("UPDATE {$table} SET cell_count = CASE WHEN JSON_VALID(cells) THEN JSON_LENGTH(cells) ELSE 0 END,
            has_error = CASE WHEN JSON_VALID(cells) THEN JSON_SEARCH(cells, 'one', 'error', NULL, '$[*].outputs[*].type') IS NOT NULL ELSE 1 END
            WHERE cell_count IS NULL LIMIT 100");
        if ('' === $wpdb->last_error && $wpdb->get_var("SELECT 1 FROM {$table} WHERE cell_count IS NULL LIMIT 1")) {
            if (! wp_next_scheduled('oj_code_lab_summary_backfill')) {
                wp_schedule_single_event(time() + 60, 'oj_code_lab_summary_backfill');
            }
        }
    }

    private static function failure(string $operation): WP_Error {
        return new WP_Error('oj_code_lab_notebook_' . $operation . '_failed', __('노트북을 처리하지 못했습니다. 내용을 유지한 채 다시 시도해 주세요.', 'oj-code-lab'), array('status' => 500));
    }
}
