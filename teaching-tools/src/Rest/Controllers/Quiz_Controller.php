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
 * Participant writes for every quick-response format. Responses are kept in
 * the short-lived tool session and updated under the session row lock, so a
 * learner cannot accidentally submit twice when two requests cross.
 */
final class Quiz_Controller {
    private const CODE_PATTERN = '(?P<code>[A-Za-z0-9]{6})';
    private const RESPONSE_TYPES = array('choice', 'scale', 'text', 'wordcloud', 'qna');
    private const MAX_QNA_PER_USER = 3;

    public static function register_routes(): void {
        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/quiz-vote',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'vote'),
                'permission_callback' => array(Capabilities::class, 'can_join'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/quiz-response',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'respond'),
                'permission_callback' => array(Capabilities::class, 'can_join'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/quiz-moderate',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'moderate'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/quiz-reveal',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'reveal'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/quiz-accepting',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'set_accepting'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );
    }

    /** Backward-compatible endpoint used by older participant pages. */
    public static function vote(WP_REST_Request $request) {
        return self::store_response($request, true);
    }

    public static function respond(WP_REST_Request $request) {
        return self::store_response($request, false);
    }

    public static function moderate(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $code = (string) $request['code'];
        $session = Tool_Session_Repository::find_active_by_code($code);
        $host_role = null === $session ? null : Tool_Session_Repository::host_role((int) $session['id'], $user_id);
        if (null === $session || 'quiz' !== (string) $session['tool_type'] || ! in_array($host_role, array('owner', 'cohost'), true)) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $response_id = sanitize_key((string) ($params['responseId'] ?? ''));
        $status = sanitize_key((string) ($params['status'] ?? ''));
        if ('' === $response_id || ! in_array($status, array('pending', 'approved', 'dismissed'), true)) {
            return new WP_Error(
                'teaching_tools_quiz_invalid_moderation',
                __('질문과 처리 상태를 확인해 주세요.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        $outcome = Tool_Session_Repository::atomic_update_by_code(
            $code,
            static function (array $state) use ($response_id, $status): array {
                $response_type = self::response_type($state);
                $moderated = 'qna' === $response_type
                    || (in_array($response_type, array('text', 'wordcloud'), true)
                        && (bool) ($state['moderationRequired'] ?? false));
                if (! $moderated) {
                    return array(false, null, 'wrong_type');
                }

                $responses = is_array($state['responses'] ?? null) ? $state['responses'] : array();
                $found = false;
                foreach ($responses as &$response) {
                    if (is_array($response) && $response_id === (string) ($response['id'] ?? '')) {
                        $response['status'] = $status;
                        $found = true;
                        break;
                    }
                }
                unset($response);
                if (! $found) {
                    return array(false, null, 'response_not_found');
                }

                $state['responses'] = $responses;

                return array(true, $state, '');
            }
        );

        if (! $outcome['ok']) {
            return new WP_Error(
                'teaching_tools_quiz_moderation_failed',
                __('처리할 응답을 찾지 못했습니다.', 'teaching-tools'),
                array('status' => 404)
            );
        }

        return rest_ensure_response(array('ok' => true, 'responseId' => $response_id, 'status' => $status));
    }

    public static function set_accepting(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $code = (string) $request['code'];
        $session = Tool_Session_Repository::find_active_by_code($code);
        $host_role = null === $session ? null : Tool_Session_Repository::host_role((int) $session['id'], $user_id);
        if (null === $session || 'quiz' !== (string) $session['tool_type'] || ! in_array($host_role, array('owner', 'cohost'), true)) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        $params = $request->get_json_params();
        $accepting = is_array($params) && ! empty($params['accepting']);
        $outcome = Tool_Session_Repository::atomic_update_by_code(
            $code,
            static function (array $state) use ($accepting): array {
                $state['acceptingResponses'] = $accepting;

                return array(true, $state, '');
            }
        );

        if (! $outcome['ok']) {
            return self::not_found_error();
        }

        return rest_ensure_response(array('ok' => true, 'accepting' => $accepting));
    }

    public static function reveal(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $code = (string) $request['code'];
        $session = Tool_Session_Repository::find_active_by_code($code);
        $host_role = null === $session ? null : Tool_Session_Repository::host_role((int) $session['id'], $user_id);
        if (null === $session || 'quiz' !== (string) $session['tool_type'] || ! in_array($host_role, array('owner', 'cohost'), true)) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        $params = $request->get_json_params();
        $revealed = is_array($params) && ! empty($params['revealed']);
        $outcome = Tool_Session_Repository::atomic_update_by_code(
            $code,
            static function (array $state) use ($revealed): array {
                $state['revealed'] = $revealed;

                return array(true, $state, '');
            }
        );

        if (! $outcome['ok']) {
            return self::not_found_error();
        }

        return rest_ensure_response(array('ok' => true, 'revealed' => $revealed));
    }

    private static function store_response(WP_REST_Request $request, bool $legacy_vote) {
        $user_id = get_current_user_id();
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $code = (string) $request['code'];
        $session = Tool_Session_Repository::find_active_by_code($code);
        if (null === $session || 'quiz' !== (string) $session['tool_type'] || 'live' !== (string) $session['mode']) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }
        if (! Tool_Session_Repository::is_participant((int) $session['id'], $user_id)) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        $initial_state = json_decode((string) $session['state'], true);
        $initial_state = is_array($initial_state) ? $initial_state : array();
        if (! (bool) ($initial_state['acceptingResponses'] ?? true)) {
            return new WP_Error(
                'teaching_tools_quiz_responses_closed',
                __('응답 접수가 마감되었습니다.', 'teaching-tools'),
                array('status' => 409)
            );
        }
        $response_type = self::response_type($initial_state);
        if ($legacy_vote && 'choice' !== $response_type) {
            return self::invalid_response_error();
        }

        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $validated = self::validated_response($response_type, $initial_state, $params);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $outcome = Tool_Session_Repository::atomic_update_by_code(
            $code,
            static function (array $state) use ($response_type, $validated, $user_id): array {
                if ($response_type !== self::response_type($state)) {
                    return array(false, null, 'type_changed');
                }
                if (! (bool) ($state['acceptingResponses'] ?? true)) {
                    return array(false, null, 'responses_closed');
                }

                $responses = self::normalized_responses($state);
                $mine = array_values(array_filter(
                    $responses,
                    static fn ($response): bool => is_array($response)
                        && (int) (($response['student']['id'] ?? $response['userId'] ?? 0)) === $user_id
                ));

                if ('qna' !== $response_type && count($mine) > 0) {
                    return array(false, null, 'already_responded');
                }
                if ('qna' === $response_type && count($mine) >= self::MAX_QNA_PER_USER) {
                    return array(false, null, 'qna_limit');
                }

                $entry = array_merge(
                    array(
                        'id' => strtolower(str_replace('-', '', wp_generate_uuid4())),
                        'student' => array('id' => $user_id),
                        'submittedAt' => time(),
                    ),
                    $validated
                );
                if (
                    'qna' === $response_type
                    || (in_array($response_type, array('text', 'wordcloud'), true)
                        && (bool) ($state['moderationRequired'] ?? false))
                ) {
                    $entry['status'] = 'pending';
                }
                $responses[] = $entry;
                $state['responses'] = $responses;

                // Keep the original vote shape while old hosts/pages are in
                // flight during an update.
                if ('choice' === $response_type) {
                    $votes = is_array($state['votes'] ?? null) ? $state['votes'] : array();
                    $votes[] = array('student' => array('id' => $user_id), 'optionIndex' => (int) $validated['optionIndex']);
                    $state['votes'] = $votes;
                }

                return array(true, $state, '');
            }
        );

        if (! $outcome['ok']) {
            $message = match ($outcome['error']) {
                'qna_limit' => __('익명 질문은 한 수업에서 3개까지 보낼 수 있습니다.', 'teaching-tools'),
                'responses_closed' => __('응답 접수가 마감되었습니다.', 'teaching-tools'),
                default => __('이미 응답했습니다.', 'teaching-tools'),
            };

            return new WP_Error(
                'teaching_tools_quiz_response_failed',
                $message,
                array('status' => 409)
            );
        }

        Tool_Session_Repository::update_participant_data(
            (int) $session['id'],
            $user_id,
            array('lastSeenAt' => time())
        );

        return rest_ensure_response(array_merge(array('ok' => true, 'responseType' => $response_type), $validated));
    }

    /** @param array<string, mixed> $state */
    private static function response_type(array $state): string {
        $type = sanitize_key((string) ($state['responseType'] ?? 'choice'));

        return in_array($type, self::RESPONSE_TYPES, true) ? $type : 'choice';
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $params
     * @return array<string, mixed>|WP_Error
     */
    private static function validated_response(string $response_type, array $state, array $params) {
        if ('choice' === $response_type) {
            $options = is_array($state['options'] ?? null) ? $state['options'] : array();
            $option_index = isset($params['optionIndex']) ? (int) $params['optionIndex'] : -1;
            if ($option_index < 0 || $option_index >= count($options)) {
                return self::invalid_response_error();
            }

            return array('optionIndex' => $option_index);
        }

        if ('scale' === $response_type) {
            $minimum = max(1, min(9, (int) ($state['scaleMin'] ?? 1)));
            $maximum = max($minimum + 1, min(10, (int) ($state['scaleMax'] ?? 5)));
            $value = isset($params['value']) ? (int) $params['value'] : 0;
            if ($value < $minimum || $value > $maximum) {
                return self::invalid_response_error();
            }

            return array('value' => $value);
        }

        $limit = 'qna' === $response_type ? 200 : ('wordcloud' === $response_type ? 30 : 100);
        $text = trim(self::limit_text(sanitize_text_field((string) ($params['text'] ?? '')), $limit));
        if ('' === $text) {
            return self::invalid_response_error();
        }

        $blocked_words = array_values(array_filter(array_map(
            static fn ($word): string => trim(self::limit_text(sanitize_text_field((string) $word), 40)),
            is_array($state['blockedWords'] ?? null) ? array_slice($state['blockedWords'], 0, 50) : array()
        )));
        foreach ($blocked_words as $blocked_word) {
            $position = function_exists('mb_stripos')
                ? mb_stripos($text, $blocked_word)
                : stripos($text, $blocked_word);
            if (false !== $position) {
                return new WP_Error(
                    'teaching_tools_quiz_blocked_response',
                    __('응답에 사용할 수 없는 표현이 포함되어 있습니다.', 'teaching-tools'),
                    array('status' => 400)
                );
            }
        }

        return array('text' => $text);
    }

    /** @param array<string, mixed> $state @return list<array<string, mixed>> */
    private static function normalized_responses(array $state): array {
        if (is_array($state['responses'] ?? null)) {
            return array_values(array_filter($state['responses'], 'is_array'));
        }

        $responses = array();
        foreach (is_array($state['votes'] ?? null) ? $state['votes'] : array() as $vote) {
            if (! is_array($vote)) {
                continue;
            }
            $responses[] = array(
                'id' => 'legacy-' . count($responses),
                'student' => array('id' => (int) ($vote['student']['id'] ?? $vote['userId'] ?? 0)),
                'optionIndex' => (int) ($vote['optionIndex'] ?? -1),
                'submittedAt' => 0,
            );
        }

        return $responses;
    }

    private static function invalid_response_error(): WP_Error {
        return new WP_Error(
            'teaching_tools_quiz_invalid_response',
            __('응답 내용을 확인해 주세요.', 'teaching-tools'),
            array('status' => 400)
        );
    }

    private static function not_found_error(): WP_Error {
        return new WP_Error(
            'teaching_tools_session_not_found',
            __('참여 코드를 확인해 주세요.', 'teaching-tools'),
            array('status' => 404)
        );
    }

    private static function limit_text(string $value, int $length): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
