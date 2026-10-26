<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use TeachingTools\Sessions\Session_Code_Guard;
use TeachingTools\Sessions\Tool_Session_Repository;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class Classroom_Controller {
    private const CODE_PATTERN = '(?P<code>[A-Za-z0-9]{6})';
    private const SIGNALS = array('', 'ready', 'understood', 'unsure', 'repeat', 'help', 'done');
    private const ONLINE_WINDOW_SECONDS = 30;

    public static function register_routes(): void {
        register_rest_route(
            'teaching-tools/v1',
            '/classroom/active',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'active_session'),
                'permission_callback' => array(__CLASS__, 'can_discover'),
            )
        );

        register_rest_route('teaching-tools/v1', '/discovery', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'discover'),
            'permission_callback' => array(__CLASS__, 'can_discover'),
        ));

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/participant-state',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'update_participant_state'),
                'permission_callback' => array(Capabilities::class, 'can_join'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/participants',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'participants'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/signals/clear',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'clear_signals'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/activity',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'update_activity'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            )
        );

        register_rest_route('teaching-tools/v1', '/sessions/' . self::CODE_PATTERN . '/roster-refresh', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'roster_refresh'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'roster_refresh'),
                'permission_callback' => array(Capabilities::class, 'can_host'),
            ),
        ));
    }

    public static function can_discover(): bool {
        return Capabilities::can_host() || Capabilities::can_join();
    }

    public static function active_session() {
        return rest_ensure_response(self::active_session_data());
    }

    public static function discover() {
        return rest_ensure_response(array(
            'classroom' => self::active_session_data(),
            'items' => Capabilities::can_join() ? Sessions_Controller::assigned_items() : array(),
        ));
    }

    private static function active_session_data(): array {
        $user_id = get_current_user_id();
        $sessions = array();
        if (Capabilities::can_host()) {
            foreach (Tool_Session_Repository::active_classrooms_for_host($user_id) as $session) {
                $sessions[(int) $session['id']] = self::present_active_session(
                    $session,
                    Tool_Session_Repository::host_role((int) $session['id'], $user_id) ?? 'observer'
                );
            }
        }
        if (Capabilities::can_join()) {
            foreach (Tool_Session_Repository::active_classrooms_for_participant($user_id) as $session) {
                $sessions[(int) $session['id']] ??= self::present_active_session($session, 'participant');
            }
        }
        $sessions = array_values($sessions);
        if ([] === $sessions) {
            return array('active' => false);
        }
        $first = $sessions[0];

        return array(
            'active' => true,
            'joinCode' => $first['joinCode'],
            'role' => $first['role'],
            'sessions' => $sessions,
        );
    }

    public static function update_participant_state(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $session = self::participant_session($request, $user_id);
        if (is_wp_error($session)) {
            return $session;
        }

        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $patch = array('lastSeenAt' => time());

        if (array_key_exists('signal', $params)) {
            $signal = sanitize_key((string) $params['signal']);
            if (! in_array($signal, self::SIGNALS, true)) {
                return new WP_Error(
                    'teaching_tools_invalid_signal',
                    __('알 수 없는 수업 신호입니다.', 'teaching-tools'),
                    array('status' => 400)
                );
            }
            $patch['signal'] = $signal;
            $patch['signalAt'] = '' === $signal ? 0 : time();
        }

        if (! Tool_Session_Repository::update_participant_data((int) $session['id'], $user_id, $patch)) {
            return new WP_Error(
                'teaching_tools_participant_update_failed',
                __('참여 상태를 저장하지 못했습니다.', 'teaching-tools'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array('ok' => true, 'state' => $patch));
    }

    public static function participants(WP_REST_Request $request) {
        $session = self::owner_session($request, get_current_user_id(), false);
        if (is_wp_error($session)) {
            return $session;
        }

        return rest_ensure_response(self::present_participants((int) $session['id'], self::session_state($session)));
    }

    public static function clear_signals(WP_REST_Request $request) {
        $session = self::owner_session($request, get_current_user_id());
        if (is_wp_error($session)) {
            return $session;
        }

        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $target_user_id = isset($params['id']) ? absint($params['id']) : 0;
        $found = 0;

        foreach (Tool_Session_Repository::participants((int) $session['id']) as $participant) {
            $participant_user_id = (int) ($participant['user_id'] ?? 0);
            if ($target_user_id > 0 && $target_user_id !== $participant_user_id) {
                continue;
            }

            if (Tool_Session_Repository::update_participant_data(
                (int) $session['id'],
                $participant_user_id,
                array('signal' => '', 'signalAt' => 0)
            )) {
                $found++;
            }
        }

        if ($target_user_id > 0 && 0 === $found) {
            return new WP_Error(
                'teaching_tools_participant_not_found',
                __('참여 학생을 찾지 못했습니다.', 'teaching-tools'),
                array('status' => 404)
            );
        }

        return rest_ensure_response(self::present_participants((int) $session['id'], self::session_state($session)));
    }

    public static function update_activity(WP_REST_Request $request) {
        $session = self::owner_session($request, get_current_user_id());
        if (is_wp_error($session)) {
            return $session;
        }

        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $state = json_decode((string) ($session['state'] ?? '{}'), true);
        $state = is_array($state) ? $state : array();

        $delivery = array('type' => 'all', 'ids' => array(), 'label' => __('전체', 'teaching-tools'));
        if (! empty($params['clear'])) {
            $activity = null;
            $state['activities'] = array();
        } else {
            $title = self::limit_text(sanitize_text_field((string) ($params['title'] ?? '')), 100);
            $note = self::limit_text(sanitize_textarea_field((string) ($params['note'] ?? '')), 240);
            $raw_url = trim((string) ($params['url'] ?? ''));
            $url = str_starts_with($raw_url, '/')
                ? home_url($raw_url)
                : esc_url_raw($raw_url, array('http', 'https'));

            if ('' === $title || '' === $url || ! preg_match('#^https?://#i', $url)) {
                return new WP_Error(
                    'teaching_tools_invalid_activity',
                    __('활동 이름과 올바른 http 또는 https 주소를 입력하세요.', 'teaching-tools'),
                    array('status' => 400)
                );
            }

            $requested_ids = is_array($params['targetIds'] ?? null) ? $params['targetIds'] : array();
            $target_ids = array_values(array_unique(array_filter(
                array_map('absint', $requested_ids),
                static fn (int $id): bool => $id > 0
            )));
            if (count($target_ids) > 500) {
                return new WP_Error(
                    'teaching_tools_activity_target_limit',
                    __('활동 대상은 한 번에 500명까지 선택할 수 있습니다.', 'teaching-tools'),
                    array('status' => 400)
                );
            }
            if ([] !== $target_ids) {
                $participant_ids = array_fill_keys(array_map(
                    static fn (array $row): int => (int) ($row['user_id'] ?? 0),
                    Tool_Session_Repository::participants((int) $session['id'])
                ), true);
                $unknown_ids = array_values(array_filter(
                    $target_ids,
                    static fn (int $id): bool => ! isset($participant_ids[$id])
                ));
                if ([] !== $unknown_ids) {
                    return new WP_Error(
                        'teaching_tools_activity_target_invalid',
                        __('현재 수업에 참여하지 않은 학생이 대상에 포함되어 있습니다.', 'teaching-tools'),
                        array('status' => 400, 'ids' => $unknown_ids)
                    );
                }
                $delivery = array(
                    'type' => 'ids',
                    'ids' => $target_ids,
                    'label' => self::limit_text(
                        sanitize_text_field((string) ($params['targetLabel'] ?? '')),
                        80
                    ),
                );
                if ('' === $delivery['label']) {
                    $delivery['label'] = sprintf(__('선택 학생 %d명', 'teaching-tools'), count($target_ids));
                }
            }

            $activity = array(
                'id' => wp_generate_uuid4(),
                'title' => $title,
                'url' => $url,
                'note' => $note,
                'sentAt' => time(),
                'target' => $delivery,
            );
            $activities = array_values(array_filter(
                is_array($state['activities'] ?? null) ? $state['activities'] : array(),
                'is_array'
            ));
            $activities[] = $activity;
            $state['activities'] = array_slice($activities, -100);
        }

        $state['activity'] = $activity;
        if (! Tool_Session_Repository::update_state((int) $session['id'], $state)) {
            return new WP_Error(
                'teaching_tools_activity_update_failed',
                __('활동 안내를 저장하지 못했습니다.', 'teaching-tools'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array(
            'ok' => true,
            'activity' => $activity,
            'delivery' => $delivery,
            'participantActivity' => self::present_activity($activity),
        ));
    }

    public static function roster_refresh(WP_REST_Request $request) {
        $session = self::owner_session($request, get_current_user_id());
        if (is_wp_error($session)) {
            return $session;
        }
        $state = self::session_state($session);
        $context = is_array($state['context'] ?? null) ? $state['context'] : array();
        $type = sanitize_key((string) ($context['type'] ?? ''));
        $id = sanitize_text_field((string) ($context['id'] ?? ''));
        if ('' === $type || '' === $id) {
            return new WP_Error('teaching_tools_context_required', __('자동 참여 학급이 연결된 수업에서만 사용할 수 있습니다.', 'teaching-tools'), array('status' => 400));
        }
        $latest = Roster_Controller::latest_context($type, $id, get_current_user_id());
        if (is_wp_error($latest)) {
            return $latest;
        }
        $students = Roster_Controller::students_for_context($type, $id, $latest['revision'], get_current_user_id());
        if (is_wp_error($students)) {
            return $students;
        }

        $current_rows = Tool_Session_Repository::participants((int) $session['id']);
        $current = array_fill_keys(array_map(static fn (array $row): int => (int) $row['user_id'], $current_rows), true);
        $next = array_fill_keys(array_map(static fn (array $student): int => (int) $student['id'], $students), true);
        $added = array_values(array_diff(array_keys($next), array_keys($current)));
        $removed = array_values(array_diff(array_keys($current), array_keys($next)));

        if (WP_REST_Server::CREATABLE === $request->get_method()) {
            $by_id = array_column($students, null, 'id');
            foreach ($added as $student_id) {
                $student = $by_id[$student_id];
                Tool_Session_Repository::add_participant((int) $session['id'], (int) $student_id, array(
                    'lastSeenAt' => 0,
                    'signal' => '',
                    'signalAt' => 0,
                    'autoEnrolled' => true,
                    'displayName' => (string) $student['displayName'],
                    'managementName' => (string) $student['managementName'],
                ));
            }
            foreach ($removed as $student_id) {
                Tool_Session_Repository::remove_participant((int) $session['id'], (int) $student_id);
            }
            $state['context'] = $latest;
            $state['audience'] = array_merge(
                is_array($state['audience'] ?? null) ? $state['audience'] : array(),
                array('label' => $latest['label'], 'memberCount' => count($students))
            );
            Tool_Session_Repository::update_state((int) $session['id'], $state);
        }

        return rest_ensure_response(array(
            'addedIds' => array_map('intval', $added),
            'removedIds' => array_map('intval', $removed),
            'memberCount' => count($students),
            'revision' => $latest['revision'],
            'applied' => WP_REST_Server::CREATABLE === $request->get_method(),
        ));
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
            || 'classroom' !== (string) $session['tool_type']
            || 'live' !== (string) $session['mode']
            || ! Tool_Session_Repository::is_participant((int) $session['id'], $user_id)
        ) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        return $session;
    }

    /** @return array<string, mixed>|WP_Error */
    private static function owner_session(WP_REST_Request $request, int $user_id, bool $write = true) {
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $session = Tool_Session_Repository::find_active_by_code((string) $request['code']);
        $role = null === $session ? null : Tool_Session_Repository::host_role((int) $session['id'], $user_id);
        if (
            null === $session
            || 'classroom' !== (string) $session['tool_type']
            || null === $role
            || ($write && ! in_array($role, array('owner', 'cohost'), true))
        ) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return self::not_found_error();
        }

        return $session;
    }

    /** @return array{participants: list<array<string, mixed>>, participantCount: int, onlineCount: int} */
    private static function present_participants(int $session_id, array $state): array {
        $presented = array();
        $online_count = 0;
        $online_after = time() - self::ONLINE_WINDOW_SECONDS;
        $name_mode = sanitize_key((string) ($state['nameMode'] ?? 'display'));
        if (! in_array($name_mode, array('display', 'management', 'anonymous'), true)) {
            $name_mode = 'display';
        }

        foreach (Tool_Session_Repository::participants($session_id) as $index => $row) {
            $user_id = (int) ($row['user_id'] ?? 0);
            $data = json_decode((string) ($row['data'] ?? '{}'), true);
            $data = is_array($data) ? $data : array();
            $last_seen_at = max(0, (int) ($data['lastSeenAt'] ?? 0));
            $online = $last_seen_at >= $online_after;
            if ($online) {
                $online_count++;
            }

            $signal = sanitize_key((string) ($data['signal'] ?? ''));
            $display_name = sanitize_text_field((string) ($data['displayName'] ?? ''));
            $management_name = sanitize_text_field((string) ($data['managementName'] ?? ''));
            if ('' === $display_name) {
                $user = get_userdata($user_id);
                $display_name = $user ? sanitize_text_field((string) $user->display_name) : sprintf(__('학생 #%d', 'teaching-tools'), $user_id);
            }
            if ('' === $management_name) {
                $management_name = $display_name;
            }
            $name = match ($name_mode) {
                'management' => $management_name,
                'anonymous' => sprintf(__('학생 %02d', 'teaching-tools'), $index + 1),
                default => $display_name,
            };
            $presented[] = array(
                'id' => $user_id,
                'name' => $name,
                'joinedAt' => (string) ($row['joined_at'] ?? ''),
                'lastSeenAt' => $last_seen_at,
                'online' => $online,
                'signal' => in_array($signal, self::SIGNALS, true) ? $signal : '',
                'signalAt' => max(0, (int) ($data['signalAt'] ?? 0)),
            );
        }

        return array(
            'participants' => $presented,
            'participantCount' => count($presented),
            'onlineCount' => $online_count,
        );
    }

    /** @param array<string, mixed> $session @return array<string, mixed> */
    private static function session_state(array $session): array {
        $state = json_decode((string) ($session['state'] ?? '{}'), true);

        return is_array($state) ? $state : array();
    }

    /** @param array<string, mixed> $session @return array<string, mixed> */
    private static function present_active_session(array $session, string $role): array {
        $state = self::session_state($session);
        $audience = is_array($state['audience'] ?? null) ? $state['audience'] : array();

        return array(
            'joinCode' => (string) $session['join_code'],
            'role' => 'participant' === $role ? 'participant' : 'host',
            'hostRole' => 'participant' === $role ? null : $role,
            'label' => sanitize_text_field((string) ($audience['label'] ?? __('수업 신호', 'teaching-tools'))),
            'updatedAt' => (string) $session['updated_at'],
        );
    }

    private static function limit_text(string $value, int $length): string {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    /** @param array<string, mixed>|null $activity @return array<string, mixed>|null */
    private static function present_activity(?array $activity): ?array {
        if (null === $activity) {
            return null;
        }
        $url = esc_url_raw((string) ($activity['url'] ?? ''), array('http', 'https'));
        if ('' === $url || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return array(
            'id' => sanitize_text_field((string) ($activity['id'] ?? '')),
            'title' => sanitize_text_field((string) ($activity['title'] ?? '')),
            'url' => $url,
            'note' => sanitize_textarea_field((string) ($activity['note'] ?? '')),
            'sentAt' => max(0, (int) ($activity['sentAt'] ?? 0)),
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
