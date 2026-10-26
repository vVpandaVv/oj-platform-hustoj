<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use TeachingTools\Sessions\Session_Code_Guard;
use TeachingTools\Sessions\Tool_Session_Repository;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Claiming a ladder column is the one write a participant makes directly
 * (every other tool only ever reads state or lets the host write it), so it
 * needs its own atomicity: two students tapping the same column within the
 * same second must not both "win" it. Tool_Session_Repository::
 * atomic_update_by_code() serializes that with a row lock.
 */
final class Ladder_Controller {
    private const CODE_PATTERN = '(?P<code>[A-Za-z0-9]{6})';

    public static function register_routes(): void {
        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/ladder-claim',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'claim'),
                'permission_callback' => array(Capabilities::class, 'can_join'),
            )
        );
    }

    public static function claim(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $code = (string) $request['code'];
        $session = Tool_Session_Repository::find_active_by_code($code);

        if (null === $session || 'ladder' !== (string) $session['tool_type'] || 'live' !== (string) $session['mode']) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        $state = json_decode((string) $session['state'], true);
        $state = is_array($state) ? $state : array();
        $participant_count = (int) ($state['participantCount'] ?? 0);

        $params = $request->get_json_params();
        $column = is_array($params) && isset($params['column']) ? (int) $params['column'] : -1;

        if ($column < 0 || $column >= $participant_count) {
            return new WP_Error(
                'teaching_tools_ladder_invalid_column',
                __('올바르지 않은 줄입니다.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        $user = get_userdata($user_id);
        $user_name = $user ? $user->display_name : ('학생 #' . $user_id);

        $outcome = Tool_Session_Repository::atomic_update_by_code(
            $code,
            static function (array $current_state) use ($column, $user_id, $user_name): array {
                $claims = is_array($current_state['claims'] ?? null) ? $current_state['claims'] : array();

                foreach ($claims as $claim) {
                    if ((int) ($claim['column'] ?? -1) === $column) {
                        return array(false, null, 'column_taken');
                    }
                    if ((int) ($claim['id'] ?? 0) === $user_id) {
                        return array(false, null, 'already_claimed');
                    }
                }

                $mapping = is_array($current_state['mapping'] ?? null) ? $current_state['mapping'] : array();
                $results = is_array($current_state['results'] ?? null) ? $current_state['results'] : array();
                $result_index = $mapping[$column] ?? null;
                $result_text = null !== $result_index && isset($results[$result_index])
                    ? (string) $results[$result_index]
                    : '';

                $claims[] = array(
                    'column' => $column,
                    'id' => $user_id,
                    'name' => $user_name,
                    'result' => $result_text,
                );
                $current_state['claims'] = $claims;

                return array(true, $current_state, '');
            }
        );

        if (! $outcome['ok']) {
            if ('not_found' === $outcome['error']) {
                Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

                return self::not_found_error();
            }

            $message = 'column_taken' === $outcome['error']
                ? __('이미 다른 학생이 선택한 줄입니다.', 'teaching-tools')
                : __('이미 참여했습니다.', 'teaching-tools');

            return new WP_Error('teaching_tools_ladder_claim_failed', $message, array('status' => 409));
        }

        Tool_Session_Repository::add_participant((int) $session['id'], $user_id);

        $mapping = is_array($state['mapping'] ?? null) ? $state['mapping'] : array();
        $results = is_array($state['results'] ?? null) ? $state['results'] : array();
        $rungs = is_array($state['rungs'] ?? null) ? $state['rungs'] : array();
        $result_index = $mapping[$column] ?? null;

        return rest_ensure_response(array(
            'column' => $column,
            'result' => null !== $result_index && isset($results[$result_index]) ? $results[$result_index] : null,
            // Only THIS student's own path — never the raw rungs/mapping,
            // which would let them (or anyone reading the response) work
            // out every other column's result before it's revealed.
            'path' => self::trace_path($rungs, $column, $participant_count),
        ));
    }

    /**
     * Walks the ladder row by row exactly like resources/js/ladder-engine.js's
     * traceColumn() — check the rung to the left first, then the right —
     * so this PHP trace and the client-side one always agree.
     *
     * @param array<int, array<int, bool>> $rungs
     * @return list<int>
     */
    private static function trace_path(array $rungs, int $start_column, int $column_count): array {
        $column = $start_column;
        $path = array($column);

        foreach ($rungs as $row_rungs) {
            if (! is_array($row_rungs)) {
                continue;
            }
            if ($column > 0 && ! empty($row_rungs[$column - 1])) {
                $column -= 1;
            } elseif ($column < $column_count - 1 && ! empty($row_rungs[$column])) {
                $column += 1;
            }
            $path[] = $column;
        }

        return $path;
    }

    private static function not_found_error(): WP_Error {
        return new WP_Error(
            'teaching_tools_session_not_found',
            __('참여 코드를 확인해 주세요.', 'teaching-tools'),
            array('status' => 404)
        );
    }
}
