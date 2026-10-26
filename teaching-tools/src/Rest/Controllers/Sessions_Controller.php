<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use TeachingTools\Addon\Whiteboard\Whiteboard_Image_Repository;
use TeachingTools\Sessions\Session_Code_Guard;
use TeachingTools\Sessions\Tool_Session_Repository;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class Sessions_Controller {
    private const CODE_PATTERN = '(?P<code>[A-Za-z0-9]{6})';

    public static function register_routes(): void {
        register_rest_route('teaching-tools/v1', '/sessions/assigned', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'assigned_sessions'),
            'permission_callback' => array(Capabilities::class, 'can_join'),
        ));
        register_rest_route(
            'teaching-tools/v1',
            '/sessions',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'create_session'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN,
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'get_session'),
                'permission_callback' => array(__CLASS__, 'can_view'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/state',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'update_state'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/join',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'join_session'),
                'permission_callback' => array(Capabilities::class, 'can_join'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/end',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'end_session'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );
    }

    public static function can_view(): bool {
        return Capabilities::can_host() || Capabilities::can_join();
    }

    public static function create_session(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $tool_type = is_array($params) && isset($params['toolType']) ? sanitize_key((string) $params['toolType']) : '';
        $mode = is_array($params) && isset($params['mode']) ? sanitize_key((string) $params['mode']) : 'solo';
        $state = is_array($params) && isset($params['state']) && is_array($params['state']) ? $params['state'] : array();
        unset($state['context'], $state['audience']);

        if (! in_array($tool_type, Tool_Session_Repository::VALID_TOOL_TYPES, true)) {
            return new WP_Error(
                'teaching_tools_invalid_tool',
                __('알 수 없는 도구입니다.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        if (! in_array($mode, Tool_Session_Repository::VALID_MODES, true)) {
            return new WP_Error(
                'teaching_tools_invalid_mode',
                __('알 수 없는 진행 방식입니다.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        $context_type = null;
        $context_id = null;
        $context = isset($params['context']) && is_array($params['context']) ? $params['context'] : null;
        $students = array();
        $authorized_students = array();

        if (null !== $context) {
            $context_type = sanitize_key((string) ($context['type'] ?? ''));
            $context_id = sanitize_text_field((string) ($context['id'] ?? ''));
            $revision = sanitize_text_field((string) ($context['revision'] ?? ''));
            $authorized_students = Roster_Controller::students_for_context(
                $context_type,
                $context_id,
                $revision,
                get_current_user_id()
            );
            if (is_wp_error($authorized_students)) {
                return $authorized_students;
            }
            if ([] === $authorized_students) {
                return new WP_Error(
                    'teaching_tools_empty_context',
                    __('선택한 학급 또는 그룹에 참여할 학생이 없습니다.', 'teaching-tools'),
                    array('status' => 400)
                );
            }

            $label = self::limit_text(sanitize_text_field((string) ($context['label'] ?? '')), 100);
            $state['context'] = array(
                'type' => $context_type,
                'id' => $context_id,
                'revision' => $revision,
                'label' => $label,
            );
            if ('live' === $mode) {
                $students = $authorized_students;
                $state['audience'] = array(
                    'mode' => 'context',
                    'type' => $context_type,
                    'id' => $context_id,
                    'label' => $label,
                    'memberCount' => count($students),
                    'autoOpen' => true === ($params['autoOpen'] ?? false),
                );
            }
            if ('timeattack' === $tool_type) {
                $allowed = array_fill_keys(array_map(
                    static fn (array $student): int => (int) $student['id'],
                    $authorized_students
                ), true);
                $roster = is_array($state['roster'] ?? null) ? $state['roster'] : array();
                $state['roster'] = array_values(array_filter(
                    array_map(
                        static function ($entry) use ($allowed): ?array {
                            if (! is_array($entry)) {
                                return null;
                            }
                            $id = (int) ($entry['id'] ?? 0);
                            if ($id < 1 || ! isset($allowed[$id])) {
                                return null;
                            }

                            return array(
                                'id' => $id,
                                'name' => self::limit_text(sanitize_text_field((string) ($entry['name'] ?? '')), 120),
                            );
                        },
                        $roster
                    ),
                    static fn ($entry): bool => is_array($entry)
                ));
                if ([] === $state['roster']) {
                    return new WP_Error(
                        'teaching_tools_empty_context',
                        __('선택한 명단을 다시 불러와 주세요.', 'teaching-tools'),
                        array('status' => 400)
                    );
                }
            }
        }

        $initial_participants = array_map(
            static fn (array $student): array => array(
                'id' => (int) $student['id'],
                'data' => array(
                    'lastSeenAt' => 0,
                    'signal' => '',
                    'signalAt' => 0,
                    'autoEnrolled' => true,
                    'displayName' => (string) $student['displayName'],
                    'managementName' => (string) $student['managementName'],
                ),
            ),
            $students
        );
        $session = Tool_Session_Repository::create(
            get_current_user_id(),
            $tool_type,
            $mode,
            $state,
            $context_type,
            $context_id,
            $initial_participants
        );
        if (null === $session) {
            return new WP_Error(
                'teaching_tools_session_create_failed',
                __('세션을 만들지 못했습니다.', 'teaching-tools'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(self::present($session, true, get_current_user_id(), 'owner'));
    }

    public static function assigned_sessions() {
        return rest_ensure_response(array('items' => self::assigned_items()));
    }

    /** @return list<array<string, mixed>> */
    public static function assigned_items(): array {
        $labels = array(
            'timer' => __('타이머', 'teaching-tools'),
            'seating' => __('자리배치', 'teaching-tools'),
            'picker' => __('뽑기', 'teaching-tools'),
            'ladder' => __('사다리타기', 'teaching-tools'),
            'whiteboard' => __('판서', 'teaching-tools'),
            'quickboard' => __('학생 미니 판서', 'teaching-tools'),
            'quiz' => __('빠른 응답', 'teaching-tools'),
            'grouping' => __('조 짜기', 'teaching-tools'),
            'timeattack' => __('타임어택', 'teaching-tools'),
            'classroom' => __('수업 신호', 'teaching-tools'),
        );
        $items = array_map(static function (array $session) use ($labels): array {
            $state = json_decode((string) ($session['state'] ?? '{}'), true);
            return array(
                'joinCode' => (string) $session['join_code'],
                'toolType' => (string) $session['tool_type'],
                'label' => $labels[$session['tool_type']] ?? __('수업 활동', 'teaching-tools'),
                'contextLabel' => sanitize_text_field((string) ($state['audience']['label'] ?? $state['context']['label'] ?? '')),
                'autoOpen' => true === ($state['audience']['autoOpen'] ?? false),
            );
        }, Tool_Session_Repository::assigned_sessions(get_current_user_id()));

        return $items;
    }

    public static function get_session(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $session = Tool_Session_Repository::find_active_by_code((string) $request['code']);
        $host_role = null !== $session ? Tool_Session_Repository::host_role((int) $session['id'], $user_id) : null;
        $is_host = null !== $host_role;
        $is_participant = null !== $session && ! $is_host
            && Tool_Session_Repository::is_participant((int) $session['id'], $user_id);

        if (null === $session || (! $is_host && ! $is_participant)) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        return rest_ensure_response(self::present($session, $is_host, $user_id, $host_role ?? 'participant'));
    }

    public static function update_state(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $session = Tool_Session_Repository::find_active_by_code((string) $request['code']);
        $host_role = null !== $session ? Tool_Session_Repository::host_role((int) $session['id'], $user_id) : null;
        if (null === $session || ! in_array($host_role, array('owner', 'cohost'), true)) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        $params = $request->get_json_params();
        $state = is_array($params) && isset($params['state']) && is_array($params['state']) ? $params['state'] : array();

        // Clients update tool state, not the server-verified audience snapshot.
        $previous = json_decode((string) ($session['state'] ?? '{}'), true);
        foreach (array('context', 'audience') as $key) {
            unset($state[$key]);
            if (is_array($previous[$key] ?? null)) {
                $state[$key] = $previous[$key];
            }
        }

        if (! Tool_Session_Repository::update_state((int) $session['id'], $state)) {
            return new WP_Error(
                'teaching_tools_session_state_rejected',
                __('세션 상태가 허용된 크기나 형식을 벗어났습니다.', 'teaching-tools'),
                array('status' => 400)
            );
        }
        $updated = Tool_Session_Repository::find((int) $session['id']);

        return rest_ensure_response(self::present((array) $updated, true, $user_id, (string) $host_role));
    }

    public static function join_session(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $session = Tool_Session_Repository::find_active_by_code((string) $request['code']);
        if (null === $session || 'live' !== (string) $session['mode']) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        $is_targeted_classroom = '' !== (string) ($session['context_type'] ?? '')
            && '' !== (string) ($session['context_id'] ?? '');
        if ($is_targeted_classroom && ! Tool_Session_Repository::is_participant((int) $session['id'], $user_id)) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        $identity = wp_get_current_user();
        $existing = Tool_Session_Repository::participant_data((int) $session['id'], $user_id);
        if (! Tool_Session_Repository::add_participant(
            (int) $session['id'],
            $user_id,
            array(
                'lastSeenAt' => time(),
                'displayName' => sanitize_text_field((string) ($existing['displayName'] ?? $identity->display_name)),
                'managementName' => sanitize_text_field((string) ($existing['managementName'] ?? get_user_meta($user_id, 'nickname', true))),
            )
        )) {
            return new WP_Error(
                'teaching_tools_join_failed',
                __('수업에 참여하지 못했습니다. 다시 시도해 주세요.', 'teaching-tools'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(self::present($session, false, $user_id, 'participant'));
    }

    public static function end_session(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $session = Tool_Session_Repository::find_active_by_code((string) $request['code']);
        $host_role = null !== $session ? Tool_Session_Repository::host_role((int) $session['id'], $user_id) : null;
        if (null === $session || ! in_array($host_role, array('owner', 'cohost'), true)) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        Tool_Session_Repository::end((int) $session['id']);

        if ('whiteboard' === (string) $session['tool_type']) {
            Whiteboard_Image_Repository::delete_for_session((int) $session['id']);
        }

        return rest_ensure_response(array('ended' => true));
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

    /** @param array<string, mixed> $session */
    private static function present(array $session, bool $include_owner_fields, int $user_id = 0, string $role = 'participant'): array {
        $state = json_decode((string) ($session['state'] ?? '{}'), true);
        $state = is_array($state) ? $state : array();
        $tool_type = (string) $session['tool_type'];

        // A ladder's whole point is that the column-to-result mapping stays
        // secret until a student commits to a column — sending the raw
        // rungs/mapping to a participant would let them work out their
        // result (or a better one) before picking. The host still gets the
        // full state; it only builds its own reveal UI once it chooses to.
        if (! $include_owner_fields && 'ladder' === $tool_type) {
            $state = array(
                'participantCount' => $state['participantCount'] ?? 0,
                'results' => $state['results'] ?? array(),
                'claims' => $state['claims'] ?? array(),
            );
        }

        if (! $include_owner_fields && 'seating' === $tool_type) {
            $seats = array_values(array_filter(is_array($state['seats'] ?? null) ? $state['seats'] : array(), 'is_array'));
            $state = array(
                'rows' => max(1, (int) ($state['rows'] ?? 1)),
                'cols' => max(1, (int) ($state['cols'] ?? 1)),
                'seats' => array_map(
                    static function (array $seat) use ($user_id): array {
                        $student = is_array($seat['student'] ?? null) ? $seat['student'] : null;
                        $student_id = (int) ($student['id'] ?? 0);
                        if ($student_id === $user_id) {
                            $presented_student = array(
                                'id' => $user_id,
                                'name' => sanitize_text_field((string) ($student['name'] ?? '')),
                            );
                        } elseif (null !== $student) {
                            $presented_student = array('name' => __('배정 완료', 'teaching-tools'));
                        } else {
                            $presented_student = null;
                        }

                        return array(
                            'id' => sanitize_key((string) ($seat['id'] ?? '')),
                            'row' => max(0, (int) ($seat['row'] ?? 0)),
                            'col' => max(0, (int) ($seat['col'] ?? 0)),
                            'disabled' => (bool) ($seat['disabled'] ?? false),
                            'student' => $presented_student,
                        );
                    },
                    $seats
                ),
            );
        }

        if (! $include_owner_fields && 'picker' === $tool_type) {
            $latest = array_values(array_filter(is_array($state['latest'] ?? null) ? $state['latest'] : array(), 'is_array'));
            $state = array(
                'latest' => array_map(
                    static fn (array $entry): array => array(
                        'name' => sanitize_text_field((string) ($entry['name'] ?? '')),
                        'mine' => (int) ($entry['id'] ?? 0) === $user_id,
                    ),
                    $latest
                ),
                'drawCount' => max(0, (int) ($state['drawCount'] ?? 0)),
            );
        }

        if (! $include_owner_fields && 'grouping' === $tool_type) {
            $groups = array_values(array_filter(is_array($state['groups'] ?? null) ? $state['groups'] : array(), 'is_array'));
            $state = array('groups' => array_map(
                static function (array $group) use ($user_id): array {
                    $members = array_values(array_filter(
                        is_array($group['members'] ?? null) ? $group['members'] : array(),
                        'is_array'
                    ));

                    return array(
                        'name' => sanitize_text_field((string) ($group['name'] ?? '')),
                        'members' => array_map(
                            static fn (array $member): array => array(
                                'name' => sanitize_text_field((string) ($member['name'] ?? '')),
                                'mine' => (int) ($member['id'] ?? 0) === $user_id,
                            ),
                            $members
                        ),
                    );
                },
                $groups
            ));
        }

        if (! $include_owner_fields && 'timeattack' === $tool_type) {
            $state = array(
                'problems' => array_values(array_filter(
                    is_array($state['problems'] ?? null) ? $state['problems'] : array(),
                    'is_array'
                )),
                'remainingSeconds' => max(0, (int) ($state['remainingSeconds'] ?? 0)),
                'running' => (bool) ($state['running'] ?? false),
            );
        }

        // Quick responses are projected per role. Learners receive only their
        // own submissions plus aggregates/content the teacher has explicitly
        // revealed. Anonymous Q&A also drops user ids from the host payload.
        if ('quiz' === $tool_type) {
            $response_type = sanitize_key((string) ($state['responseType'] ?? 'choice'));
            if (! in_array($response_type, array('choice', 'scale', 'text', 'wordcloud', 'qna'), true)) {
                $response_type = 'choice';
            }
            $responses = self::quiz_responses($state);

            if ($include_owner_fields && 'qna' === $response_type) {
                $state['responses'] = array_map(
                    static function (array $response): array {
                        unset($response['student'], $response['userId']);

                        return $response;
                    },
                    $responses
                );
                unset($state['votes']);
            } elseif (! $include_owner_fields) {
                $options = is_array($state['options'] ?? null) ? array_values($state['options']) : array();
                $revealed = (bool) ($state['revealed'] ?? false);
                $mine = array_values(array_filter(
                    $responses,
                    static fn (array $response): bool => (int) ($response['student']['id'] ?? $response['userId'] ?? 0) === $user_id
                ));
                $scale_minimum = max(1, min(9, (int) ($state['scaleMin'] ?? 1)));
                $scale_maximum = max($scale_minimum + 1, min(10, (int) ($state['scaleMax'] ?? 5)));
                $participant_state = array(
                    'responseType' => $response_type,
                    'question' => sanitize_text_field((string) ($state['question'] ?? '')),
                    'options' => $options,
                    'scaleMin' => $scale_minimum,
                    'scaleMax' => $scale_maximum,
                    'scaleLowLabel' => sanitize_text_field((string) ($state['scaleLowLabel'] ?? '')),
                    'scaleHighLabel' => sanitize_text_field((string) ($state['scaleHighLabel'] ?? '')),
                    'revealed' => $revealed,
                    'acceptingResponses' => (bool) ($state['acceptingResponses'] ?? true),
                    'myResponses' => array_map(
                        static function (array $response): array {
                            return array_filter(
                                array(
                                    'id' => sanitize_key((string) ($response['id'] ?? '')),
                                    'optionIndex' => isset($response['optionIndex']) ? (int) $response['optionIndex'] : null,
                                    'value' => isset($response['value']) ? (int) $response['value'] : null,
                                    'text' => sanitize_text_field((string) ($response['text'] ?? '')),
                                    'status' => sanitize_key((string) ($response['status'] ?? '')),
                                ),
                                static fn ($value): bool => null !== $value && '' !== $value
                            );
                        },
                        $mine
                    ),
                );

                // Legacy participant scripts still look for myVote.
                $participant_state['myVote'] = isset($mine[0]['optionIndex']) ? (int) $mine[0]['optionIndex'] : null;

                if ('qna' === $response_type) {
                    $participant_state['approvedQuestions'] = array_values(array_map(
                        static fn (array $response): array => array(
                            'id' => sanitize_key((string) ($response['id'] ?? '')),
                            'text' => sanitize_text_field((string) ($response['text'] ?? '')),
                        ),
                        array_filter(
                            $responses,
                            static fn (array $response): bool => 'approved' === (string) ($response['status'] ?? '')
                        )
                    ));
                } elseif ($revealed) {
                    $result_responses = $responses;
                    if (
                        in_array($response_type, array('text', 'wordcloud'), true)
                        && (bool) ($state['moderationRequired'] ?? false)
                    ) {
                        $result_responses = array_values(array_filter(
                            $responses,
                            static fn (array $response): bool => 'approved' === (string) ($response['status'] ?? '')
                        ));
                    }
                    self::add_quiz_results($participant_state, $response_type, $state, $result_responses);
                }

                $state = $participant_state;
            }
        }

        if (! $include_owner_fields && 'quickboard' === $tool_type) {
            $participant_data = Tool_Session_Repository::participant_data((int) $session['id'], $user_id);
            $board = is_array($participant_data['quickboard'] ?? null) ? $participant_data['quickboard'] : array();
            $state = array(
                'prompt' => sanitize_text_field((string) ($state['prompt'] ?? '')),
                'myBoard' => array(
                    'elements' => is_array($board['elements'] ?? null) ? $board['elements'] : array(),
                    'revision' => max(0, (int) ($board['revision'] ?? 0)),
                    'submittedAt' => max(0, (int) ($board['submittedAt'] ?? 0)),
                ),
            );
        }

        if (! $include_owner_fields && 'classroom' === $tool_type) {
            $participant_data = Tool_Session_Repository::participant_data((int) $session['id'], $user_id);
            $signal = sanitize_key((string) ($participant_data['signal'] ?? ''));
            $activity = self::participant_activity($state, $user_id);
            $state = array(
                'activity' => $activity,
                'mySignal' => in_array($signal, array('ready', 'understood', 'unsure', 'repeat', 'help', 'done'), true)
                    ? $signal
                    : '',
            );
        }

        $presented = array(
            'joinCode' => (string) $session['join_code'],
            'toolType' => (string) $session['tool_type'],
            'mode' => (string) $session['mode'],
            'role' => $include_owner_fields ? 'host' : 'participant',
            'status' => (string) $session['status'],
            'state' => is_array($state) ? $state : array(),
            'updatedAt' => (string) $session['updated_at'],
        );

        if ($include_owner_fields) {
            $presented['hostRole'] = $role;
            $presented['participantCount'] = Tool_Session_Repository::count_participants((int) $session['id']);
        }

        return $presented;
    }

    /** @param array<string, mixed> $state @return array<string, mixed>|null */
    private static function participant_activity(array $state, int $user_id): ?array {
        $activities = array_values(array_filter(
            is_array($state['activities'] ?? null) ? $state['activities'] : array(),
            'is_array'
        ));
        if ([] === $activities && is_array($state['activity'] ?? null)) {
            $activities[] = $state['activity'];
        }

        foreach (array_reverse($activities) as $activity) {
            $target = is_array($activity['target'] ?? null) ? $activity['target'] : array('type' => 'all');
            $target_type = sanitize_key((string) ($target['type'] ?? 'all'));
            if ('ids' === $target_type) {
                $target_ids = array_map('absint', is_array($target['ids'] ?? null) ? $target['ids'] : array());
                if (! in_array($user_id, $target_ids, true)) {
                    continue;
                }
            }

            $url = esc_url_raw((string) ($activity['url'] ?? ''), array('http', 'https'));
            if ('' === $url || ! preg_match('#^https?://#i', $url)) {
                continue;
            }

            return array(
                'id' => sanitize_text_field((string) ($activity['id'] ?? '')),
                'title' => sanitize_text_field((string) ($activity['title'] ?? '')),
                'url' => $url,
                'note' => sanitize_textarea_field((string) ($activity['note'] ?? '')),
                'sentAt' => max(0, (int) ($activity['sentAt'] ?? 0)),
            );
        }

        return null;
    }

    /** @param array<string, mixed> $state @return list<array<string, mixed>> */
    private static function quiz_responses(array $state): array {
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
            );
        }

        return $responses;
    }

    /**
     * @param array<string, mixed> $participant_state
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $responses
     */
    private static function add_quiz_results(array &$participant_state, string $response_type, array $state, array $responses): void {
        if ('choice' === $response_type) {
            $options = is_array($state['options'] ?? null) ? $state['options'] : array();
            $counts = array_fill(0, count($options), 0);
            foreach ($responses as $response) {
                $index = (int) ($response['optionIndex'] ?? -1);
                if (isset($counts[$index])) {
                    $counts[$index]++;
                }
            }
            $participant_state['counts'] = $counts;
            $participant_state['correctIndex'] = $state['correctIndex'] ?? null;

            return;
        }

        if ('scale' === $response_type) {
            $minimum = (int) $participant_state['scaleMin'];
            $maximum = max($minimum + 1, (int) $participant_state['scaleMax']);
            $counts = array_fill(0, $maximum - $minimum + 1, 0);
            foreach ($responses as $response) {
                $value = (int) ($response['value'] ?? 0);
                if ($value >= $minimum && $value <= $maximum) {
                    $counts[$value - $minimum]++;
                }
            }
            $participant_state['counts'] = $counts;

            return;
        }

        $texts = array_values(array_filter(array_map(
            static fn (array $response): string => sanitize_text_field((string) ($response['text'] ?? '')),
            $responses
        )));
        if ('wordcloud' === $response_type) {
            $words = array();
            foreach ($texts as $text) {
                $key = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
                if (! isset($words[$key])) {
                    $words[$key] = array('text' => $text, 'count' => 0);
                }
                $words[$key]['count']++;
            }
            uasort($words, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $participant_state['words'] = array_values($words);
        } else {
            $participant_state['items'] = $texts;
        }
    }
}
