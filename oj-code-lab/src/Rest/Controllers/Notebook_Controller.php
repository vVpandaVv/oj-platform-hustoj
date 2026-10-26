<?php
/*
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Additional permission: see PLATFORM-LINKING-EXCEPTION.txt.
 */
declare(strict_types=1);

namespace OjCodeLab\Rest\Controllers;

use OjCodeLab\Database\Notebook_Repository;
use OjCodeLab\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Backs each learner's own scratch notebook (Addon\Lab_Page_Handler,
 * resources/js/lab.js). Every route is scoped to the current WordPress
 * user id — a student never reads or writes another student's row (see
 * Roster_Controller for the separate, capability-gated teacher path).
 */
final class Notebook_Controller {
    // An absolute API cap; the repository also applies the current DB packet limit.
    private const MAX_CELLS = 300;
    private const MAX_PAYLOAD_BYTES = Notebook_Repository::MAX_PAYLOAD_BYTES;

    public static function register_routes(): void {
        register_rest_route('oj-code-lab/v1', '/notebook', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get'),
                'permission_callback' => array(__CLASS__, 'can_use'),
            ),
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array(__CLASS__, 'save'),
                'permission_callback' => array(__CLASS__, 'can_use'),
            ),
        ));
    }

    public static function can_use(): bool|WP_Error {
        return Capabilities::can_use() ?: new WP_Error(
            'oj_code_lab_forbidden',
            __('이용 권한이 없습니다.', 'oj-code-lab'),
            array('status' => 403)
        );
    }

    public static function get(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $notebook = Notebook_Repository::find_for_user(get_current_user_id());

        return rest_ensure_response($notebook);
    }

    public static function save(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (Notebook_Repository::FORMAT_VERSION !== $request->get_param('formatVersion')) {
            return new WP_Error(
                'oj_code_lab_notebook_format_changed',
                __('노트북 편집기가 업데이트되었습니다. 현재 내용을 다운로드한 뒤 페이지를 새로고침해 주세요.', 'oj-code-lab'),
                array('status' => 409, 'formatVersion' => Notebook_Repository::FORMAT_VERSION)
            );
        }
        $cells = $request->get_param('cells');
        if (! is_array($cells)) {
            return new WP_Error('oj_code_lab_invalid_cells', __('셀 목록 형식이 올바르지 않습니다.', 'oj-code-lab'), array('status' => 400));
        }

        if (count($cells) > self::MAX_CELLS) {
            return new WP_Error('oj_code_lab_too_many_cells', __('셀 개수가 너무 많습니다.', 'oj-code-lab'), array('status' => 400));
        }

        foreach ($cells as $cell) {
            if (is_array($cell) && array_key_exists('type', $cell) && ! in_array($cell['type'], array('code', 'text'), true)) {
                return new WP_Error('oj_code_lab_invalid_cell_type', __('지원하지 않는 셀 종류입니다.', 'oj-code-lab'), array('status' => 400));
            }
        }

        $encoded = wp_json_encode(array_values($cells));
        if (false === $encoded || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            return new WP_Error(
                'oj_code_lab_payload_too_large',
                sprintf(
                    /* translators: %s: maximum size in megabytes */
                    __('저장할 내용이 너무 큽니다 (최대 %sMB). 그림이 많이 포함된 셀의 실행 결과를 지우고 다시 시도해 보세요.', 'oj-code-lab'),
                    round(self::MAX_PAYLOAD_BYTES / 1_000_000, 1)
                ),
                array('status' => 413, 'maxBytes' => self::MAX_PAYLOAD_BYTES)
            );
        }

        $revision = $request->get_param('revision');
        if (! is_int($revision) || $revision < 0 || $revision > 9007199254740990) {
            return new WP_Error('oj_code_lab_revision_required', __('최신 노트북을 불러온 후 저장해 주세요.', 'oj-code-lab'), array('status' => 428));
        }
        $updated_at = Notebook_Repository::save_for_user(get_current_user_id(), self::sanitize_cells($cells), $revision);

        if (is_wp_error($updated_at)) {
            return $updated_at;
        }

        return rest_ensure_response($updated_at);
    }

    /**
     * @param array<int, mixed> $cells
     * @return list<array<string, mixed>>
     */
    public static function sanitize_cells(array $cells): array {
        $sanitized = array();

        foreach ($cells as $cell) {
            if (! is_array($cell)) {
                continue;
            }

            $cell_type = 'text' === ($cell['type'] ?? 'code') ? 'text' : 'code';
            $outputs = array();
            if ('code' === $cell_type && is_array($cell['outputs'] ?? null)) {
                foreach ($cell['outputs'] as $output) {
                    if (! is_array($output)) {
                        continue;
                    }

                    $type = is_string($output['type'] ?? null) ? $output['type'] : '';
                    // 'input' is the echoed value a student typed into the
                    // inline stdin box (resources/js/lab.js showInlineInput())
                    // — persisted like any other output so it survives save/reload.
                    if (! in_array($type, array('stream', 'error', 'image', 'input'), true)) {
                        continue;
                    }

                    $entry = array('type' => $type);
                    if ('image' === $type) {
                        $entry['image'] = is_string($output['image'] ?? null) ? $output['image'] : '';
                    } else {
                        $entry['text'] = is_string($output['text'] ?? null) ? $output['text'] : '';
                    }

                    $outputs[] = $entry;
                }
            }

            $sanitized[] = array(
                'id' => is_string($cell['id'] ?? null) ? $cell['id'] : wp_generate_uuid4(),
                'type' => $cell_type,
                'code' => is_string($cell['code'] ?? null) ? $cell['code'] : '',
                'outputs' => $outputs,
            );
        }

        return $sanitized;
    }
}
