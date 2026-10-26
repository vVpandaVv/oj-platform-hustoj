<?php
declare(strict_types=1);

namespace FocusTracker\Rest\Controllers;

use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Scoring\Activity_Score_Service;
use FocusTracker\Checks\Check_Service;
use FocusTracker\Support\Capabilities;
use FocusTracker\Privacy\Privacy_Guard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Backs the Phase 2 student self-view page (Addon\Self_View_Page_Handler,
 * docs/DESIGN.md §10): a learner's own most recent activity score, nothing
 * about classmates.
 */
final class Me_Controller {
    public static function register_routes(): void {
        register_rest_route('focus-tracker/v1', '/me/summary', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'summary'),
            'permission_callback' => array(__CLASS__, 'can_view_self'),
        ));
    }

    public static function can_view_self(): bool|WP_Error {
        return Capabilities::can_view_self() ?: new WP_Error('focus_tracker_forbidden', __('열람 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
    }

    public static function summary(WP_REST_Request $request): WP_REST_Response|WP_Error {
        return Privacy_Guard::run(get_current_user_id(), static fn () => self::summary_response());
    }

    private static function summary_response(): WP_REST_Response {
        $user_id = get_current_user_id();
        $participation = Participation_Repository::find_latest_activity_participation_for_user($user_id);
        $check_summary = Check_Service::summary($user_id);

        if (null === $participation) {
            return rest_ensure_response(array('hasActivity' => false, 'learningChecks' => $check_summary));
        }

        $activity = Activity_Repository::find((int) $participation['activity_id'], true);
        if (null === $activity || $activity['status'] === Activity_Repository::STATUS_DELETED) {
            return rest_ensure_response(array('hasActivity' => false, 'learningChecks' => $check_summary));
        }

        $metrics = Activity_Score_Service::score_for_learner($activity, $user_id);

        return rest_ensure_response(array(
            'hasActivity' => true,
            'learningChecks' => $check_summary,
            'activityName' => $activity['name'],
            'focusRatio' => $metrics['focus_ratio'],
            'blurCount' => $metrics['blur_count'],
            'avgRecoverySeconds' => $metrics['avg_recovery_seconds'],
            'activityWindowRatio' => $metrics['activity_window_ratio'],
            'scoreFocus' => $metrics['score_focus'],
            'scoreSelfreg' => $metrics['score_selfreg'],
            'scoreActivity' => $metrics['score_activity'],
            'scoreTotal' => $metrics['score_total'],
            'needsReview' => $metrics['needs_review'],
            'dataStatus' => $metrics['data_status'],
            'finalized' => $metrics['finalized'],
            'hasDeliveryGaps' => $metrics['has_delivery_gaps'],
            'hasObservationGaps' => $metrics['has_observation_gaps'] ?? false,
        ));
    }
}
