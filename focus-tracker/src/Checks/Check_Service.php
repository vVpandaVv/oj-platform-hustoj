<?php
declare(strict_types=1);

namespace FocusTracker\Checks;

use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1;
use FocusTracker\Addon\Runtime_Bridge;
use FocusTracker\Database\Check_Repository;
use FocusTracker\Database\Activity_Repository;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;
use FocusTracker\Support\Diagnostics;
use FocusTracker\Support\Request_Limiter;
use FocusTracker\Tracking\Activity_Window_Gate;
use Throwable;
use WP_Error;

/** Independent learning evidence. No check outcome changes tracking/grade events. */
final class Check_Service {
    private const EXPIRY_MS = 600000;

    public static function next(int $user_id, array $input): array|WP_Error {
        $limit = Request_Limiter::check($user_id, 'check_next');
        if ($limit !== null) { Diagnostics::record('check', $limit->get_error_code() === 'focus_tracker_rate_limited' ? 'limited' : 'store'); return $limit; }
        try { $result = self::process_next($user_id, $input); }
        catch (Throwable $error) { $result = self::store_failed(); }
        if (is_array($result) && ! isset($result['reason'])) { $result['reason'] = in_array($result['status'] ?? '', array('inactive', 'cooldown'), true) ? $result['status'] : 'success'; }
        self::diagnose($result);
        return $result;
    }

    private static function process_next(int $user_id, array $input): array|WP_Error {
        if (! Settings::get('checks_enabled')) { return array('status' => 'unavailable', 'reason' => 'disabled', 'retryAfterMs' => 300000); }
        if (null === Runtime_Bridge::learning_checks()) { return array('status' => 'unavailable', 'reason' => 'unsupported', 'retryAfterMs' => 300000); }
        $problem_id = filter_var($input['problem_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if (false === $problem_id) { return self::invalid('문제 번호가 올바르지 않습니다.'); }
        $type = $input['context_type'] ?? 'general';
        $context_id = $input['context_id'] ?? null;
        if (null !== $context_id) {
            $context_id = filter_var($context_id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        }
        if (! is_string($type) || ! in_array($type, array('general', 'class', 'group', 'contest'), true)
            || false === $context_id || ($type === 'general' ? null !== $context_id : null === $context_id)) {
            return self::invalid('학습 문맥이 올바르지 않습니다.');
        }
        // No editor analysis or platform source/problem lookup outside a
        // currently authorised activity window.
        $gate = Activity_Window_Gate::state($user_id, $problem_id, $type, $context_id);
        if ($gate instanceof WP_Error) { return $gate; }
        if (empty($gate['active'])) { return self::inactive($user_id, $problem_id, $gate); }
        $state = Check_Repository::begin_for_user($user_id);
        if (null === $state) { return self::store_failed(); }
        try {
            // Locking policy reads serialize source analysis/issuance with a
            // teacher's close or shortened-window transaction.
            $gate = Activity_Window_Gate::state($user_id, $problem_id, $type, $context_id, null, true);
            if ($gate instanceof WP_Error || empty($gate['active'])) { return self::commit(self::inactive_result(is_array($gate) ? $gate : array())); }
            $draft = self::draft($input['draft'] ?? null);
            if ($draft instanceof WP_Error) { Check_Repository::finish(false); return $draft; }
            $submission_id = $input['submission_id'] ?? null;
            if (null !== $submission_id) {
                $submission_id = filter_var($submission_id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
                if (false === $submission_id) { Check_Repository::finish(false); return self::invalid('제출 번호가 올바르지 않습니다.'); }
            }
            if (Clock::now_ms() >= (int) $gate['windowEndsAtMs']) { return self::commit(self::inactive_result($gate)); }
            $context = self::context($user_id, $problem_id, $submission_id, $type, $context_id);
            if (null === $context) { return self::commit(array('status' => 'unavailable', 'reason' => 'access', 'retryAfterMs' => 60000)); }
            $now = Clock::now_ms();
            $pending = Check_Repository::pending($user_id, $now);
            if (! self::read_ok()) { return self::rollback(); }
            if (null !== $pending) {
                $pending_gate = self::gate_for_row($user_id, $pending);
                if (! self::same_window($pending, $pending_gate)) {
                    if (! Check_Repository::respond($pending['id'], $user_id, self::retirement_status($pending, $now), null, null, $now)) { return self::rollback(); }
                } else {
                    if ($pending['problem_id'] !== $problem_id || $pending['context_type'] !== $type || (string) $pending['context_id'] !== (string) $context_id) {
                        return self::commit(array('status' => 'cooldown', 'retryAfterMs' => max(1000, $pending['expires_at_ms'] - $now)));
                    }
                    $pending_context = self::context_for_row($user_id, $pending);
                    if (null === $pending_context || ! self::valid_snapshot($pending, $pending_context)) {
                        if (! Check_Repository::respond($pending['id'], $user_id, 'invalidated', null, null, $now)) { return self::rollback(); }
                    } else {
                        return self::commit(array('status' => 'ready', 'check' => self::present($pending)));
                    }
                }
            }
            $day = (new \DateTimeImmutable('@' . intdiv($now, 1000)))->setTimezone(wp_timezone())->format('Y-m-d');
            $count = $state['issued_day'] === $day ? (int) $state['issued_today'] : 0;
            $limit = max(1, min(20, (int) Settings::get('check_daily_limit')));
            if ($count >= $limit) { return self::commit(array('status' => 'cooldown', 'retryAfterMs' => 3600000)); }
            if ((int) $state['next_allowed_at_ms'] > $now) {
                return self::commit(array('status' => 'cooldown', 'retryAfterMs' => (int) $state['next_allowed_at_ms'] - $now));
            }
            $recent = Check_Repository::recent($user_id, $now - DAY_IN_SECONDS * 1000);
            if (! self::read_ok()) { return self::rollback(); }
            $gate = Activity_Window_Gate::state($user_id, $problem_id, $type, $context_id, null, true);
            if ($gate instanceof WP_Error || empty($gate['active'])) { return self::commit(self::inactive_result(is_array($gate) ? $gate : array())); }
            $generator = new Verified_Question_Generator();
            $candidates = $generator->generate_candidates($context, $draft);
            $candidate = self::choose($candidates, $recent, $problem_id);
            if (null === $candidate || ! $generator->verify($candidate, $context, $draft)) {
                if (! Check_Repository::save_state($user_id, array('next_allowed_at_ms' => $now + 60000))) { return self::rollback(); }
                return self::commit(array('status' => 'unavailable', 'reason' => 'no_candidate', 'retryAfterMs' => 60000));
            }
            $json = wp_json_encode($candidate, JSON_UNESCAPED_UNICODE);
            if (! is_string($json) || strlen($json) > 32768) { return self::rollback(); }
            $issued_at = Clock::now_ms();
            if ($issued_at >= (int) $gate['windowEndsAtMs']) { return self::commit(self::inactive_result($gate)); }
            $row = Check_Repository::create(array(
                'user_id' => $user_id, 'problem_id' => $problem_id,
                'submission_id' => $candidate['sourceKind'] === 'submission' ? (int) $context['submission']['id'] : null,
                'context_type' => $type, 'context_id' => $context_id,
                'problem_hash' => self::problem_hash($context), 'source_kind' => $candidate['sourceKind'],
                'source_hash' => $candidate['sourceHash'], 'candidate_json' => $json,
                'issued_at_ms' => $issued_at, 'expires_at_ms' => min($issued_at + self::EXPIRY_MS, (int) $gate['windowEndsAtMs']), 'status' => 'pending',
                'activity_ids_json' => wp_json_encode(self::activity_ids($gate['activityIds'] ?? array())),
                'window_start_ms' => (int) $gate['windowStartsAtMs'], 'window_end_ms' => (int) $gate['windowEndsAtMs'],
            ));
            $cooldown = max(60, min(3600, (int) Settings::get('check_cooldown_seconds'))) * 1000;
            if (null === $row || ! Check_Repository::save_state($user_id, array(
                'issued_day' => $day, 'issued_today' => $count + 1, 'next_allowed_at_ms' => $now + $cooldown,
            ))) { return self::rollback(); }
            return self::commit(array('status' => 'ready', 'check' => self::present($row)));
        } catch (Throwable $error) {
            return self::rollback();
        }
    }

    public static function respond(int $user_id, int $id, array $input): array|WP_Error {
        $limit = Request_Limiter::check($user_id, 'check_response');
        if ($limit !== null) { Diagnostics::record('check', $limit->get_error_code() === 'focus_tracker_rate_limited' ? 'limited' : 'store'); return $limit; }
        $result = self::process_response($user_id, $id, $input);
        self::diagnose($result);
        return $result;
    }

    private static function process_response(int $user_id, int $id, array $input): array|WP_Error {
        $uid = $input['response_uid'] ?? '';
        if (! is_string($uid) || preg_match('/^[a-zA-Z0-9_-]{8,64}$/D', $uid) !== 1) { return self::invalid('응답 식별자가 올바르지 않습니다.'); }
        $answer = $input['answer_id'] ?? null;
        $action = $input['action'] ?? null;
        if ((null !== $answer && (! is_string($answer) || strlen($answer) > 64))
            || (null !== $action && ! in_array($action, array('unknown', 'skip'), true))
            || ((null === $answer) === (null === $action))) { return self::invalid('응답을 하나 선택해 주세요.'); }
        if (null === Check_Repository::begin_for_user($user_id)) { return self::store_failed(); }
        try {
            $row = Check_Repository::find($id, $user_id);
            if (! self::read_ok()) { return self::rollback(); }
            if (null === $row) {
                Check_Repository::finish(false);
                return new WP_Error('focus_tracker_check_not_found', '문항을 찾을 수 없습니다.', array('status' => 404));
            }
            // A committed response may retry after the scheduled period ended.
            // It remains the original result; it never produces a new grade.
            if ($row['status'] !== 'pending' && $row['response_uid'] === $uid) { return self::commit(self::response_message($row['status'])); }
            if ($row['status'] !== 'pending') {
                if (in_array($row['status'], array('unanswered', 'invalidated'), true)) { return self::commit(self::response_message($row['status'])); }
                Check_Repository::finish(false);
                return new WP_Error('focus_tracker_check_answered', '이미 처리된 문항입니다.', array('status' => 409));
            }
            $now = Clock::now_ms();
            $gate = self::gate_for_row($user_id, $row);
            if (! self::same_window($row, $gate)) {
                $status = $row['status'] === 'pending' ? self::retirement_status($row, $now) : $row['status'];
                if ($row['status'] === 'pending' && ! Check_Repository::respond($id, $user_id, $status, null, $uid, $now)) { return self::rollback(); }
                return self::commit(self::response_message($status));
            }
            // Recheck access before revealing even an already-answered result.
            $context = self::context_for_row($user_id, $row);
            if (null === $context || ! self::valid_snapshot($row, $context)) {
                if ($row['status'] === 'pending' && ! Check_Repository::respond($id, $user_id, 'invalidated', null, $uid, Clock::now_ms())) { return self::rollback(); }
                return self::commit(self::response_message('invalidated'));
            }
            $now = Clock::now_ms();
            if (! self::same_window($row, self::gate_for_row($user_id, $row))) {
                $status = self::retirement_status($row, $now);
                if (! Check_Repository::respond($id, $user_id, $status, null, $uid, $now)) { return self::rollback(); }
                return self::commit(self::response_message($status));
            }
            if ($row['expires_at_ms'] <= $now) {
                $status = 'unanswered';
                $answer = null;
            } elseif (null !== $action) {
                $status = $action === 'unknown' ? 'unknown' : 'skipped';
            } else {
                $ids = array_column($row['candidate']['choices'], 'id');
                if (! in_array($answer, $ids, true)) { Check_Repository::finish(false); return self::invalid('선택지가 올바르지 않습니다.'); }
                $status = hash_equals((string) $row['candidate']['correctAnswer'], $answer) ? 'correct' : 'incorrect';
            }
            if (! Check_Repository::respond($id, $user_id, $status, $answer, $uid, $now)) { return self::rollback(); }
            return self::commit(self::response_message($status));
        } catch (Throwable $error) { return self::rollback(); }
    }

    public static function summary(int $user_id): array {
        $now = Clock::now_ms();
        $rows = Check_Repository::recent($user_id, $now - DAY_IN_SECONDS * 1000);
        if (! self::read_ok()) { return array('available' => false, 'affectsFocusScore' => false); }
        $counts = array_fill_keys(array('pending', 'correct', 'incorrect', 'unknown', 'skipped', 'unanswered', 'invalidated'), 0);
        foreach ($rows as $row) {
            $status = $row['status'] === 'pending' && $row['expires_at_ms'] <= $now ? self::retirement_status($row, $now) : $row['status'];
            if (isset($counts[$status])) { $counts[$status]++; }
        }
        return array('available' => true, 'period' => 'last_24_hours', 'counts' => $counts, 'affectsFocusScore' => false);
    }

    /**
     * Keep pending deadlines in step with an authorised schedule edit, even
     * when the student has no open page. The caller owns the transaction.
     * Only original bound activities may carry the question forward; a later
     * separated window can never revive it.
     */
    public static function reconcile_activity_windows(int $activity_id): bool {
        try {
            $rows = Check_Repository::pending_for_activity($activity_id);
            if (! self::read_ok()) { return false; }
            $now = Clock::now_ms();
            $activities = array();
            foreach ($rows as $row) {
                $ids = json_decode((string) ($row['activity_ids_json'] ?? ''), true);
                if (! is_array($ids)) { return false; }
                $windows = array();
                foreach (self::activity_ids($ids) as $id) {
                    if (! array_key_exists($id, $activities)) { $activities[$id] = Activity_Repository::find($id); if (! self::read_ok()) { return false; } }
                    $activity = $activities[$id];
                    if ($activity === null || $activity['status'] === Activity_Repository::STATUS_DELETED || ($activity['problem_ids'] !== null && ! in_array($row['problem_id'], $activity['problem_ids'], true))) { continue; }
                    $start = Clock::mysql_to_ms($activity['starts_at']);
                    $end = Clock::mysql_to_ms($activity['ends_at']);
                    if ($activity['status'] === Activity_Repository::STATUS_CLOSED) { $end = min($end, Clock::mysql_to_ms($activity['updated_at'])); }
                    if ($end > $start) { $windows[] = array('activityId' => $id, 'startMs' => $start, 'endMs' => $end); }
                }
                $run = null;
                foreach (Activity_Window_Gate::union($windows) as $candidate) {
                    if ($candidate['startMs'] <= $row['issued_at_ms'] && $row['issued_at_ms'] < $candidate['endMs']) { $run = $candidate; break; }
                }
                if ($run !== null && $run['startMs'] >= $row['window_start_ms'] && $run['startMs'] <= $now && $now < $run['endMs']) {
                    if ($row['expires_at_ms'] <= $now) {
                        if (! Check_Repository::respond($row['id'], $row['user_id'], self::retirement_status($row, $now), null, null, $now)) { return false; }
                    } elseif (! Check_Repository::bind_window($row, self::activity_ids($run['activityIds']), $run['startMs'], min($run['endMs'], $row['window_end_ms']))) { return false; }
                    continue;
                }
                $status = $run !== null && $run['endMs'] <= $row['expires_at_ms'] ? 'invalidated' : self::retirement_status($row, $now);
                if (! Check_Repository::respond($row['id'], $row['user_id'], $status, null, null, $now)) { return false; }
            }
            return true;
        } catch (Throwable $error) { return false; }
    }

    private static function draft($draft): array|WP_Error|null {
        if (null === $draft) { return null; }
        if (! is_array($draft) || ! is_string($draft['source'] ?? null) || strlen($draft['source']) > 65536 || preg_match('//u', $draft['source']) !== 1) {
            return self::invalid('초안은 UTF-8 텍스트 64KB 이하만 사용할 수 있습니다.');
        }
        if (trim($draft['source']) === '') { return null; }
        $id = filter_var($draft['language_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
        if (false === $id) { return self::invalid('초안 언어가 올바르지 않습니다.'); }
        $language = match ($id) { 0, 13 => 'c', 1, 14 => 'cpp', 3 => 'java', 6 => 'python', default => 'unknown' };
        return array('source' => $draft['source'], 'language' => $language);
    }

    private static function context(int $user_id, int $problem_id, ?int $submission_id, string $type, ?int $context_id): ?array {
        $gateway = Runtime_Bridge::learning_checks();
        if (null === $gateway) { return null; }
        try {
            $result = $gateway->context(new AddonLearningCheckContextQueryV1($user_id, $problem_id, $submission_id, $type, $context_id));
            if (! $result->isSuccessful()) { return null; }
            $payload = $result->payload();
            return ($payload['problem']['id'] ?? 0) === $problem_id ? $payload : null;
        } catch (Throwable $error) { return null; }
    }

    private static function context_for_row(int $user_id, array $row): ?array {
        return self::context($user_id, $row['problem_id'], $row['submission_id'], $row['context_type'], null === $row['context_id'] ? null : (int) $row['context_id']);
    }

    private static function gate_for_row(int $user_id, array $row): array|WP_Error {
        return Activity_Window_Gate::state($user_id, $row['problem_id'], $row['context_type'], null === $row['context_id'] ? null : (int) $row['context_id'], null, true);
    }

    private static function activity_ids(array $ids): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private static function same_window(array $row, array|WP_Error $gate): bool {
        if ($gate instanceof WP_Error || empty($gate['active']) || null === ($row['window_start_ms'] ?? null) || null === ($row['window_end_ms'] ?? null)) { return false; }
        $ids = json_decode((string) ($row['activity_ids_json'] ?? ''), true);
        return is_array($ids) && ! empty($ids)
            && self::activity_ids($ids) === self::activity_ids($gate['activityIds'] ?? array())
            && (int) $row['window_start_ms'] === (int) $gate['windowStartsAtMs']
            && (int) $row['window_end_ms'] === (int) $gate['windowEndsAtMs']
            && $row['issued_at_ms'] >= $row['window_start_ms'] && $row['issued_at_ms'] < $row['window_end_ms'];
    }

    private static function retirement_status(array $row, int $now): string {
        return $row['expires_at_ms'] <= $now && $row['window_end_ms'] !== null && $row['expires_at_ms'] < $row['window_end_ms'] ? 'unanswered' : 'invalidated';
    }

    private static function inactive(int $user_id, int $problem_id, array $gate): array|WP_Error {
        $existing = Check_Repository::pending_snapshot($user_id);
        if (! self::read_ok()) { return self::store_failed(); }
        if ($existing === null) { return self::inactive_result($gate); }
        if (null === Check_Repository::begin_for_user($user_id)) { return self::store_failed(); }
        try {
            $row = Check_Repository::pending_snapshot($user_id);
            if (! self::read_ok()) { return self::rollback(); }
            if ($row !== null && $row['problem_id'] === $problem_id) {
                $own_gate = self::gate_for_row($user_id, $row);
                if (! self::same_window($row, $own_gate) && ! Check_Repository::respond($row['id'], $user_id, self::retirement_status($row, Clock::now_ms()), null, null, Clock::now_ms())) { return self::rollback(); }
            }
            return self::commit(self::inactive_result($gate));
        } catch (Throwable $error) { return self::rollback(); }
    }

    private static function inactive_result(array $gate): array {
        return array('status' => 'inactive', 'reason' => 'inactive', 'retryAfterMs' => 30000, 'affectsFocusScore' => false);
    }

    private static function valid_snapshot(array $row, array $context): bool {
        if (! hash_equals($row['problem_hash'], self::problem_hash($context))) { return false; }
        $generator = new Verified_Question_Generator();
        return $row['source_kind'] === 'draft' ? $generator->verify($row['candidate']) : $generator->verify($row['candidate'], $context, null);
    }

    private static function problem_hash(array $context): string {
        return hash('sha256', (string) wp_json_encode($context['problem'], JSON_UNESCAPED_UNICODE));
    }

    private static function choose(array $candidates, array $recent, int $problem_id): ?array {
        $seen = array();
        $source_counts = array();
        foreach ($recent as $row) {
            if ($row['problem_id'] !== $problem_id) { continue; }
            $seen[hash('sha256', (string) wp_json_encode($row['candidate']))] = true;
            $source_counts[$row['source_kind']] = ($source_counts[$row['source_kind']] ?? 0) + 1;
        }
        $priority = array('draft' => 0, 'submission' => 1, 'problem' => 2);
        usort($candidates, static function (array $a, array $b) use ($source_counts, $priority): int {
            return (($source_counts[$a['sourceKind']] ?? 0) <=> ($source_counts[$b['sourceKind']] ?? 0))
                ?: (($priority[$a['sourceKind']] ?? 3) <=> ($priority[$b['sourceKind']] ?? 3));
        });
        foreach ($candidates as $candidate) {
            if (! isset($seen[hash('sha256', (string) wp_json_encode($candidate))])) { return $candidate; }
        }
        return null;
    }

    private static function present(array $row): array {
        $candidate = $row['candidate'];
        $labels = array('draft' => '작성 중인 초안의 일부', 'submission' => '본인의 제출 코드 일부', 'problem' => '문제에 공개된 예제');
        // Explicit allowlist: proof, expected answer, full source and context never leave here.
        return array(
            'id' => $row['id'], 'problemId' => $row['problem_id'], 'prompt' => $candidate['prompt'],
            'codeSnippet' => $candidate['codeSnippet'] ?? '', 'input' => $candidate['input'] ?? '',
            'assignments' => $candidate['assignments'] ?? '', 'choices' => $candidate['choices'],
            'sourceKind' => $row['source_kind'], 'sourceLabel' => $labels[$row['source_kind']] ?? '',
            'expiresAtMs' => $row['expires_at_ms'], 'affectsFocusScore' => false,
        );
    }

    private static function response_message(string $status): array {
        $messages = array(
            'correct' => '이 문항에 응답하고 정답을 확인한 기록을 남겼습니다. 집중 점수와는 별개입니다.',
            'incorrect' => '이 문항의 이해는 아직 확인되지 않았습니다. 미집중으로 판정하지 않습니다.',
            'unknown' => '모르겠음으로 기록했습니다. 미집중으로 판정하지 않습니다.',
            'skipped' => '문항을 건너뛰었습니다. 미집중으로 판정하지 않습니다.',
            'unanswered' => '응답 여부를 확인하지 못했습니다. 미집중으로 판정하지 않습니다.',
            'invalidated' => '활동 시간이 끝났거나 자료·권한이 변경되어 문항을 취소했습니다. 미집중으로 판정하지 않습니다.',
        );
        return array('status' => $status, 'message' => $messages[$status] ?? '확인 기록을 처리했습니다.', 'affectsFocusScore' => false);
    }

    private static function read_ok(): bool { global $wpdb; return '' === (string) $wpdb->last_error; }
    private static function diagnose(array|WP_Error $result): void {
        if ($result instanceof WP_Error) {
            $status = (int) ($result->get_error_data()['status'] ?? 500);
            Diagnostics::record('check', $status >= 500 ? 'store' : ($status === 403 ? 'access' : 'invalid'));
            return;
        }
        $reason = $result['reason'] ?? (in_array($result['status'] ?? '', array('inactive', 'cooldown'), true) ? $result['status'] : 'success');
        Diagnostics::record('check', $reason);
    }
    private static function commit(array $result): array|WP_Error { return Check_Repository::finish(true) ? $result : self::store_failed(); }
    private static function rollback(): WP_Error { Check_Repository::finish(false); return self::store_failed(); }
    private static function invalid(string $message): WP_Error { return new WP_Error('focus_tracker_invalid_check', $message, array('status' => 400)); }
    private static function store_failed(): WP_Error { return \FocusTracker\Privacy\Privacy_Guard::error() ?? new WP_Error('focus_tracker_check_store_failed', '학습 확인 기록을 저장하지 못했습니다. 다시 시도해 주세요.', array('status' => 503)); }
}
