<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use TeachingTools\Addon\Seating\Seating_Layout_Repository;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * CRUD for named seating layouts a teacher saves for reuse. Every read/write
 * is scoped to get_current_user_id() — Seating_Layout_Repository never takes
 * an owner id from the request, so one teacher can't load or delete
 * another's saved layout by guessing its id.
 */
final class Seating_Layout_Controller {
    private const MAX_SEATS = 400; // matches the 20x20 cap the seating UI/engine already enforces

    public static function register_routes(): void {
        register_rest_route(
            'teaching-tools/v1',
            '/seating-layouts',
            array(
                array(
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => array(__CLASS__, 'list_layouts'),
                    'permission_callback' => array(Capabilities::class, 'can_host'),
                ),
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array(__CLASS__, 'save_layout'),
                    'permission_callback' => array(Capabilities::class, 'can_host'),
                ),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/seating-layouts/(?P<id>\d+)',
            array(
                array(
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => array(__CLASS__, 'get_layout'),
                    'permission_callback' => array(Capabilities::class, 'can_host'),
                ),
                array(
                    'methods' => WP_REST_Server::DELETABLE,
                    'callback' => array(__CLASS__, 'delete_layout'),
                    'permission_callback' => array(Capabilities::class, 'can_host'),
                ),
            )
        );
    }

    public static function list_layouts(WP_REST_Request $request) {
        $items = array_map(
            static function (array $row): array {
                return array(
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'rows' => (int) $row['rows_count'],
                    'cols' => (int) $row['cols_count'],
                    'updatedAt' => (string) $row['updated_at'],
                );
            },
            Seating_Layout_Repository::list_for_owner(get_current_user_id())
        );

        return rest_ensure_response(array('items' => $items));
    }

    public static function save_layout(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();

        $name = isset($params['name']) ? trim(sanitize_text_field((string) $params['name'])) : '';
        $rows = isset($params['rows']) ? (int) $params['rows'] : 0;
        $cols = isset($params['cols']) ? (int) $params['cols'] : 0;
        $seats = isset($params['seats']) && is_array($params['seats']) ? $params['seats'] : array();

        if ('' === $name) {
            return new WP_Error(
                'teaching_tools_seating_layout_name_required',
                __('배치 이름을 입력해 주세요.', 'teaching-tools'),
                array('status' => 400)
            );
        }
        if ($rows < 1 || $rows > 20 || $cols < 1 || $cols > 20) {
            return new WP_Error(
                'teaching_tools_seating_layout_invalid_size',
                __('행/열 크기가 올바르지 않습니다.', 'teaching-tools'),
                array('status' => 400)
            );
        }
        if (empty($seats) || count($seats) > self::MAX_SEATS) {
            return new WP_Error(
                'teaching_tools_seating_layout_invalid_seats',
                __('좌석 정보가 올바르지 않습니다.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        $normalized_seats = self::normalize_seats($seats);
        if (null === $normalized_seats) {
            return new WP_Error(
                'teaching_tools_seating_layout_invalid_seats',
                __('좌석 정보가 올바르지 않습니다.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        $saved = Seating_Layout_Repository::save(get_current_user_id(), $name, $rows, $cols, $normalized_seats);
        if (null === $saved) {
            return new WP_Error(
                'teaching_tools_seating_layout_save_failed',
                __('배치를 저장하지 못했습니다.', 'teaching-tools'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(self::present($saved));
    }

    public static function get_layout(WP_REST_Request $request) {
        $layout = Seating_Layout_Repository::find((int) $request['id'], get_current_user_id());
        if (null === $layout) {
            return self::not_found_error();
        }

        return rest_ensure_response(self::present($layout, true));
    }

    public static function delete_layout(WP_REST_Request $request) {
        $deleted = Seating_Layout_Repository::delete((int) $request['id'], get_current_user_id());
        if (! $deleted) {
            return self::not_found_error();
        }

        return rest_ensure_response(array('deleted' => true));
    }

    /**
     * @param array<int, mixed> $seats
     * @return list<array<string, mixed>>|null
     */
    private static function normalize_seats(array $seats): ?array {
        $normalized = array();
        foreach ($seats as $seat) {
            if (! is_array($seat) || ! isset($seat['id'], $seat['row'], $seat['col'])) {
                return null;
            }
            $student = self::normalize_student($seat['student'] ?? null, $seat['studentUserId'] ?? null);
            if (false === $student) {
                return null;
            }

            $normalized[] = array(
                'id' => (string) $seat['id'],
                'row' => (int) $seat['row'],
                'col' => (int) $seat['col'],
                'disabled' => (bool) ($seat['disabled'] ?? false),
                'locked' => (bool) ($seat['locked'] ?? false),
                'student' => $student,
            );
        }

        return $normalized;
    }

    /** @param array<string, mixed> $row */
    private static function present(array $row, bool $include_seats = false): array {
        $presented = array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'rows' => (int) $row['rows_count'],
            'cols' => (int) $row['cols_count'],
            'updatedAt' => (string) $row['updated_at'],
        );

        if ($include_seats) {
            $seats = json_decode((string) $row['seats'], true);
            $presented['seats'] = array_values(array_filter(array_map(
                static function ($seat): ?array {
                    if (! is_array($seat)) {
                        return null;
                    }
                    $student = self::normalize_student($seat['student'] ?? null, $seat['studentUserId'] ?? null);
                    if (false === $student) {
                        $student = null;
                    }
                    unset($seat['studentUserId']);
                    $seat['student'] = $student;

                    return $seat;
                },
                is_array($seats) ? $seats : array()
            )));
        }

        return $presented;
    }

    /**
     * @return array{
     *     id: int|string,
     *     name: string,
     *     displayName: string,
     *     managementName: string,
     *     anonymousName: string
     * }|null|false
     */
    private static function normalize_student($student, $legacy_id = null) {
        if (null === $student || '' === $student) {
            return null;
        }

        if (is_array($student)) {
            $id = $student['id'] ?? null;
            $name = self::student_label($student['name'] ?? $student['displayName'] ?? '');
            $display_name = self::student_label($student['displayName'] ?? $name);
            $management_name = self::student_label($student['managementName'] ?? $display_name);
            $anonymous_name = self::student_label($student['anonymousName'] ?? $name);
        } else {
            $id = $legacy_id;
            $name = self::student_label($student);
            $display_name = $name;
            $management_name = $name;
            $anonymous_name = $name;
        }
        if ('' === $name) {
            return false;
        }
        $display_name = '' !== $display_name ? $display_name : $name;
        $management_name = '' !== $management_name ? $management_name : $display_name;
        $anonymous_name = '' !== $anonymous_name ? $anonymous_name : $name;

        if (is_numeric($id) && (int) $id > 0) {
            $id = (int) $id;
        } elseif (is_string($id) && preg_match('/\Amanual:[a-z0-9:.-]{1,80}\z/i', $id)) {
            $id = sanitize_key(str_replace(':', '-', $id));
            $id = 'manual:' . substr($id, strlen('manual-'));
        } elseif (null !== $legacy_id && (int) $legacy_id > 0) {
            $id = (int) $legacy_id;
        } else {
            // Old solo layouts did not persist an id. Give them an explicit
            // non-platform identity without pretending it is a learner id.
            $id = 'manual:legacy:' . substr(hash('sha256', $name), 0, 16);
        }

        return array(
            'id' => $id,
            'name' => $name,
            'displayName' => $display_name,
            'managementName' => $management_name,
            'anonymousName' => $anonymous_name,
        );
    }

    private static function student_label($value): string {
        if (! is_scalar($value)) {
            return '';
        }

        return trim(sanitize_text_field((string) $value));
    }

    private static function not_found_error(): WP_Error {
        return new WP_Error(
            'teaching_tools_seating_layout_not_found',
            __('저장된 배치를 찾을 수 없습니다.', 'teaching-tools'),
            array('status' => 404)
        );
    }
}
