<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use FocusTracker\Addon\Runtime_Bridge;
use FocusTracker\Checks\Check_Service;
use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Check_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;

$checks = 0;
function gate_expect(mixed $expected, mixed $actual, string $label): void {
    global $checks, $wpdb;
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ' SQL: ' . $wpdb->last_error); }
}
function gate_assert(bool $actual, string $label): void { gate_expect(true, $actual, $label); }
function gate_user(int $id): void { $GLOBALS['focus_test_user_id'] = $id; }
function gate_activity(int $start, int $end, array $problems = array(123), string $context = 'class:1'): int {
    return Activity_Repository::create(array('name' => '자동 확인 허용 활동', 'owner_user_id' => 1, 'context_type' => 'class', 'context_id' => $context,
        'problem_ids' => $problems, 'starts_at' => Clock::ms_to_mysql($start), 'ends_at' => Clock::ms_to_mysql($end), 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
}
function gate_clear(): void {
    global $wpdb;
    $wpdb->query('DELETE FROM ' . Installer::activities_table());
}
function gate_next(array $extra = array()): array|WP_Error { return Check_Service::next(get_current_user_id(), array_merge(array('problem_id' => 123), $extra)); }
function gate_ready(array $extra = array()): array {
    $result = gate_next($extra);
    gate_assert(is_array($result) && ($result['status'] ?? null) === 'ready', 'Verified question ready inside authorised window');
    return $result['check'];
}
function gate_row(array $check): array { return Check_Repository::find($check['id'], get_current_user_id()); }
function gate_answer(array $check, string $uid): array|WP_Error {
    return Check_Service::respond(get_current_user_id(), $check['id'], array('answer_id' => gate_row($check)['candidate']['correctAnswer'], 'response_uid' => $uid));
}

$gateway = new class implements Api\AddonLearningCheckGatewayV1 {
    public int $reads = 0;
    public function context(Api\AddonLearningCheckContextQueryV1 $query): Api\AddonLearningCheckContextResultV1 {
        ++$this->reads;
        $problem = array('id' => 123, 'title' => '허용 시간의 문제', 'description' => '두 수를 더하세요.', 'input' => '두 정수', 'output' => '합', 'sample_input' => '3 4', 'sample_output' => '7', 'content_hash' => str_repeat('a', 64));
        return new Api\AddonLearningCheckContextResultV1(true, array('problem' => $problem, 'submission' => null), null);
    }
};
$runtime = new class($gateway) implements Api\AddonLearningCheckRuntimeContextV1 {
    public function __construct(private Api\AddonLearningCheckGatewayV1 $gateway) {}
    public function snapshot(): Api\AddonRuntimeSnapshotV1 { return new Api\AddonRuntimeSnapshotV1('focus-tracker', FOCUS_TRACKER_VERSION, '>=2.3.0 <3.0.0', '2.3.22', '^1.4', '1.4.0', 'accepted', '', 'check-gate-catalog', array(), 'check-gate-runtime'); }
    public function contexts(): Api\AddonContextGatewayV1 { return new Platform_Fixture_Context_Gateway(); }
    public function aiTasks(): Api\AddonAiTaskGatewayV1 { throw new LogicException('No AI involved.'); }
    public function learningChecks(): Api\AddonLearningCheckGatewayV1 { return $this->gateway; }
};
Runtime_Bridge::connect($runtime);
$GLOBALS['focus_test_statistics_contexts'] = array(array('type' => 'class', 'id' => 'class:1', 'label' => '수업 1'));
Settings::update(array('checks_enabled' => true, 'check_daily_limit' => 20, 'check_cooldown_seconds' => 60));
gate_user(1);
$invalid_draft = array('draft' => array('source' => array('must not be parsed'), 'language_id' => 'invalid'));
gate_expect('inactive', gate_next($invalid_draft)['status'], 'No activity is inactive even for invalid draft payload');
gate_expect(0, $gateway->reads, 'No activity does not read problem/submission gateway');
gate_expect(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::checks_table()), 'No activity creates no question row');
gate_expect(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::check_states_table()), 'Inactive polling does not create a quota ledger');
$now = Clock::now_ms();
gate_activity($now + 60000, $now + 180000);
gate_expect('inactive', gate_next($invalid_draft)['status'], 'Future activity does not authorise early editor analysis');
gate_expect(0, $gateway->reads, 'Future schedule reads no learning source');
gate_clear();
gate_activity($now - 60000, $now + 60000, array(124));
gate_expect('inactive', gate_next($invalid_draft)['status'], 'Wrong problem does not authorise editor analysis');
gate_clear();
gate_activity($now - 60000, $now + 60000, array(123), 'class:2');
gate_expect('inactive', gate_next($invalid_draft)['status'], 'Other class activity cannot authorise a general-context draft');
gate_expect(0, $gateway->reads, 'Foreign context has no source reads');

// A gap is a hard boundary; an overlapping/adjacent run is continuous.
gate_clear();
gate_user(2);
$window_start = Clock::now_ms() - 30000;
$window_end = $window_start + 150000;
$first = gate_activity($window_start, $window_end);
$after_gap = gate_activity($window_end + 60000, $window_end + 180000);
$draft = array('draft' => array('source' => "if value >= 7:\n    pass", 'language_id' => 6));
$question = gate_ready($draft);
$stored = gate_row($question);
gate_expect($window_start, $stored['window_start_ms'], 'Issuance binds to exact continuous window start');
gate_expect($window_end, $stored['window_end_ms'], 'Future separated window cannot extend question lifetime');
gate_expect(array($first), json_decode($stored['activity_ids_json'], true), 'Only current run activity IDs are bound');
gate_expect($window_end, $question['expiresAtMs'], 'Question deadline is capped at activity end');
gate_assert(! array_key_exists('activity_ids_json', $question), 'Private scheduling proof is not leaked');
$reads_before_stop = $gateway->reads;
Activity_Repository::update($first, array('ends_at' => Clock::ms_to_mysql(Clock::now_ms() - 1)));
$ended = gate_answer($question, 'window-ended-response');
gate_expect('invalidated', $ended['status'], 'A response after activity end is cancelled without grading');
gate_expect(null, gate_row($question)['answer_id'], 'An after-end answer is not stored');
gate_expect($reads_before_stop, $gateway->reads, 'Stopped response does not reopen private source context');
gate_expect(false, $ended['affectsFocusScore'], 'Schedule cancellation has no grade effect');
$new_window = gate_activity(Clock::now_ms() - 1, Clock::now_ms() + 120000);
$wpdb->update(Installer::check_states_table(), array('next_allowed_at_ms' => 0), array('user_id' => 2));
$replacement = gate_ready($draft);
gate_assert($replacement['id'] !== $question['id'], 'Later allowed run cannot revive a cancelled question');
gate_expect('invalidated', gate_row($question)['status'], 'Old question remains cancelled after later window starts');

gate_clear();
gate_user(3);
$active = gate_activity(Clock::now_ms() - 1000, Clock::now_ms() + 120000);
$committed = gate_ready();
$original = gate_answer($committed, 'committed-answer-uid');
gate_expect('correct', $original['status'], 'Answer during active window commits normally');
Activity_Repository::update($active, array('ends_at' => Clock::ms_to_mysql(Clock::now_ms() - 1)));
$reads_after_commit = $gateway->reads;
gate_expect($original, gate_answer($committed, 'committed-answer-uid'), 'Committed response retry remains idempotent after window ended');
gate_expect($reads_after_commit, $gateway->reads, 'Committed retry after stop reads no source');
gate_expect(409, gate_answer($committed, 'different-response-uid')->data['status'], 'A new response UID cannot create another grade after stop');

gate_clear();
gate_user(4);
gate_activity(Clock::now_ms() - 1000, Clock::now_ms() + 3600000);
$timed_out = gate_ready();
$wpdb->update(Installer::checks_table(), array('expires_at_ms' => Clock::now_ms() - 1), array('id' => $timed_out['id']));
gate_expect('unanswered', gate_answer($timed_out, 'own-timeout-response')['status'], 'Question timeout inside an ongoing long activity is unanswered');
gate_expect(null, gate_row($timed_out)['answer_id'], 'Late timeout answer is not stored');

gate_clear();
gate_user(5);
$start = Clock::now_ms() - 60000;
$one = gate_activity($start, $start + 120000);
$two = gate_activity($start + 120000, $start + 180000);
gate_activity($start + 180001, $start + 240000);
$continuous = gate_ready();
$continuous_row = gate_row($continuous);
gate_expect(array($one, $two), json_decode($continuous_row['activity_ids_json'], true), 'Exactly adjacent windows share one continuous run');
gate_expect($start + 180000, $continuous['expiresAtMs'], 'One-millisecond gap is not bridged');
gate_activity($start, $start + 180000);
$before_rebind = $gateway->reads;
gate_expect('invalidated', gate_answer($continuous, 'changed-run-response')['status'], 'Changed run membership cancels stale scheduling proof conservatively');
gate_expect($before_rebind, $gateway->reads, 'Changed scheduling proof is rejected before source lookup');

gate_clear();
gate_user(6);
gate_activity(Clock::now_ms() - 1000, Clock::now_ms() + 120000);
$revoked = gate_ready();
$before_revoke = $gateway->reads;
$GLOBALS['focus_test_statistics_contexts'] = array();
gate_expect('inactive', gate_next($invalid_draft)['status'], 'Membership loss stops next poll before draft parsing');
gate_expect('invalidated', gate_row($revoked)['status'], 'Inactive next poll retires old pending question promptly');
gate_expect($before_revoke, $gateway->reads, 'Membership loss reads no problem/source context');

// Teacher edits retire or shorten pending questions without relying on the
// student page to poll. Overlapping authorisations still form one run.
$GLOBALS['focus_test_statistics_contexts'] = array(array('type' => 'class', 'id' => 'class:1', 'label' => '수업 1'));
gate_clear();
gate_user(7);
$long = gate_activity(Clock::now_ms() - 30000, Clock::now_ms() + 3600000);
$passive = gate_ready();
gate_assert(gate_row($passive)['expires_at_ms'] < gate_row($passive)['window_end_ms'], 'Long activity initially has its own ten-minute question timeout');
$reads_before_teacher = $gateway->reads;
gate_user(1);
$closed = \FocusTracker\Rest\Controllers\Activities_Controller::update(new WP_REST_Request(array('status' => 'closed'), array('id' => $long)));
gate_assert($closed instanceof WP_REST_Response, 'Teacher can close an ongoing activity');
gate_user(7);
gate_expect('invalidated', gate_row($passive)['status'], 'Early close immediately cancels passive pending question before ten-minute timeout');
gate_expect(null, gate_row($passive)['answer_id'], 'Passive cancellation records no answer');
gate_expect($reads_before_teacher, $gateway->reads, 'Teacher schedule edit reads no learner source gateway');

gate_clear();
gate_user(8);
$long = gate_activity(Clock::now_ms() - 30000, Clock::now_ms() + 3600000);
$shortened = gate_ready();
$old_deadline = gate_row($shortened)['expires_at_ms'];
$short_end = intdiv(Clock::now_ms(), 1000) * 1000 + 60000;
gate_user(1);
$short = \FocusTracker\Rest\Controllers\Activities_Controller::update(new WP_REST_Request(array('ends_at' => gmdate('c', intdiv($short_end, 1000))), array('id' => $long)));
gate_assert($short instanceof WP_REST_Response, 'Teacher can shorten a still-active window');
gate_user(8);
$short_row = gate_row($shortened);
gate_expect('pending', $short_row['status'], 'Question remains usable while the shortened continuous run remains active');
gate_expect($short_end, $short_row['expires_at_ms'], 'Passive pending deadline is shortened to actual activity end');
gate_expect($short_end, $short_row['window_end_ms'], 'Shortened binding matches the authorised remaining run');
gate_assert($short_row['expires_at_ms'] < $old_deadline, 'Shortening cannot silently retain the original ten-minute deadline');
gate_expect($shortened['id'], gate_ready()['id'], 'Shortened active run can return same verified pending question');

gate_clear();
gate_user(9);
$shared_start = Clock::now_ms() - 30000;
$shared_end = $shared_start + 3630000;
$overlap_a = gate_activity($shared_start, $shared_end);
gate_activity($shared_start, $shared_end);
$covered = gate_ready();
$covered_deadline = gate_row($covered)['expires_at_ms'];
gate_user(1);
\FocusTracker\Rest\Controllers\Activities_Controller::update(new WP_REST_Request(array('status' => 'closed'), array('id' => $overlap_a)));
gate_user(9);
gate_expect('pending', gate_row($covered)['status'], 'Another fully overlapping authorised window preserves the continuous question run');
gate_expect($covered_deadline, gate_row($covered)['expires_at_ms'], 'Closing a redundant overlap does not extend or shorten covered question timeout');
gate_expect($covered['id'], gate_ready()['id'], 'Remaining overlap is still validated against current run');

gate_clear();
gate_user(10);
$adj_start = Clock::now_ms() - 30000;
$adj_a = gate_activity($adj_start, $adj_start + 90000);
gate_activity($adj_start + 90000, $adj_start + 180000);
$broken = gate_ready();
gate_user(1);
\FocusTracker\Rest\Controllers\Activities_Controller::update(new WP_REST_Request(array('status' => 'closed'), array('id' => $adj_a)));
gate_user(10);
gate_expect('invalidated', gate_row($broken)['status'], 'Early close breaks an adjacent future run and cannot carry the question across the gap');

gate_clear();
gate_user(11);
$atomic = gate_activity(Clock::now_ms() - 30000, Clock::now_ms() + 3600000);
$atomic_question = gate_ready();
$atomic_before = Activity_Repository::find($atomic);
gate_user(1);
$wpdb->fail_pattern = 'UPDATE ' . Installer::checks_table();
$failed_close = \FocusTracker\Rest\Controllers\Activities_Controller::update(new WP_REST_Request(array('status' => 'closed'), array('id' => $atomic)));
gate_assert($failed_close instanceof WP_Error, 'Schedule change does not acknowledge failed pending retirement');
gate_expect($atomic_before['ends_at'], Activity_Repository::find($atomic)['ends_at'], 'Failed retirement rolls back shortened activity end too');
gate_user(11);
gate_expect('pending', gate_row($atomic_question)['status'], 'Failed schedule edit leaves pending question and policy consistent');

echo 'check-gate: ' . $checks . " SQL-backed schedule checks passed\n";
