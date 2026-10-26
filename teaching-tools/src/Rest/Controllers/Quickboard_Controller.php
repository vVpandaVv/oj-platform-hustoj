<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use TeachingTools\Sessions\Session_Code_Guard;
use TeachingTools\Sessions\Tool_Session_Repository;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class Quickboard_Controller {
    private const CODE_PATTERN = '(?P<code>[A-Za-z0-9]{6})';
    private const MAX_ELEMENTS = 100;
    private const MAX_POINTS_PER_ELEMENT = 400;
    private const MAX_TOTAL_POINTS = 6000;
    private const COLORS = array('#1a1a1a', '#e03131', '#1971c2', '#2f9e44', '#f08c00');

    public static function register_routes(): void {
        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/quickboard-response',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'save_response'),
                'permission_callback' => array(Capabilities::class, 'can_join'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/quickboard-responses',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'responses'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/quickboard-responses/clear',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'clear_responses'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );
    }

    public static function save_response(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $session = self::participant_session($request, $user_id);
        if (is_wp_error($session)) {
            return $session;
        }

        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $elements = self::sanitize_elements($params['elements'] ?? null);
        if (is_wp_error($elements)) {
            return $elements;
        }

        $revision = max(1, min(1000000, (int) ($params['revision'] ?? 1)));
        $board = array(
            'elements' => $elements,
            'revision' => $revision,
            'submittedAt' => time(),
        );
        if (! Tool_Session_Repository::update_participant_data(
            (int) $session['id'],
            $user_id,
            array('quickboard' => $board, 'lastSeenAt' => time())
        )) {
            return new WP_Error(
                'teaching_tools_quickboard_save_failed',
                __('그림을 저장하지 못했습니다.', 'teaching-tools'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array(
            'ok' => true,
            'revision' => $revision,
            'submittedAt' => $board['submittedAt'],
        ));
    }

    public static function responses(WP_REST_Request $request) {
        $session = self::owner_session($request, get_current_user_id());
        if (is_wp_error($session)) {
            return $session;
        }

        $state = json_decode((string) ($session['state'] ?? '{}'), true);
        $state = is_array($state) ? $state : array();
        $anonymous = ! empty($state['anonymous']);
        $items = array();
        foreach (Tool_Session_Repository::participants((int) $session['id']) as $index => $row) {
            $participant_user_id = (int) ($row['user_id'] ?? 0);
            $data = json_decode((string) ($row['data'] ?? '{}'), true);
            $data = is_array($data) ? $data : array();
            $board = is_array($data['quickboard'] ?? null) ? $data['quickboard'] : array();
            $items[] = array(
                'id' => $participant_user_id,
                'name' => $anonymous
                    ? sprintf(__('응답 %d', 'teaching-tools'), $index + 1)
                    : sanitize_text_field((string) ($data['displayName'] ?? sprintf(__('학생 #%d', 'teaching-tools'), $participant_user_id))),
                'elements' => is_array($board['elements'] ?? null) ? $board['elements'] : array(),
                'revision' => max(0, (int) ($board['revision'] ?? 0)),
                'submittedAt' => max(0, (int) ($board['submittedAt'] ?? 0)),
            );
        }

        return rest_ensure_response(array(
            'responses' => $items,
            'participantCount' => count($items),
            'submittedCount' => count(array_filter($items, static fn (array $item): bool => $item['submittedAt'] > 0)),
        ));
    }

    public static function clear_responses(WP_REST_Request $request) {
        $session = self::owner_session($request, get_current_user_id());
        if (is_wp_error($session)) {
            return $session;
        }

        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $target_user_id = absint($params['id'] ?? 0);
        $found = false;
        foreach (Tool_Session_Repository::participants((int) $session['id']) as $row) {
            $participant_user_id = (int) ($row['user_id'] ?? 0);
            if ($target_user_id > 0 && $participant_user_id !== $target_user_id) {
                continue;
            }
            $found = Tool_Session_Repository::update_participant_data(
                (int) $session['id'],
                $participant_user_id,
                array('quickboard' => null)
            ) || $found;
        }

        if ($target_user_id > 0 && ! $found) {
            return new WP_Error(
                'teaching_tools_quickboard_participant_not_found',
                __('참여 학생을 찾지 못했습니다.', 'teaching-tools'),
                array('status' => 404)
            );
        }

        return rest_ensure_response(array('ok' => true, 'id' => $target_user_id));
    }

    /** @return array<string, mixed>|WP_Error */
    private static function participant_session(WP_REST_Request $request, int $user_id) {
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $session = Tool_Session_Repository::find_active_by_code((string) $request['code']);
        if (
            null === $session
            || 'quickboard' !== (string) $session['tool_type']
            || 'live' !== (string) $session['mode']
            || ! Tool_Session_Repository::is_participant((int) $session['id'], $user_id)
        ) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        return $session;
    }

    /** @return array<string, mixed>|WP_Error */
    private static function owner_session(WP_REST_Request $request, int $user_id) {
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $session = Tool_Session_Repository::find_active_by_code((string) $request['code']);
        $host_role = null === $session ? null : Tool_Session_Repository::host_role((int) $session['id'], $user_id);
        if (null === $session || 'quickboard' !== (string) $session['tool_type'] || ! in_array($host_role, array('owner', 'cohost'), true)) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        return $session;
    }

    /** @return list<array<string, mixed>>|WP_Error */
    private static function sanitize_elements($raw) {
        if (! is_array($raw) || count($raw) > self::MAX_ELEMENTS) {
            return self::invalid_board_error();
        }

        $elements = array();
        $total_points = 0;
        foreach ($raw as $element) {
            if (! is_array($element)) {
                return self::invalid_board_error();
            }
            $type = sanitize_key((string) ($element['type'] ?? ''));
            $points = is_array($element['points'] ?? null) ? $element['points'] : array();
            if (! in_array($type, array('draw', 'erase'), true) || count($points) < 2 || count($points) > self::MAX_POINTS_PER_ELEMENT) {
                return self::invalid_board_error();
            }

            $safe_points = array();
            foreach ($points as $point) {
                if (! is_array($point) || count($point) < 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                    return self::invalid_board_error();
                }
                $safe_points[] = array(
                    max(0, min(1000, (int) round((float) $point[0]))),
                    max(0, min(600, (int) round((float) $point[1]))),
                );
            }
            $total_points += count($safe_points);
            if ($total_points > self::MAX_TOTAL_POINTS) {
                return self::invalid_board_error();
            }

            $color = strtolower(sanitize_hex_color((string) ($element['color'] ?? '')) ?: '#1a1a1a');
            if (! in_array($color, self::COLORS, true)) {
                $color = '#1a1a1a';
            }
            $elements[] = array(
                'type' => $type,
                'color' => $color,
                'width' => max(2, min(18, (int) ($element['width'] ?? 4))),
                'points' => $safe_points,
            );
        }

        return $elements;
    }

    private static function invalid_board_error(): WP_Error {
        return new WP_Error(
            'teaching_tools_quickboard_invalid',
            __('그림 데이터가 너무 크거나 올바르지 않습니다.', 'teaching-tools'),
            array('status' => 400)
        );
    }

    private static function not_found_error(): WP_Error {
        return new WP_Error(
            'teaching_tools_session_not_found',
            __('수업 세션을 확인해 주세요.', 'teaching-tools'),
            array('status' => 404)
        );
    }
}
