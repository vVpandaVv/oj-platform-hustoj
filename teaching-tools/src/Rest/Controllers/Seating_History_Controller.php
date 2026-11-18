<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use TeachingTools\Addon\Seating\Seating_History_Repository;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class Seating_History_Controller {
    public static function register_routes(): void {
        foreach (array('/seating-history/query' => 'query', '/seating-history' => 'confirm') as $route => $callback) {
            register_rest_route('teaching-tools/v1', $route, array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, $callback),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            ));
        }
    }

    public static function query(WP_REST_Request $request) {
        $scope = self::scope($request);
        if (is_wp_error($scope)) { return $scope; }
        $items = Seating_History_Repository::recent(get_current_user_id(), $scope['key']);
        if (null === $items) { return self::error('최근 배치 이력을 읽지 못했습니다. 다시 시도하세요.', 500); }

        return rest_ensure_response(array('items' => $items, 'studentKeys' => $scope['students'], 'limit' => Seating_History_Repository::LIMIT));
    }

    public static function confirm(WP_REST_Request $request) {
        $scope = self::scope($request);
        if (is_wp_error($scope)) { return $scope; }
        $params = $request->get_json_params();
        $key = $params['confirmationKey'] ?? '';
        $rows = $params['rows'] ?? null;
        $cols = $params['cols'] ?? null;
        $positions = $params['positions'] ?? null;
        if (! is_string($key) || ! preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/i', $key)
            || ! is_int($rows) || ! is_int($cols) || $rows < 1 || $rows > 20 || $cols < 1 || $cols > 20
            || ! is_array($positions) || count($positions) !== count($scope['students'])) {
            return self::error('확정할 배치 정보가 올바르지 않습니다.');
        }
        $normalized = array(); $seen = array(); $occupied = array();
        foreach ($positions as $position) {
            if (! is_array($position) || ! is_scalar($position['id'] ?? null)) { return self::error('학생 ID를 확인하세요.'); }
            $id = (string) $position['id'];
            $row = $position['row'] ?? null; $col = $position['col'] ?? null;
            if (! isset($scope['students'][$id]) || isset($seen[$id]) || ! is_int($row) || ! is_int($col)
                || $row < 0 || $row >= $rows || $col < 0 || $col >= $cols || isset($occupied[$row . '-' . $col])) {
                return self::error('학생 또는 좌석이 중복되거나 범위를 벗어났습니다.');
            }
            $seen[$id] = true; $occupied[$row . '-' . $col] = true;
            $normalized[] = array('id' => $scope['students'][$id], 'row' => $row, 'col' => $col);
        }
        if (! Seating_History_Repository::confirm(get_current_user_id(), $scope['key'], strtolower($key), $rows, $cols, $normalized)) {
            return self::error('배치 이력을 저장하지 못했습니다. 다시 시도하세요.', 500);
        }

        return rest_ensure_response(array('confirmed' => true));
    }

    /** Only the authenticated teacher's history is readable; caller-supplied owner/scope keys are never used. */
    private static function scope(WP_REST_Request $request): array|WP_Error {
        if (! Capabilities::can_host() || get_current_user_id() < 1) { return self::error('배치 이력을 관리할 권한이 없습니다.', 403); }
        if (strlen($request->get_body()) > 262144) { return self::error('명단 정보가 너무 큽니다.'); }
        $params = $request->get_json_params();
        $roster = is_array($params) ? ($params['roster'] ?? null) : null;
        $group = $params['historyGroup'] ?? '';
        if (! is_array($roster) || count($roster) < 1 || count($roster) > 400 || ! is_string($group) || strlen($group) > 240) {
            return self::error('배치 이력의 명단 또는 그룹 이름을 확인하세요.');
        }
        $students = array(); $occurrences = array();
        usort($roster, static fn ($left, $right): int => strcmp(
            is_array($left) && is_scalar($left['id'] ?? null) ? (string) $left['id'] : '',
            is_array($right) && is_scalar($right['id'] ?? null) ? (string) $right['id'] : ''
        ));
        foreach ($roster as $student) {
            if (! is_array($student) || ! is_scalar($student['id'] ?? null)) { return self::error('학생 ID를 확인하세요.'); }
            $id = (string) $student['id'];
            if (isset($students[$id])) { return self::error('중복된 학생 ID가 있습니다.'); }
            if (preg_match('/\A[1-9][0-9]{0,18}\z/', $id)) {
                $canonical = 'u:' . $id;
            } elseif (preg_match('/\Amanual:[a-z0-9:.-]{1,80}\z/i', $id)) {
                $name = $student['displayName'] ?? $student['name'] ?? '';
                if (! is_string($name) || strlen($name) > 500) { return self::error('직접 입력한 학생 이름을 확인하세요.'); }
                $name = trim((string) preg_replace('/\s+/u', ' ', sanitize_text_field($name)));
                if ('' === $name) { return self::error('직접 입력한 학생 이름을 확인하세요.'); }
                $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
                $canonical = 'm:' . hash('sha256', $name . '\0' . $occurrences[$name]);
            } else { return self::error('학생 ID 형식이 올바르지 않습니다.'); }
            $students[$id] = $canonical;
        }
        $ids = array_values($students); sort($ids, SORT_STRING);
        $group = trim(sanitize_text_field($group));
        $key = hash('sha256', '' !== $group ? 'group:' . $group : 'roster:' . implode(',', $ids));

        return array('key' => $key, 'students' => $students);
    }

    private static function error(string $message, int $status = 400): WP_Error {
        return new WP_Error('teaching_tools_seating_history', __($message, 'teaching-tools'), array('status' => $status));
    }
}
