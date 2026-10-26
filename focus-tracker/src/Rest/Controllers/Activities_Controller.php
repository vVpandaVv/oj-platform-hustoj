<?php
declare(strict_types=1);

namespace FocusTracker\Rest\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use FocusTracker\Addon\Context_Resolver;
use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Score_Repository;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Support\Capabilities;
use FocusTracker\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * CRUD for teacher-created "활동" (activity) rows: name, target class/group,
 * time window, per-activity scoring weights (see docs/DESIGN.md §4, §7).
 * Every write authorizes the specific context via the Context Gateway
 * (Context_Resolver::authorize) — Capabilities::can_manage() alone only
 * proves the *feature* is enabled for this user, not that they own this
 * particular class.
 */
final class Activities_Controller {
    public static function register_routes(): void {
        register_rest_route('focus-tracker/v1', '/activities', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'index'),
                'permission_callback' => array(__CLASS__, 'can_view'),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array(__CLASS__, 'create'),
                'permission_callback' => array(__CLASS__, 'can_manage'),
            ),
        ));

        register_rest_route('focus-tracker/v1', '/activities/(?P<id>\d+)', array(
            array('methods' => WP_REST_Server::EDITABLE, 'callback' => array(__CLASS__, 'update'), 'permission_callback' => array(__CLASS__, 'can_manage')),
            array('methods' => WP_REST_Server::DELETABLE, 'callback' => array(__CLASS__, 'delete'), 'permission_callback' => array(__CLASS__, 'authorize_delete')),
        ));
    }

    public static function can_view(): bool|WP_Error {
        return Capabilities::can_view() ?: new WP_Error('focus_tracker_forbidden', __('열람 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
    }

    public static function can_manage(): bool|WP_Error {
        return Capabilities::can_manage() ?: new WP_Error('focus_tracker_forbidden', __('관리 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
    }

    public static function authorize_delete(WP_REST_Request $request): bool|WP_Error {
        $allowed = self::can_manage();
        if ($allowed !== true) { return $allowed; }
        $nonce = (string) $request->get_header('X-WP-Nonce');
        if ($nonce === '') { $nonce = (string) $request->get_param('_wpnonce'); }
        return wp_verify_nonce($nonce, 'wp_rest') !== false ? true : new WP_Error('focus_tracker_bad_nonce', __('요청을 검증할 수 없습니다.', 'focus-tracker'), array('status' => 403));
    }

    /** Hide a schedule and stop its authorization, retaining historical records. */
    public static function delete(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $allowed = self::authorize_delete($request);
        if ($allowed !== true) { return $allowed; }
        $id = filter_var($request->get_param('id'), FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($id === false) { return self::validation_error(__('활동 번호가 올바르지 않습니다.', 'focus-tracker')); }
        global $wpdb;
        if (false === $wpdb->query('START TRANSACTION')) { return self::delete_failed(); }
        try {
            // Do not acquire learner privacy/quota locks after this activity
            // lock. Ingestion/checks already take those before activity locks.
            $activity = Activity_Repository::find($id, true);
            if ('' !== (string) $wpdb->last_error) { throw new \RuntimeException('activity_read'); }
            if ($activity === null || $activity['owner_user_id'] !== get_current_user_id()) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('focus_tracker_not_found', __('활동을 찾을 수 없습니다.', 'focus-tracker'), array('status' => 404));
            }
            try { $revision = Context_Resolver::authorize(get_current_user_id(), $activity['context_type'], $activity['context_id'], Context_Resolver::OPERATION_MANAGE); }
            catch (\Throwable $error) { $revision = null; }
            if ($revision === null) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('focus_tracker_context_denied', __('해당 학급/그룹을 관리할 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
            }
            if ($activity['status'] === Activity_Repository::STATUS_DELETED) {
                if (false === $wpdb->query('COMMIT')) { $wpdb->query('ROLLBACK'); return self::delete_failed(); }
                return rest_ensure_response(array('id' => $id, 'deleted' => true));
            }
            $end = min($activity['ends_at'], current_time('mysql', true));
            if ($activity['status'] === Activity_Repository::STATUS_CLOSED) { $end = min($end, $activity['updated_at']); }
            $end = max($activity['starts_at'], $end); // An unstarted deleted schedule has an empty historical window.
            if (! Activity_Repository::update($id, array('status' => Activity_Repository::STATUS_DELETED, 'ends_at' => $end))
                || ! \FocusTracker\Checks\Check_Service::reconcile_activity_windows($id)) { throw new \RuntimeException('activity_delete'); }
            Score_Repository::invalidate_activity($id);
            if ('' !== (string) $wpdb->last_error || false === $wpdb->query('COMMIT')) { throw new \RuntimeException('activity_commit'); }
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); return self::delete_failed(); }
        \FocusTracker\Support\Audit_Log::record('activity_deleted', $id, array('status', 'ends_at'));
        return rest_ensure_response(array('id' => $id, 'deleted' => true));
    }

    private static function delete_failed(): WP_Error { return new WP_Error('focus_tracker_delete_failed', __('활동을 삭제하지 못했습니다. 다시 시도해 주세요.', 'focus-tracker'), array('status' => 503)); }

    public static function index(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $status = $request->get_param('status');
        $type = $request->get_param('context_type');
        $id = $request->get_param('context_id');
        if ((null !== $status && (! is_string($status) || ! in_array($status, array('scheduled', 'active', 'closed'), true)))
            || ((null === $type) !== (null === $id))
            || (null !== $type && (! is_string($type) || ! in_array($type, array('class', 'group'), true) || ! is_string($id) || ! self::valid_context_id($id)))) {
            return self::validation_error(__('활동 조회 조건이 올바르지 않습니다.', 'focus-tracker'));
        }
        if (null !== $type && null === Context_Resolver::authorize(get_current_user_id(), $type, $id, Context_Resolver::OPERATION_REPORT)) {
            return new WP_Error('focus_tracker_context_denied', __('해당 학급/그룹의 활동을 열람할 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
        }
        $activities = Activity_Repository::list_for_owner(get_current_user_id(), $status, $type, $id);
        $contexts = array();
        foreach (Context_Resolver::list_teaching_contexts(get_current_user_id()) as $context) { $contexts[$context['type'] . ':' . $context['id']] = $context['label']; }
        $presented = array();
        foreach ($activities as $activity) {
            if (null === Context_Resolver::authorize(get_current_user_id(), $activity['context_type'], $activity['context_id'], Context_Resolver::OPERATION_REPORT)) { continue; }
            $item = self::present($activity);
            $item['contextLabel'] = $contexts[$activity['context_type'] . ':' . $activity['context_id']] ?? $activity['context_id'];
            $presented[] = $item;
        }
        return rest_ensure_response($presented);
    }

    public static function create(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = self::json_body($request);
        $user_id = get_current_user_id();

        foreach (array('name', 'context_type', 'context_id', 'starts_at', 'ends_at') as $field) {
            if (! isset($params[$field]) || ! is_string($params[$field])) { return self::validation_error(__('활동 입력 형식이 올바르지 않습니다.', 'focus-tracker')); }
        }

        $name = isset($params['name']) ? sanitize_text_field((string) $params['name']) : '';
        $context_type = isset($params['context_type']) ? sanitize_key((string) $params['context_type']) : '';
        $context_id = isset($params['context_id']) ? sanitize_text_field((string) $params['context_id']) : '';
        $starts_at = self::to_mysql_utc(isset($params['starts_at']) ? (string) $params['starts_at'] : '');
        $ends_at = self::to_mysql_utc(isset($params['ends_at']) ? (string) $params['ends_at'] : '');
        $problem_ids = self::sanitize_problem_ids($params['problem_ids'] ?? null);
        if ($problem_ids instanceof WP_Error) { return $problem_ids; }

        if (! self::valid_name($name)) {
            return self::validation_error(__('활동 이름을 입력해 주세요.', 'focus-tracker'));
        }

        if (! in_array($context_type, array('class', 'group'), true) || ! self::valid_context_id($context_id)) {
            return self::validation_error(__('대상 학급/그룹을 선택해 주세요.', 'focus-tracker'));
        }

        if (null === $starts_at || null === $ends_at || $ends_at <= $starts_at) {
            return self::validation_error(__('종료 시각은 시작 시각보다 뒤여야 합니다.', 'focus-tracker'));
        }

        $context_revision = Context_Resolver::authorize($user_id, $context_type, $context_id, Context_Resolver::OPERATION_MANAGE);
        if (null === $context_revision) {
            return new WP_Error('focus_tracker_context_denied', __('해당 학급/그룹을 관리할 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
        }

        $settings = Settings::all();
        foreach (array('weight_focus', 'weight_selfreg', 'weight_activity') as $key) {
            if (isset($params[$key]) && ! self::valid_weight($params[$key])) {
                return self::validation_error(__('가중치는 0 이상 100 이하의 숫자여야 합니다.', 'focus-tracker'));
            }
        }
        $activity_id = Activity_Repository::create(array(
            'name' => $name,
            'owner_user_id' => $user_id,
            'context_type' => $context_type,
            'context_id' => $context_id,
            'problem_ids' => $problem_ids,
            'starts_at' => $starts_at,
            'ends_at' => $ends_at,
            'weight_focus' => isset($params['weight_focus']) ? (float) $params['weight_focus'] : $settings['weight_focus'],
            'weight_selfreg' => isset($params['weight_selfreg']) ? (float) $params['weight_selfreg'] : $settings['weight_selfreg'],
            'weight_activity' => isset($params['weight_activity']) ? (float) $params['weight_activity'] : $settings['weight_activity'],
        ));

        if (null === $activity_id) {
            return new WP_Error('focus_tracker_create_failed', __('활동을 만들지 못했습니다.', 'focus-tracker'), array('status' => 500));
        }

        $created = Activity_Repository::find($activity_id);
        \FocusTracker\Support\Audit_Log::record('activity_created', $activity_id);
        self::reconcile($created, $context_revision);
        return rest_ensure_response(self::present($created));
    }

    public static function update(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        $activity = Activity_Repository::find($id);

        if (null === $activity || $activity['status'] === Activity_Repository::STATUS_DELETED || (int) $activity['owner_user_id'] !== get_current_user_id()) {
            return new WP_Error('focus_tracker_not_found', __('활동을 찾을 수 없습니다.', 'focus-tracker'), array('status' => 404));
        }

        $params = self::json_body($request);
        $update = array();

        foreach (array('name', 'starts_at', 'ends_at', 'status') as $field) {
            if (array_key_exists($field, $params) && ! is_string($params[$field])) { return self::validation_error(__('활동 입력 형식이 올바르지 않습니다.', 'focus-tracker')); }
        }
        if (array_key_exists('context_type', $params) || array_key_exists('context_id', $params) || array_key_exists('owner_user_id', $params)) {
            return self::validation_error(__('활동의 대상과 소유자는 변경할 수 없습니다. 새 활동을 만들어 주세요.', 'focus-tracker'));
        }

        $context_revision = Context_Resolver::authorize(get_current_user_id(), (string) $activity['context_type'], (string) $activity['context_id'], Context_Resolver::OPERATION_MANAGE);
        if (null === $context_revision) {
            return new WP_Error('focus_tracker_context_denied', __('해당 학급/그룹을 관리할 권한이 없습니다.', 'focus-tracker'), array('status' => 403));
        }
        if ($activity['status'] === Activity_Repository::STATUS_CLOSED) {
            return new WP_Error('focus_tracker_activity_closed', __('종료한 활동은 변경할 수 없습니다. 새 활동을 만들어 주세요.', 'focus-tracker'), array('status' => 409));
        }
        $scoring_fields = array('starts_at', 'ends_at', 'problem_ids', 'weight_focus', 'weight_selfreg', 'weight_activity', 'status');
        if (array_intersect($scoring_fields, array_keys($params)) && Activity_Repository::has_archived_scores($id)) {
            return new WP_Error('focus_tracker_activity_archived', __('원시 기록 보관 기간이 끝난 활동의 평가 범위는 변경할 수 없습니다.', 'focus-tracker'), array('status' => 409));
        }

        if (isset($params['name'])) {
            $update['name'] = sanitize_text_field((string) $params['name']);
            if (! self::valid_name($update['name'])) {
                return self::validation_error(__('활동 이름을 입력해 주세요.', 'focus-tracker'));
            }
        }
        if (isset($params['starts_at'])) {
            $converted = self::to_mysql_utc((string) $params['starts_at']);
            if (null === $converted) {
                return self::validation_error(__('시작 시각이 올바르지 않습니다.', 'focus-tracker'));
            }
            $update['starts_at'] = $converted;
        }
        if (isset($params['ends_at'])) {
            $converted = self::to_mysql_utc((string) $params['ends_at']);
            if (null === $converted) {
                return self::validation_error(__('종료 시각이 올바르지 않습니다.', 'focus-tracker'));
            }
            $update['ends_at'] = $converted;
        }
        if (isset($params['status'])) {
            if (! in_array($params['status'], array(Activity_Repository::STATUS_SCHEDULED, Activity_Repository::STATUS_ACTIVE, Activity_Repository::STATUS_CLOSED), true)) {
                return self::validation_error(__('활동 상태가 올바르지 않습니다.', 'focus-tracker'));
            }
            $update['status'] = $params['status'];
        }
        foreach (array('weight_focus', 'weight_selfreg', 'weight_activity') as $key) {
            if (isset($params[$key])) {
                if (! self::valid_weight($params[$key])) {
                    return self::validation_error(__('가중치는 0 이상 100 이하의 숫자여야 합니다.', 'focus-tracker'));
                }
                $update[$key] = (float) $params[$key];
            }
        }
        if (array_key_exists('problem_ids', $params)) {
            $update['problem_ids'] = self::sanitize_problem_ids($params['problem_ids']);
            if ($update['problem_ids'] instanceof WP_Error) { return $update['problem_ids']; }
        }

        // Closing an activity early also closes its observation window.
        if (($update['status'] ?? '') === Activity_Repository::STATUS_CLOSED && $activity['status'] !== Activity_Repository::STATUS_CLOSED) {
            $update['ends_at'] = min($update['ends_at'] ?? $activity['ends_at'], current_time('mysql', true));
        }
        if (($update['ends_at'] ?? $activity['ends_at']) <= ($update['starts_at'] ?? $activity['starts_at'])) {
            return self::validation_error(__('종료 시각은 시작 시각보다 뒤여야 합니다.', 'focus-tracker'));
        }
        global $wpdb;
        if (false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error('focus_tracker_update_failed', __('활동을 저장하지 못했습니다.', 'focus-tracker'), array('status' => 503));
        }
        $locked = Activity_Repository::find($id, true);
        if ('' !== (string) $wpdb->last_error) { $wpdb->query('ROLLBACK'); return new WP_Error('focus_tracker_update_failed', __('활동을 저장하지 못했습니다.', 'focus-tracker'), array('status' => 503)); }
        if ($locked === null || $locked['status'] === Activity_Repository::STATUS_DELETED || $locked['owner_user_id'] !== get_current_user_id()) {
            $wpdb->query('ROLLBACK'); return new WP_Error('focus_tracker_not_found', __('활동을 찾을 수 없습니다.', 'focus-tracker'), array('status' => 404));
        }
        if ($locked['status'] === Activity_Repository::STATUS_CLOSED) {
            $wpdb->query('ROLLBACK'); return new WP_Error('focus_tracker_activity_closed', __('종료한 활동은 변경할 수 없습니다.', 'focus-tracker'), array('status' => 409));
        }
        if (($update['ends_at'] ?? $locked['ends_at']) <= ($update['starts_at'] ?? $locked['starts_at'])) {
            $wpdb->query('ROLLBACK'); return self::validation_error(__('종료 시각은 시작 시각보다 뒤여야 합니다.', 'focus-tracker'));
        }
        if (! Activity_Repository::update($id, $update)
            || ! \FocusTracker\Checks\Check_Service::reconcile_activity_windows($id)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('focus_tracker_update_failed', __('활동을 저장하지 못했습니다.', 'focus-tracker'), array('status' => 500));
        }
        Score_Repository::invalidate_activity($id);
        if (false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('focus_tracker_update_failed', __('활동을 저장하지 못했습니다.', 'focus-tracker'), array('status' => 503));
        }

        $updated = Activity_Repository::find($id);
        \FocusTracker\Support\Audit_Log::record('activity_updated', $id, array_keys($update));
        self::reconcile($updated, $context_revision);
        return rest_ensure_response(self::present($updated));
    }

    /** @return array<string, mixed> */
    private static function present(?array $activity): array {
        if (null === $activity) {
            return array();
        }

        return array(
            'id' => $activity['id'],
            'name' => $activity['name'],
            'contextType' => $activity['context_type'],
            'contextId' => $activity['context_id'],
            'problemIds' => $activity['problem_ids'],
            // Stored values are always UTC (converted on write by to_mysql_utc()).
            'startsAt' => str_replace(' ', 'T', (string) $activity['starts_at']) . 'Z',
            'endsAt' => str_replace(' ', 'T', (string) $activity['ends_at']) . 'Z',
            'weightFocus' => $activity['weight_focus'],
            'weightSelfreg' => $activity['weight_selfreg'],
            'weightActivity' => $activity['weight_activity'],
            'status' => $activity['status'],
            'effectiveStatus' => Activity_Repository::effective_status($activity),
            'canEditSchedule' => $activity['status'] !== Activity_Repository::STATUS_CLOSED && ! Activity_Repository::has_archived_scores($activity['id']),
        );
    }

    private static function to_mysql_utc(string $input): ?string {
        $input = trim($input);
        if ('' === $input) {
            return null;
        }

        // Accept an actual calendar date, never relative dates or PHP's rollover
        // of an invalid date. Naive API values retain the site's timezone.
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(?:[zZ]|[+-]\d{2}:?\d{2})?$/D', $input, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) ($parts[6] ?? 0) > 59) { return null; }
        if (preg_match('/[+-](\d{2}):?(\d{2})$/D', $input, $offset)
            && ((int) $offset[1] > 14 || (int) $offset[2] > 59 || ((int) $offset[1] === 14 && (int) $offset[2] !== 0))) { return null; }

        try {
            // <input type="datetime-local"> submits a naive string with no
            // offset — treat that as the site's configured timezone rather
            // than the server process's default one.
            $has_offset = (bool) preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $input);
            $date = $has_offset ? new DateTimeImmutable($input) : new DateTimeImmutable($input, wp_timezone());
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) { return null; }
            if (! $has_offset) {
                $wall = substr(str_replace(' ', 'T', $input), 0, 19);
                $format = strlen($wall) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s';
                if ($date->format($format) !== $wall) { return null; }
            }
        } catch (Exception $e) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return list<int>|WP_Error|null */
    private static function sanitize_problem_ids($value): array|WP_Error|null {
        if (null === $value || $value === array()) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value) || count($value) > 1000) { return self::validation_error(__('대상 문제 번호가 올바르지 않습니다.', 'focus-tracker')); }
        $ids = array();
        foreach ($value as $item) {
            if ((! is_int($item) && ! is_string($item)) || false === ($id = filter_var($item, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))))) {
                return self::validation_error(__('대상 문제 번호는 양의 정수여야 합니다.', 'focus-tracker'));
            }
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    private static function validation_error(string $message): WP_Error {
        return new WP_Error('focus_tracker_invalid_payload', $message, array('status' => 400));
    }

    private static function valid_weight($value): bool {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0.0 && (float) $value <= 100.0;
    }

    private static function valid_context_id(string $id): bool {
        return strlen($id) <= 160 && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $id) === 1;
    }

    private static function valid_name(string $name): bool {
        return trim($name) !== '' && preg_match('/^.{1,191}$/usD', $name) === 1;
    }

    private static function reconcile(?array $activity, string $revision): void {
        if (null === $activity) { return; }
        $learner_ids = Context_Resolver::list_all_learner_ids(get_current_user_id(), (string) $activity['context_type'], (string) $activity['context_id'], $revision);
        Participation_Repository::link_matching_sessions($activity, $learner_ids);
    }

    /** @return array<string, mixed> */
    private static function json_body(WP_REST_Request $request): array {
        $params = $request->get_json_params();

        return is_array($params) ? $params : array();
    }
}
