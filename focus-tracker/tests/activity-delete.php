<?php
declare(strict_types=1);

require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';

use FocusTracker\Checks\Check_Service;
use FocusTracker\Database\Activity_Repository as Activities;
use FocusTracker\Database\Check_Repository as Checks;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Database\Score_Repository;
use FocusTracker\Rest\Controllers\Activities_Controller as Controller;
use FocusTracker\Rest\Controllers\Events_Controller;
use FocusTracker\Rest\Controllers\Me_Controller;
use FocusTracker\Rest\Controllers\Scores_Controller;
use FocusTracker\Scoring\Activity_Score_Service;
use FocusTracker\Support\Audit_Log;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;
use FocusTracker\Tracking\Activity_Window_Gate as Gate;

$checks = 0;
function deletion_expect(mixed $expected, mixed $actual, string $message): void {
    global $checks, $wpdb;
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ' SQL: ' . $wpdb->last_error); }
}
function deletion_assert(bool $actual, string $message): void { deletion_expect(true, $actual, $message); }
function deletion_error(mixed $actual, int $status, string $message): void { deletion_expect($status, $actual instanceof WP_Error ? ($actual->data['status'] ?? 0) : get_debug_type($actual), $message); }
function deletion_reset(): void {
    global $wpdb;
    foreach (array(Installer::events_table(), Installer::activity_links_table(), Installer::participations_table(), Installer::activities_table(), Installer::checks_table(), Installer::check_states_table(), Installer::scores_table(), Installer::statistics_table()) as $table) { $wpdb->query('DELETE FROM ' . $table); }
    $GLOBALS['focus_test_user_id'] = 1;
    $GLOBALS['focus_test_allowed'] = true;
    $GLOBALS['focus_test_statistics_deny_context'] = false;
    update_option('focus_tracker_audit_log', array());
}
function deletion_activity(int $start, int $end, int $owner = 1, string $context = 'class:1'): int {
    return Activities::create(array('name' => '삭제 확인 활동', 'owner_user_id' => $owner, 'context_type' => 'class', 'context_id' => $context, 'problem_ids' => array(123), 'starts_at' => Clock::ms_to_mysql($start), 'ends_at' => Clock::ms_to_mysql($end), 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
}
function deletion_call(int $id, string $nonce = 'focus-test-nonce'): WP_REST_Response|WP_Error {
    return Controller::delete(new WP_REST_Request(array(), array('id' => $id, '_wpnonce' => $nonce)));
}
function deletion_question(int $activity, int $user = 2): array {
    $GLOBALS['focus_test_user_id'] = $user;
    $result = Check_Service::next($user, array('problem_id' => 123, 'draft' => array('source' => 'if value' . $activity . " < 7:\n    pass", 'language_id' => 6)));
    deletion_assert(is_array($result) && $result['status'] === 'ready', 'A real verified question is issued inside the activity window');
    return $result['check'];
}
function deletion_counts(): array {
    global $wpdb;
    $out = array();
    foreach (array('raw' => Installer::events_table(), 'sessions' => Installer::participations_table(), 'links' => Installer::activity_links_table(), 'checks' => Installer::checks_table()) as $name => $table) { $out[$name] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table); }
    return $out;
}

platform_fixture_connect_runtime(1, array(1, 2, 3));
platform_fixture_apply_check_config(array('scenario' => 'problem', 'userId' => 1));
Settings::update(array('checks_enabled' => true, 'check_daily_limit' => 20, 'check_cooldown_seconds' => 60));
$now = intdiv(Clock::now_ms(), 1000) * 1000;

// Feature permission, nonce, exact owner, current context MANAGE and retry ACL.
deletion_reset();
$id = deletion_activity($now - 30000, $now + 300000);
$original = Activities::find($id);
deletion_error(deletion_call($id, 'wrong'), 403, 'Delete explicitly verifies its REST nonce');
$GLOBALS['focus_test_allowed'] = false;
deletion_error(deletion_call($id), 403, 'An owner without the feature capability cannot delete');
$GLOBALS['focus_test_allowed'] = true;
$GLOBALS['focus_test_user_id'] = 9;
deletion_error(deletion_call($id), 404, 'Another context manager cannot delete somebody else’s activity');
$GLOBALS['focus_test_user_id'] = 1;
$GLOBALS['focus_test_statistics_deny_context'] = true;
deletion_error(deletion_call($id), 403, 'An owner whose context permission was revoked cannot delete');
$GLOBALS['focus_test_statistics_deny_context'] = false;
deletion_expect($original, Activities::find($id), 'All denied requests preserve the complete activity');
deletion_error(deletion_call(999999), 404, 'A missing activity remains missing');

// Ordinary eligible page signals automatically create participation, without a join action.
$GLOBALS['focus_test_user_id'] = 2;
deletion_expect(true, Gate::state(2, 123)['active'], 'Current roster membership and a matching schedule authorize an ordinary problem page');
deletion_expect(0, deletion_counts()['sessions'], 'Authorization alone does not invent student observations');
$packet = array('user_id' => 2, 'problem_id' => 123, 'session_uid' => 'delete-open-student-tab', 'session_started_at' => $now - 1000, 'events' => array(array('seq' => 1, 'type' => 'heartbeat', 'occurred_at' => $now - 1, 'meta' => array('visible' => true, 'focused' => true))));
$ingested = Events_Controller::handle(new WP_REST_Request($packet));
deletion_assert($ingested instanceof WP_REST_Response && $ingested->data['stored'] > 0, 'First normal page observation enrolls automatically');
deletion_expect(array($id), Participation_Repository::activity_ids($ingested->data['participation_id']), 'Automatic participation is linked to its eligible activity');
$pending = deletion_question($id);
$pending_before = Checks::find($pending['id'], 2);
Activity_Score_Service::score_for_learner(Activities::find($id), 2);
deletion_assert(Score_Repository::get($id, 2) !== null, 'Mutable activity cache exists before deletion');
$committed = deletion_question($id, 3);
$committed_row = Checks::find($committed['id'], 3);
$response_input = array('response_uid' => 'before-delete-committed', 'answer_id' => $committed_row['candidate']['correctAnswer']);
deletion_expect('correct', Check_Service::respond(3, $committed['id'], $response_input)['status'], 'A completed response exists before deletion');
$before = deletion_counts();
$GLOBALS['focus_test_user_id'] = 1;
$deleted = deletion_call($id);
deletion_assert($deleted instanceof WP_REST_Response, 'Authorized delete succeeds');
deletion_expect(array('id' => $id, 'deleted' => true), $deleted->data, 'Delete has an explicit acknowledgement contract');
$tombstone = Activities::find($id);
deletion_expect(Activities::STATUS_DELETED, $tombstone['status'], 'The schedule becomes an irreversible tombstone');
deletion_assert($tombstone['ends_at'] <= current_time('mysql', true) && $tombstone['ends_at'] >= $tombstone['starts_at'], 'An active observation window is capped at deletion');
deletion_expect($before, deletion_counts(), 'Deletion preserves all raw observations, links, sessions and question rows');
deletion_expect(null, Score_Repository::get($id, 2), 'Mutable score cache is invalidated after its end changes');
deletion_expect('invalidated', Checks::find($pending['id'], 2)['status'], 'Passive pending check is cancelled immediately without a student poll');
deletion_expect(null, Checks::find($pending['id'], 2)['answer_id'], 'Deletion never stores a new student answer');
deletion_expect($pending_before['candidate_json'], Checks::find($pending['id'], 2)['candidate_json'], 'Question verification evidence is preserved');
deletion_expect('correct', Checks::find($committed['id'], 3)['status'], 'Committed answer is not rewritten as cancellation');
deletion_expect(array(), Controller::index(new WP_REST_Request())->data, 'Deleted schedule disappears from all normal activity lists');
deletion_expect(array(), Activities::find_overlapping_for_context('class', 'class:1', Clock::ms_to_mysql($now - 60000), Clock::ms_to_mysql($now + 600000)), 'Policy queries exclude deleted activities');
deletion_expect(1, count(Activities::find_overlapping_for_context('class', 'class:1', Clock::ms_to_mysql($now - 60000), Clock::ms_to_mysql($now + 600000), false, true)), 'Historical statistics may explicitly retain the tombstone window');
deletion_error(Controller::update(new WP_REST_Request(array('status' => 'active'), array('id' => $id))), 404, 'A deleted activity cannot be reactivated or edited');
deletion_error(Scores_Controller::activity_scores(new WP_REST_Request(array(), array('id' => $id))), 404, 'Deleted activity scores cannot be reopened');
deletion_error(Scores_Controller::timeline(new WP_REST_Request(array(), array('id' => $id, 'user_id' => 2))), 404, 'Teacher timeline is hidden too');
deletion_expect(1, count(Audit_Log::recent()), 'Deletion adds one administrative audit entry');
deletion_expect('activity_deleted', Audit_Log::recent()[0]['operation'], 'Audit names the deletion operation');
deletion_expect($deleted->data, deletion_call($id)->data, 'An authorized repeated delete is idempotent');
deletion_expect($tombstone, Activities::find($id), 'Repeated deletion preserves the original timestamp and end');
deletion_expect(1, count(Audit_Log::recent()), 'Idempotent retry adds no duplicate audit event');
$GLOBALS['focus_test_statistics_deny_context'] = true;
deletion_error(deletion_call($id), 403, 'Idempotent retries still require current context permission');
$GLOBALS['focus_test_statistics_deny_context'] = false;
$GLOBALS['focus_test_user_id'] = 2;
deletion_expect(false, Gate::state(2, 123)['active'], 'Deleting the only activity stops tracking authorization');
deletion_expect(false, Me_Controller::summary(new WP_REST_Request())->data['hasActivity'], 'Student recent-activity view hides deleted schedules');
deletion_error(Scores_Controller::timeline(new WP_REST_Request(array(), array('id' => $id, 'user_id' => 2))), 404, 'Own per-activity timeline is hidden while longitudinal history is retained');
deletion_expect('inactive', Check_Service::next(2, array('problem_id' => 123, 'draft' => array('source' => array('must not read'), 'language_id' => -1)))['status'], 'Deleted-only policy does not analyze another draft');
deletion_expect('invalidated', Check_Service::respond(2, $pending['id'], array('response_uid' => 'after-delete-pending', 'action' => 'unknown'))['status'], 'Answering a cancelled question remains penalty free');
$later = Events_Controller::handle(new WP_REST_Request($packet));
deletion_expect(0, $later->data['stored'], 'Delayed observations are not restored through a deleted authorization');
deletion_expect($before, deletion_counts(), 'Stopped requests do not remove or duplicate historical evidence');
$GLOBALS['focus_test_user_id'] = 3;
deletion_expect('correct', Check_Service::respond(3, $committed['id'], $response_input)['status'], 'Committed response retry remains stable after deletion');

// A still-valid original overlap preserves the pending question; it is not a new activity.
deletion_reset();
$first = deletion_activity($now - 60000, $now + 300000);
$other = deletion_activity($now - 30000, $now + 180000);
$covered = deletion_question($first);
$covered_before = Checks::find($covered['id'], 2);
$GLOBALS['focus_test_user_id'] = 1;
deletion_assert(deletion_call($first) instanceof WP_REST_Response, 'One overlapping activity can be deleted independently');
$surviving = Checks::find($covered['id'], 2);
deletion_expect('pending', $surviving['status'], 'A remaining overlap containing issuance and now preserves the pending check');
deletion_expect(array($other), json_decode($surviving['activity_ids_json'], true), 'The pending binding drops only the deleted activity');
deletion_expect(Clock::mysql_to_ms(Activities::find($other)['starts_at']), $surviving['window_start_ms'], 'A shorter original overlap safely narrows the bound start');
deletion_assert($surviving['expires_at_ms'] <= $covered_before['expires_at_ms'], 'Deletion never extends question lifetime');
$GLOBALS['focus_test_user_id'] = 2;
deletion_expect(array($other), Gate::state(2, 123)['activityIds'], 'Other eligible activity continues tracking');
deletion_expect('correct', Check_Service::respond(2, $covered['id'], array('response_uid' => 'surviving-overlap-answer', 'answer_id' => $surviving['candidate']['correctAnswer']))['status'], 'The surviving verified question is still answerable');

deletion_reset();
$current = deletion_activity($now - 30000, $now + 30000);
deletion_activity($now + 30000, $now + 180000);
$adjacent = deletion_question($current);
$GLOBALS['focus_test_user_id'] = 1;
deletion_call($current);
deletion_expect('invalidated', Checks::find($adjacent['id'], 2)['status'], 'A future adjacent authorization cannot carry a deleted current question across the new gap');
$GLOBALS['focus_test_user_id'] = 2;
deletion_expect(false, Gate::state(2, 123)['active'], 'Future adjacent activity does not start early');

// Pending retirement, cache invalidation, and commit failures roll back together.
foreach (array('UPDATE ' . Installer::activities_table(), 'UPDATE ' . Installer::checks_table(), 'DELETE FROM ' . Installer::scores_table(), 'COMMIT') as $failure) {
    deletion_reset();
    $failed_id = deletion_activity($now - 30000, $now + 300000);
    $q = deletion_question($failed_id);
    $old_activity = Activities::find($failed_id);
    $old_question = Checks::find($q['id'], 2);
    $GLOBALS['focus_test_user_id'] = 1;
    $wpdb->fail_pattern = $failure;
    deletion_error(deletion_call($failed_id), 503, 'A failed stage is never acknowledged as deletion: ' . $failure);
    deletion_expect($old_activity, Activities::find($failed_id), 'Failed deletion rolls back its activity tombstone');
    deletion_expect($old_question, Checks::find($q['id'], 2), 'Failed deletion rolls back question retirement and binding');
    deletion_expect(array(), Audit_Log::recent(), 'Rolled-back deletion is not audited as successful');
    deletion_assert(deletion_call($failed_id) instanceof WP_REST_Response, 'Retry completes after the transient failure is removed');
}

deletion_reset();
$future = deletion_activity($now + 60000, $now + 180000);
deletion_assert(deletion_call($future) instanceof WP_REST_Response, 'An unstarted activity can be deleted');
deletion_expect(Activities::find($future)['starts_at'], Activities::find($future)['ends_at'], 'Unstarted tombstone has an empty historical window');
deletion_expect(array(), Activities::find_active_for_context('class', 'class:1', Activities::find($future)['starts_at']), 'Legacy active query cannot authorize the empty deleted boundary');
$past = deletion_activity($now - 180000, $now - 120000);
$archived_metrics = array_fill_keys(array('total_active_seconds', 'total_hidden_seconds', 'focus_ratio', 'blur_count', 'avg_recovery_seconds', 'activity_window_ratio', 'score_focus', 'score_selfreg', 'score_activity', 'score_total'), 0);
$archived_metrics['raw_events_expired'] = 1;
$archived_metrics['finalized'] = true;
Score_Repository::upsert($past, 2, $archived_metrics);
$archived_before = Score_Repository::get($past, 2);
deletion_assert(deletion_call($past) instanceof WP_REST_Response, 'Archived activities may be hidden without erasing their history');
deletion_expect($archived_before, Score_Repository::get($past, 2), 'Archived grade snapshot survives activity deletion unchanged');

echo "activity-delete: {$checks} SQL-backed checks passed\n";
