<?php
declare(strict_types=1);

namespace FocusTracker\Rest\Controllers;

use FocusTracker\Addon\Context_Resolver;
use FocusTracker\Privacy\Privacy_Guard;
use FocusTracker\Reporting\Statistics_Service;
use FocusTracker\Support\Capabilities;
use InvalidArgumentException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class Statistics_Controller {
    public static function register_routes(): void {
        register_rest_route('focus-tracker/v1', '/me/statistics', array('methods' => WP_REST_Server::READABLE, 'callback' => array(__CLASS__, 'self_statistics'), 'permission_callback' => array(__CLASS__, 'authorize_self')));
        register_rest_route('focus-tracker/v1', '/statistics', array('methods' => WP_REST_Server::READABLE, 'callback' => array(__CLASS__, 'report'), 'permission_callback' => array(__CLASS__, 'authorize_report')));
    }

    public static function authorize_self(): bool|WP_Error {
        return is_user_logged_in() && Capabilities::can_view_self() ? true : self::forbidden();
    }

    public static function authorize_report(): bool|WP_Error {
        return is_user_logged_in() && Capabilities::can_view() ? true : self::forbidden();
    }

    public static function self_statistics(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (true !== self::authorize_self()) { return self::forbidden(); }
        // Identity is session-derived. A query parameter cannot select a peer.
        if (null !== $request->get_param('user_id') && (int) $request->get_param('user_id') !== get_current_user_id()) { return self::forbidden(); }
        return self::make($request, get_current_user_id(), array('type' => 'self', 'id' => ''));
    }

    public static function report(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (true !== self::authorize_report()) { return self::forbidden(); }
        $type = $request->get_param('context_type');
        $id = $request->get_param('context_id');
        $target = filter_var($request->get_param('user_id'), FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if (! is_string($type) || ! in_array($type, array('class', 'group'), true) || ! is_string($id) || trim($id) === '' || strlen($id) > 160 || preg_match('//u', $id) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $id) || false === $target) {
            return new WP_Error('focus_tracker_invalid_statistics', '학급·모둠과 학생을 선택해 주세요.', array('status' => 400));
        }
        try {
            $revision = Context_Resolver::authorize(get_current_user_id(), $type, $id, Context_Resolver::OPERATION_REPORT, $target);
            if (null === $revision || ! in_array($target, Context_Resolver::list_all_learner_ids(get_current_user_id(), $type, $id, $revision), true)) { return self::forbidden(); }
            return self::make($request, $target, array('type' => $type, 'id' => $id));
        } catch (Throwable $error) { return self::forbidden(); }
    }

    private static function make(WP_REST_Request $request, int $user_id, array $scope): WP_REST_Response|WP_Error {
        $period = $request->get_param('period') ?? 'week';
        $date = $request->get_param('date');
        if (! is_string($period) || (null !== $date && ! is_string($date))) { return new WP_Error('focus_tracker_invalid_statistics', '조회 기간이 올바르지 않습니다.', array('status' => 400)); }
        try {
            return Privacy_Guard::run($user_id, static function () use ($user_id, $period, $date, $scope): WP_REST_Response|WP_Error {
                try {
                    return rest_ensure_response(Statistics_Service::report($user_id, $period, $date, $scope));
                } catch (InvalidArgumentException $error) {
                    return new WP_Error('focus_tracker_invalid_statistics', $error->getMessage(), array('status' => 400));
                } catch (Throwable $error) {
                    return new WP_Error('focus_tracker_statistics_unavailable', '통계를 읽거나 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.', array('status' => 503));
                }
            });
        } catch (InvalidArgumentException $error) {
            return new WP_Error('focus_tracker_invalid_statistics', $error->getMessage(), array('status' => 400));
        } catch (Throwable $error) {
            return new WP_Error('focus_tracker_statistics_unavailable', '통계를 읽거나 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.', array('status' => 503));
        }
    }

    private static function forbidden(): WP_Error { return new WP_Error('focus_tracker_statistics_denied', '이 학생의 해당 학급·모둠 통계를 열람할 권한이 없습니다.', array('status' => 403)); }
}
