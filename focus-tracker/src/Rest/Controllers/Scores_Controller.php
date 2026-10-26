<?php
declare(strict_types=1);

namespace FocusTracker\Rest\Controllers;

use FocusTracker\Addon\Context_Resolver;
use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Scoring\Activity_Score_Service;
use FocusTracker\Scoring\Score_Calculator;
use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Clock;
use FocusTracker\Privacy\Privacy_Guard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Read-only score/timeline endpoints consumed by resources/js/dashboard.js
 * (see docs/DESIGN.md §6, §10 Phase 3).
 */
final class Scores_Controller {
    public static function register_routes(): void {
        register_rest_route('focus-tracker/v1', '/activities/(?P<id>\d+)/scores', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'activity_scores'),
            'permission_callback' => array(__CLASS__, 'can_view'),
        ));

        register_rest_route('focus-tracker/v1', '/activities/(?P<id>\d+)/participants/(?P<user_id>\d+)/timeline', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'timeline'),
            'permission_callback' => array(__CLASS__, 'can_view_participant'),
        ));
    }

    public static function can_view(): bool|WP_Error {
        return Capabilities::can_view() ?: new WP_Error('focus_tracker_forbidden', __('열람 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
    }

    public static function can_view_participant(WP_REST_Request $request): bool|WP_Error {
        $user_id = get_current_user_id();
        $target_user_id = (int) $request->get_param('user_id');

        // A student may always see their own timeline.
        if (Capabilities::can_view_self() && $target_user_id === $user_id) {
            $own_activity = Activity_Repository::find((int) $request->get_param('id'));
            return $own_activity !== null && $own_activity['status'] !== Activity_Repository::STATUS_DELETED ? true : new WP_Error('focus_tracker_not_found', __('활동을 찾을 수 없습니다.', 'focus-tracker'), array('status' => 404));
        }

        if (! Capabilities::can_view()) {
            return new WP_Error('focus_tracker_forbidden', __('열람 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
        }

        // Capabilities::can_view() only proves the *feature* is enabled for
        // this teacher — it says nothing about whether THIS activity's
        // class/group is theirs. Without this check, any teacher with the
        // capability could read any other teacher's student's timeline by
        // guessing an activity_id + user_id.
        $activity = Activity_Repository::find((int) $request->get_param('id'));
        if (null === $activity || $activity['status'] === Activity_Repository::STATUS_DELETED) {
            return new WP_Error('focus_tracker_not_found', __('활동을 찾을 수 없습니다.', 'focus-tracker'), array('status' => 404));
        }

        try {
            $context_revision = Context_Resolver::authorize($user_id, (string) $activity['context_type'], (string) $activity['context_id'], Context_Resolver::OPERATION_REPORT, $target_user_id);
            $current = $context_revision === null ? array() : Context_Resolver::list_all_learner_ids($user_id, (string) $activity['context_type'], (string) $activity['context_id'], $context_revision);
        } catch (\Throwable $error) { $current = array(); }
        if (! in_array($target_user_id, $current, true)) {
            return new WP_Error('focus_tracker_context_denied', __('해당 학급/모둠의 결과를 열람할 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
        }

        return true;
    }

    public static function activity_scores(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $activity_id = (int) $request->get_param('id');
        $activity = Activity_Repository::find($activity_id);

        if (null === $activity || $activity['status'] === Activity_Repository::STATUS_DELETED) {
            return new WP_Error('focus_tracker_not_found', __('활동을 찾을 수 없습니다.', 'focus-tracker'), array('status' => 404));
        }

        $user_id = get_current_user_id();
        $context_revision = Context_Resolver::authorize($user_id, (string) $activity['context_type'], (string) $activity['context_id'], Context_Resolver::OPERATION_REPORT);

        if (null === $context_revision) {
            return new WP_Error('focus_tracker_context_denied', __('해당 학급/모둠의 결과를 열람할 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
        }

        $learner_ids = Context_Resolver::list_all_learner_ids($user_id, (string) $activity['context_type'], (string) $activity['context_id'], $context_revision);
        $force_refresh = filter_var($request->get_param('refresh'), FILTER_VALIDATE_BOOLEAN);
        return Privacy_Guard::run_many($learner_ids, static fn () => self::activity_scores_response($activity, $learner_ids, $force_refresh));
    }

    private static function activity_scores_response(array $activity, array $learner_ids, bool $force_refresh): WP_REST_Response|WP_Error {
        $activity_id = (int) $activity['id'];
        $activity = Activity_Repository::find($activity_id, true);
        if ($activity === null || $activity['status'] === Activity_Repository::STATUS_DELETED) { return new WP_Error('focus_tracker_not_found', __('활동을 찾을 수 없습니다.', 'focus-tracker'), array('status' => 404)); }
        $scores = Activity_Score_Service::scores_for_activity($activity, $learner_ids, $force_refresh);

        $rows = array();
        foreach ($learner_ids as $learner_id) {
            $metrics = $scores[$learner_id];
            // The Context Gateway deliberately never exposes learner names
            // (see docs/DESIGN.md §7 research notes — CG11 roster hides
            // PII by design). learnerUserId() is a plain WP user id though,
            // so a normal get_userdata() lookup here is the addon's own
            // WordPress code reading WordPress's own user table, not a
            // Context Gateway PII leak.
            $user = get_userdata($learner_id);
            $rows[] = array_merge(
                array('userId' => $learner_id, 'displayName' => $user ? $user->display_name : (string) $learner_id),
                self::present_metrics($metrics)
            );
        }

        return rest_ensure_response(array(
            'activity' => array(
                'id' => (int) $activity['id'],
                'name' => (string) $activity['name'],
                'status' => (string) $activity['status'],
                'weightFocus' => (float) $activity['weight_focus'],
                'weightSelfreg' => (float) $activity['weight_selfreg'],
                'weightActivity' => (float) $activity['weight_activity'],
            ),
            'rows' => $rows,
        ));
    }

    public static function timeline(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $allowed = self::can_view_participant($request);
        if ($allowed !== true) { return $allowed; }
        return Privacy_Guard::run((int) $request->get_param('user_id'), static fn () => self::timeline_response($request));
    }

    private static function timeline_response(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $activity_id = (int) $request->get_param('id');
        $target_user_id = (int) $request->get_param('user_id');

        $activity = Activity_Repository::find($activity_id, true);
        if (null === $activity || $activity['status'] === Activity_Repository::STATUS_DELETED) {
            return new WP_Error('focus_tracker_not_found', __('활동을 찾을 수 없습니다.', 'focus-tracker'), array('status' => 404));
        }

        $participations = Participation_Repository::list_for_activity_and_user($activity_id, $target_user_id);
        $metrics = Activity_Score_Service::score_for_learner($activity, $target_user_id);
        $now = Clock::now_ms();
        $participations = Activity_Score_Service::participations_for_activity($activity, $participations, $now);
        $start = Clock::mysql_to_ms((string) $activity['starts_at']);
        $end = max($start, min($now, Clock::mysql_to_ms((string) $activity['ends_at'])));
        $timeline = array('intervals' => array(), 'markers' => array());
        if (in_array($metrics['data_status'], array('complete', 'provisional', 'incomplete'), true)) {
            $inputs = array_values(array_filter(
                Activity_Score_Service::participation_inputs($participations),
                static fn (array $p): bool => ! empty($p['events'])
            ));
            $timeline = Score_Calculator::timeline_for_participations(
                $inputs, $start, $end, $now
            );
        }

        return rest_ensure_response(array(
            'windowStartsAtMs' => $start,
            'windowEndsAtMs' => $end,
            'spans' => $timeline['intervals'],
            'markers' => $timeline['markers'],
            'dataStatus' => $metrics['data_status'],
            'needsReview' => $metrics['needs_review'],
            'finalized' => $metrics['finalized'],
            'hasDeliveryGaps' => $metrics['has_delivery_gaps'],
            'hasObservationGaps' => $metrics['has_observation_gaps'],
        ));
    }

    /** @param array<string, mixed> $metrics */
    private static function present_metrics(array $metrics): array {
        return array(
            'totalActiveSeconds' => $metrics['total_active_seconds'],
            'totalHiddenSeconds' => $metrics['total_hidden_seconds'],
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
            'hasObservationGaps' => $metrics['has_observation_gaps'],
            'abandonedWithoutReturn' => $metrics['abandoned_without_return'],
        );
    }
}
