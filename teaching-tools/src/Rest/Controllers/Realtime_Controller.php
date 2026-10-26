<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use TeachingTools\Sessions\Session_Code_Guard;
use TeachingTools\Sessions\Tool_Session_Repository;
use TeachingTools\Support\Capabilities;
use TeachingTools\Support\Realtime_Settings;
use TeachingTools\Support\Realtime_Ticket;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class Realtime_Controller {
    private const CODE_PATTERN = '(?P<code>[A-Za-z0-9]{6})';
    private const USER_TICKETS_PER_MINUTE = 30;
    private const IP_TICKETS_PER_MINUTE = 600;

    public static function register_routes(): void {
        register_rest_route(
            'teaching-tools/v1',
            '/sessions/' . self::CODE_PATTERN . '/realtime-ticket',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'ticket'),
                'permission_callback' => array(Sessions_Controller::class, 'can_view'),
            )
        );

        register_rest_route(
            'teaching-tools/v1',
            '/realtime/status',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'status'),
                'permission_callback' => array(Capabilities::class, 'can_manage_settings'),
            )
        );
    }

    public static function ticket(WP_REST_Request $request) {
        Realtime_Settings::ensure_secret();
        if (! Realtime_Settings::configured()) {
            return rest_ensure_response(array('mode' => 'poll'));
        }

        $user_id = get_current_user_id();
        if (! self::allow_ticket($user_id)) {
            return new WP_Error(
                'teaching_tools_realtime_ticket_rate_limited',
                __('실시간 연결 요청이 너무 많습니다. 잠시 뒤 다시 시도해 주세요.', 'teaching-tools'),
                array('status' => 429)
            );
        }
        $guard = Session_Code_Guard::check($user_id, (string) $request['code']);
        if (true !== $guard) {
            return $guard;
        }

        $session = Tool_Session_Repository::find_active_by_code((string) $request['code']);
        $host_role = null !== $session ? Tool_Session_Repository::host_role((int) $session['id'], $user_id) : null;
        $is_host = in_array($host_role, array('owner', 'cohost', 'observer'), true);
        $is_participant = null !== $session && ! $is_host
            && Tool_Session_Repository::is_participant((int) $session['id'], $user_id);

        $tool_type = null === $session ? '' : (string) $session['tool_type'];
        if (
            null === $session
            || ! in_array($tool_type, array('whiteboard', 'classroom', 'quickboard'), true)
            || 'live' !== (string) $session['mode']
            || (! $is_host && ! $is_participant)
        ) {
            Session_Code_Guard::record_failed_attempt($user_id, (string) $request['code']);

            return new WP_Error(
                'teaching_tools_session_not_found',
                __('참여 코드를 확인해 주세요.', 'teaching-tools'),
                array('status' => 404)
            );
        }

        $params = $request->get_json_params();
        $requested_role = is_array($params) ? ($params['role'] ?? null) : null;
        if (null !== $requested_role && ! in_array($requested_role, array('host', 'viewer'), true)) {
            return new WP_Error('teaching_tools_realtime_invalid_role', __('실시간 연결 역할이 올바르지 않습니다.', 'teaching-tools'), array('status' => 400));
        }
        $can_publish = in_array($host_role, array('owner', 'cohost'), true);
        if ('host' === $requested_role && ! $can_publish) {
            return new WP_Error('teaching_tools_realtime_publish_forbidden', __('수업 진행 권한이 없습니다.', 'teaching-tools'), array('status' => 403));
        }
        // A teacher can watch their own class without replacing the publishing connection.
        $socket_role = 'viewer' === $requested_role ? 'viewer' : ($can_publish ? 'host' : 'viewer');
        $ticket = Realtime_Ticket::issue(
            (int) $session['id'],
            $user_id,
            $socket_role,
            $tool_type
        );
        if ('' === $ticket) {
            return new WP_Error(
                'teaching_tools_realtime_ticket_failed',
                __('실시간 연결을 준비하지 못했습니다.', 'teaching-tools'),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array(
            'mode' => 'websocket',
            'url' => Realtime_Settings::public_url(),
            'ticket' => $ticket,
            'channel' => $tool_type,
            'expiresIn' => Realtime_Ticket::lifetime(),
        ));
    }

    public static function status() {
        Realtime_Settings::ensure_secret();
        if (! Realtime_Settings::enabled()) {
            return new WP_Error(
                'teaching_tools_realtime_disabled',
                __('실시간 전송이 꺼져 있습니다.', 'teaching-tools'),
                array('status' => 400)
            );
        }

        $time = (string) time();
        $signature = hash_hmac('sha256', "GET\n/status\n" . $time . "\n", Realtime_Settings::secret());
        $url = (string) preg_replace('#^ws(s?)://#i', 'http$1://', Realtime_Settings::public_url());
        $response = wp_remote_get(untrailingslashit($url) . '/status', array('timeout' => 4, 'headers' => array(
            'X-OJ-Realtime-Time' => $time, 'X-OJ-Realtime-Signature' => $signature,
        )));
        if (is_wp_error($response)) {
            return new WP_Error(
                'teaching_tools_realtime_unreachable',
                __('실시간 서버에 연결하지 못했습니다.', 'teaching-tools'),
                array('status' => 502, 'detail' => $response->get_error_message())
            );
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (200 !== $status || ! is_array($body) || empty($body['ok']) || empty($body['authenticated'])
            || 'oj-realtime-hub' !== ($body['service'] ?? '') || 'teaching-tools' !== ($body['app'] ?? '') || 1 !== ($body['protocol'] ?? null)) {
            return new WP_Error(
                'teaching_tools_realtime_bad_response',
                __('공통 서버의 티칭툴 등록·인증을 확인하지 못했습니다. 새 설치 명령으로 갱신하고 주소와 비밀키를 확인하세요.', 'teaching-tools'),
                array('status' => 502)
            );
        }

        return rest_ensure_response(array(
            'ok' => true,
            'version' => (string) ($body['version'] ?? ''),
            'service' => 'oj-realtime-hub',
            'app' => 'teaching-tools',
            'appVersion' => (string) ($body['appVersion'] ?? ''),
            'versions' => \TeachingTools\Support\Realtime_Versions::report(
                (string) ($body['appVersion'] ?? ''), (string) ($body['version'] ?? '')
            ),
            'channels' => (array) ($body['channels'] ?? array()),
            'authenticated' => true,
            'rooms' => (int) ($body['rooms'] ?? 0),
            'connections' => (int) ($body['connections'] ?? 0),
        ));
    }

    private static function allow_ticket(int $user_id): bool {
        $window = (string) floor(time() / MINUTE_IN_SECONDS);
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $limits = array(
            'user-' . $user_id => self::USER_TICKETS_PER_MINUTE,
            'ip-' . hash('sha256', $ip) => self::IP_TICKETS_PER_MINUTE,
        );
        foreach ($limits as $identity => $limit) {
            $key = 'htt_rt_' . md5($identity . ':' . $window);
            $count = (int) get_transient($key);
            if ($count >= $limit) {
                return false;
            }
            set_transient($key, $count + 1, 2 * MINUTE_IN_SECONDS);
        }

        return true;
    }
}
