<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';

use CodingPanda\OjPlatform\AddonApi\V1 as Api;
use FocusTracker\Addon\Runtime_Bridge;
use FocusTracker\Checks\Check_Service;
use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Check_Repository;
use FocusTracker\Database\Event_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Database\Score_Repository;
use FocusTracker\Database\Statistics_Repository;
use FocusTracker\Privacy\Personal_Data;
use FocusTracker\Privacy\Privacy_Guard;
use FocusTracker\Jobs\Retention_Cleanup;
use FocusTracker\Rest\Controllers\Checks_Controller;
use FocusTracker\Rest\Controllers\Events_Controller;
use FocusTracker\Rest\Controllers\Scores_Controller;
use FocusTracker\Rest\Controllers\Tracking_Controller;
use FocusTracker\Rest\Controllers\Statistics_Controller;
use FocusTracker\Support\Audit_Log;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Diagnostics;
use FocusTracker\Support\Request_Limiter;
use FocusTracker\Support\Settings;

$checks = 0;
function security_expect(mixed $expected, mixed $actual, string $message): void {
    global $checks, $wpdb;
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', actual ' . var_export($actual, true) . ' SQL: ' . $wpdb->last_error); }
}
function security_assert(bool $actual, string $message): void { security_expect(true, $actual, $message); }
function security_error(mixed $actual, int $status, string $message): void { security_assert($actual instanceof WP_Error && ($actual->data['status'] ?? 0) === $status, $message); }
function get_user_by(string $field, string $value): object|false {
    if ($field !== 'email') { return false; }
    foreach (array(7 => 'learner@example.test', 8 => 'peer@example.test', 9 => 'deleted@example.test') as $id => $email) {
        if ($email === $value) { return (object) array('ID' => $id, 'user_email' => $email); }
    }
    return false;
}
function security_counts(int $user): array {
    global $wpdb;
    $counts = array();
    foreach (array('participations' => Installer::participations_table(), 'scores' => Installer::scores_table(), 'checks' => Installer::checks_table(), 'states' => Installer::check_states_table(), 'daily' => Installer::statistics_table()) as $key => $table) {
        $counts[$key] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user));
    }
    foreach (array('events' => Installer::events_table(), 'links' => Installer::activity_links_table()) as $key => $table) {
        $counts[$key] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} r JOIN " . Installer::participations_table() . ' p ON p.id = r.participation_id WHERE p.user_id = %d', $user));
    }
    return $counts;
}
function security_seed(int $user, int $activity, int $events): array {
    $now = Clock::now_ms() - 10000;
    $p = Participation_Repository::find_or_create(array('activity_id' => $activity, 'user_id' => $user, 'problem_id' => 123, 'session_uid' => 'privacy-account-' . $user, 'started_at_ms' => $now));
    Participation_Repository::link_activity($p['id'], $activity);
    $batch = array();
    for ($i = 1; $i <= $events; ++$i) { $batch[] = array('seq' => $i, 'type' => 'activity', 'occurred_at' => $now + $i, 'meta' => array('visible' => true)); }
    security_expect($events, Event_Repository::insert_batch($p['id'], $batch), 'Own raw events are seeded');
    $metrics = array_fill_keys(array('total_active_seconds', 'total_hidden_seconds', 'focus_ratio', 'blur_count', 'avg_recovery_seconds', 'activity_window_ratio', 'score_focus', 'score_selfreg', 'score_activity', 'score_total'), 0);
    security_assert(Score_Repository::upsert($activity, $user, $metrics), 'Own cached grade is seeded');
    security_assert(Statistics_Repository::save($user, array('type' => 'self', 'id' => ''), '2026-10-20', 'Asia/Seoul', array('activeSeconds' => 15), str_repeat('a', 64), true), 'Own archived daily aggregate is seeded');
    if ($user !== 7) {
        Check_Repository::create(array('user_id' => $user, 'problem_id' => 123, 'submission_id' => null, 'context_type' => 'general', 'context_id' => null, 'problem_hash' => str_repeat('a', 64), 'source_kind' => 'problem', 'source_hash' => str_repeat('b', 64), 'candidate_json' => json_encode(array('prompt' => 'PRIVATE_PROMPT_' . $user)), 'issued_at_ms' => $now, 'expires_at_ms' => $now + 600000, 'status' => 'correct'));
        security_assert(Check_Repository::begin_for_user($user) !== null, 'Other account question ledger is seeded');
        Check_Repository::finish(true);
    }
    return $p;
}

Personal_Data::register();
Request_Limiter::register();
security_assert(isset(Personal_Data::exporters(array())['focus-tracker']['callback']), 'WordPress exporter is discoverable');
security_assert(isset(Personal_Data::erasers(array())['focus-tracker']['callback']), 'WordPress eraser is discoverable');
security_assert(in_array(array(Personal_Data::class, 'delete_user'), $GLOBALS['focus_test_hooks']['delete_user'], true), 'Core account deletion is wired');
security_assert(in_array(array(Personal_Data::class, 'delete_user'), $GLOBALS['focus_test_hooks']['wpmu_delete_user'], true), 'Network account deletion is wired');

// Atomic budgets have separate accounts/channels and do not use issuance counts.
$minute = intdiv(Clock::now_ms(), 60000) * 60000 + 15000;
for ($i = 0; $i < 60; ++$i) { security_expect(null, Request_Limiter::check(41, 'check_next', $minute), 'Budget admits exactly the configured burst'); }
$limited = Request_Limiter::check(41, 'check_next', $minute);
security_error($limited, 429, 'Next request is explicitly rate limited');
security_expect(45, $limited->data['retryAfterSeconds'], 'Retry delay is the remaining fixed window');
security_expect(null, Request_Limiter::check(42, 'check_next', $minute), 'Another account has its own budget');
security_expect(null, Request_Limiter::check(41, 'gate', $minute), 'Another operation has its own budget');
security_expect(null, Request_Limiter::check(41, 'check_next', $minute + 60000), 'New minute replenishes atomically');
$response = new WP_REST_Response(array('code' => $limited->code, 'data' => $limited->data), 429);
Request_Limiter::retry_header($response);
security_expect('45', $response->get_headers()['Retry-After'] ?? '', 'Real REST response carries Retry-After');
for ($at = 0; $at < 60000; $at += 1000) {
    if ($at % 5000 === 0) { for ($tab = 0; $tab < 2; ++$tab) { security_expect(null, Request_Limiter::check(43, 'gate', $minute + $at), 'Two tabs can renew leases every five seconds'); } }
    if ($at % 7000 === 0) { for ($tab = 0; $tab < 2; ++$tab) { security_expect(null, Request_Limiter::check(43, 'ingest', $minute + $at), 'Two tabs can flush every seven seconds'); } }
}
$wpdb->fail_pattern = 'UPDATE wp_options';
security_error(Request_Limiter::check(44, 'gate'), 503, 'Budget storage failure fails closed');
security_expect(null, Request_Limiter::check(44, 'gate'), 'A failed budget write does not permanently block retries');
update_option('focus_tracker_limit_gate_45', '1', false);
update_option('focusXtrackerXlimitXunrelated', '1', false);
Request_Limiter::cleanup();
security_expect(false, get_option('focus_tracker_limit_gate_45', false), 'Expired limiter metadata is cleaned up');
security_expect('1', get_option('focusXtrackerXlimitXunrelated'), 'SQL wildcard escaping preserves unrelated options');

Diagnostics::reset();
$diagnostic_now = Clock::now_ms();
Diagnostics::record('ingest', 'success', $diagnostic_now);
Diagnostics::record('ingest', 'success', $diagnostic_now);
Diagnostics::record('check', 'no_candidate');
Diagnostics::record('gate', 'store');
Diagnostics::record('secret source = 123', 'success');
Diagnostics::record('ingest', 'learner@example.test');
$health = Diagnostics::snapshot();
security_expect(1, $health['operations']['ingest']['counts']['success'], 'Repeated requests in one minute produce one operational observation');
security_expect(60, $health['samplePeriodSeconds'], 'The diagnostic snapshot explicitly declares its sampling period');
security_expect(1, $health['operations']['check']['counts']['no_candidate'], 'Safe lack of a question is distinct from a failure');
security_assert($health['operations']['gate']['lastErrorAtMs'] !== null, 'Last operational failure is visible');
security_assert(! str_contains(json_encode($health), 'secret') && ! str_contains(json_encode($health), '@'), 'Diagnostics contain no identifiers or source');
$wpdb->options = 'missing_diagnostic_table';
security_expect(false, Diagnostics::snapshot()['available'], 'A failed diagnostic read does not fabricate healthy zero counts');
$wpdb->options = 'wp_options';

$GLOBALS['focus_test_user_id'] = 7;
$GLOBALS['focus_test_allowed'] = true;
platform_fixture_connect_runtime(1, array(7, 8));
platform_fixture_apply_check_config(array('scenario' => 'problem', 'userId' => 7, 'initialDelayMs' => 45000, 'pollMs' => 60000, 'cooldownSeconds' => 300, 'dailyLimit' => 3));
$now = Clock::now_ms();
$activity = Activity_Repository::create(array('name' => 'Shared schedule survives erasure', 'owner_user_id' => 1, 'context_type' => 'class', 'context_id' => 'class:1', 'problem_ids' => array(123), 'starts_at' => Clock::ms_to_mysql($now - 60000), 'ends_at' => Clock::ms_to_mysql($now + 600000), 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
$oversized = new WP_REST_Request(array('problem_id' => 123, 'extra' => str_repeat('X', 524289)), array('_wpnonce' => 'focus-test-nonce'));
security_error(Checks_Controller::authorize($oversized), 413, 'Checks reject an oversized raw request before source access');
security_error(Events_Controller::authorize($oversized), 413, 'Event requests have a byte limit as well as a count limit');
security_error(Tracking_Controller::authorize(new WP_REST_Request(array('extra' => str_repeat('X', 8193)), array('_wpnonce' => 'focus-test-nonce'))), 413, 'Gate requests cannot carry large unrelated payloads');
security_error(Checks_Controller::authorize(new WP_REST_Request(array(), array('_wpnonce' => 'wrong'))), 403, 'Request protection does not replace nonce checks');

// Exhausted requests do not even inspect an invalid draft or query the gateway.
Request_Limiter::erase_user(7);
for ($i = 0; $i < 60; ++$i) { Request_Limiter::check(7, 'check_next'); }
$before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::checks_table());
security_error(Check_Service::next(7, array('problem_id' => 123, 'draft' => array('source' => array('not analyzable'), 'language_id' => 'wrong'))), 429, 'Budget check precedes editor source validation');
security_expect($before, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::checks_table()), 'Limited requests create no question or grade');
Request_Limiter::erase_user(7);
Settings::update(array('checks_enabled' => false));
security_expect('disabled', Check_Service::next(7, array('problem_id' => 123))['reason'], 'Disabled configuration is explicit');
Settings::update(array('checks_enabled' => true));
platform_fixture_apply_check_config(array('scenario' => 'draft', 'userId' => 7));
security_expect('no_candidate', Check_Service::next(7, array('problem_id' => 123))['reason'], 'Unsupported code/absent published sample is a nonpunitive no-candidate result');
platform_fixture_apply_check_config(array('scenario' => 'unavailable', 'userId' => 7));
security_expect('access', Check_Service::next(7, array('problem_id' => 123))['reason'], 'Source access failures are distinct from no candidate');
platform_fixture_apply_check_config(array('scenario' => 'problem', 'userId' => 7));
$wpdb->update(Installer::check_states_table(), array('next_allowed_at_ms' => 0), array('user_id' => 7));
$draft = "# ENTIRE_DRAFT_SECRET_MUST_NOT_BE_STORED\nif answer < 7:\n    pass";
$question = Check_Service::next(7, array('problem_id' => 123, 'draft' => array('source' => $draft, 'language_id' => 6)));
security_expect('ready', $question['status'], 'A real verified draft question is issued for export');
security_assert(! isset($question['check']['correctAnswer']), 'Public question never includes the internal key');
$own = security_seed(7, $activity, 121);
$peer = security_seed(8, $activity, 3);
$peer_counts = security_counts(8);
$GLOBALS['focus_test_user_id'] = 7;
Audit_Log::record('settings', 0, array('checks_enabled'));
$GLOBALS['focus_test_user_id'] = 8;
Audit_Log::record('settings', 0, array('checks_enabled'));
$GLOBALS['focus_test_user_id'] = 7;

$all = array();
$done = false;
for ($page = 1; $page <= 10 && ! $done; ++$page) {
    $result = Personal_Data::export_data('learner@example.test', $page);
    security_assert(is_array($result) && count($result['data']) <= 100, 'Every export page is bounded to 100 records');
    $all = array_merge($all, $result['data']);
    $done = $result['done'];
}
security_assert($done && count($all) > 121, 'All event and derived-data sections span complete export pages');
security_expect(count($all), count(array_unique(array_column($all, 'item_id'))), 'Export pages do not duplicate stable item identifiers');
$exported = json_encode($all, JSON_UNESCAPED_UNICODE);
security_assert(str_contains($exported, 'codeSnippet'), 'Own persisted snippet is exported');
security_assert(! str_contains($exported, 'correctAnswer') && ! str_contains($exported, 'proof'), 'Pending answer key and private verifier proof are excluded from export');
security_assert(! str_contains($exported, 'ENTIRE_DRAFT_SECRET') && ! str_contains($exported, 'privacy-account-8'), 'Whole draft and peer session are not exported');
security_assert(! str_contains($exported, 'PRIVATE_PROMPT_8') && str_contains($exported, '&lt;'), 'Peer questions are excluded and source operators remain escaped text');
security_assert(str_contains($exported, '본인 설정·일정 변경 이력'), 'Own administrative metadata is exportable');
security_expect(array('data' => array(), 'done' => true), Personal_Data::export_data('missing@example.test'), 'Unknown email has no student data');

// The same current-roster policy applies to old timeline and new statistics.
$GLOBALS['focus_test_user_id'] = 1;
$timeline = new WP_REST_Request(array(), array('id' => $activity, 'user_id' => 7));
security_expect(true, Scores_Controller::can_view_participant($timeline), 'Current learner in authorized context is readable');
platform_fixture_connect_runtime(1, array(8));
security_error(Scores_Controller::can_view_participant($timeline), 403, 'Former learner is denied even when their raw activity is retained');
security_error(Scores_Controller::timeline($timeline), 403, 'Timeline callback rechecks membership before reading data');
security_expect(121, security_counts(7)['events'], 'Revoking teacher access preserves former learner records');
$GLOBALS['focus_test_user_id'] = 7;
security_expect(true, Scores_Controller::can_view_participant($timeline), 'Student can still access their own retained timeline');

$before_delete = security_counts(7);
$lease = Retention_Cleanup::acquire_lock();
security_assert($lease !== null, 'Retention and privacy share an atomic global lease');
security_assert(Personal_Data::erase_data('learner@example.test') instanceof WP_Error, 'A concurrent cleanup defers privacy deletion rather than racing snapshots');
security_expect($before_delete, security_counts(7), 'Deferred privacy work changes no learner data');
Retention_Cleanup::release_lock($lease);
$wpdb->fail_pattern = 'DELETE FROM ' . Installer::events_table();
security_assert(Personal_Data::erase_data('learner@example.test') instanceof WP_Error, 'A privacy delete storage failure is visible and retryable');
security_expect($before_delete, security_counts(7), 'Failed paginated erasure rolls back all records in its page');
$done = false;
for ($page = 1; $page <= 10 && ! $done; ++$page) {
    $result = Personal_Data::erase_data('learner@example.test', $page);
    security_assert(is_array($result), 'Paginated privacy erasure succeeds');
    security_expect(true, $result['items_retained'], 'Only the explicitly disclosed replay-protection metadata is retained');
    $done = $result['done'];
    if ($page === 1) {
        security_expect(false, $done, 'A large erasure is visibly in progress between bounded pages');
        security_expect('erasing', Privacy_Guard::state(7), 'Erasure progress is durably protected between callbacks');
        $called = false;
        $guarded = Privacy_Guard::run(7, static function () use (&$called) { $called = true; return 'must not run'; });
        security_error($guarded, 423, 'A derived-data read cannot recreate erased snapshots during erasure');
        security_expect(false, $called, 'Blocked data callback never reads source');
        security_error(Check_Service::next(7, array('problem_id' => 123, 'draft' => array('source' => array('not read'), 'language_id' => -1))), 423, 'Erasure blocks source analysis before draft validation');
        security_error(Events_Controller::handle(new WP_REST_Request(array())), 423, 'Erasure blocks ingestion before accepting another session');
        security_error(Tracking_Controller::state(new WP_REST_Request(array('problem_id' => 123))), 423, 'Activity leases stop during privacy erasure');
        security_error(Statistics_Controller::self_statistics(new WP_REST_Request()), 423, 'Statistics REST entry uses the same user guard');
        platform_fixture_connect_runtime(1, array(7, 8));
        $GLOBALS['focus_test_user_id'] = 1;
        security_error(Scores_Controller::activity_scores(new WP_REST_Request(array(), array('id' => $activity))), 423, 'Multi-student cached scoring cannot resurrect an erasing learner');
        $GLOBALS['focus_test_user_id'] = 7;
    }
}
security_assert($done, 'All learner tables and audit metadata are fully erased');
security_expect(array_fill_keys(array_keys($before_delete), 0), security_counts(7), 'Raw events, links, sessions, scores, checks, quotas and daily aggregates are all removed');
security_expect(array(), Audit_Log::for_user(7), 'Own audit actor references are removed');
security_expect(1, count(Audit_Log::for_user(8)), 'Other actor audit references survive');
security_expect($peer_counts, security_counts(8), 'Peer raw and aggregate data survives personal erasure');
security_assert(Activity_Repository::find($activity) !== null, 'Shared schedule survives personal erasure');
security_expect('open', Privacy_Guard::state(7), 'Completed personal erasure permits new learning afterward');
security_assert(str_contains(implode(' ', $result['messages']), '재저장'), 'WordPress privacy tools disclose the retained replay protection');
$first_cutoff = Privacy_Guard::cutoff(7);
security_assert($first_cutoff > 0, 'Completed erasure stores an inclusive event watermark');
$old_packet = array('user_id' => 7, 'problem_id' => 123, 'session_uid' => 'privacy-still-open-tab', 'session_started_at' => $now - 30000, 'events' => array(
    array('seq' => 1, 'type' => 'activity', 'occurred_at' => $first_cutoff - 1),
    array('seq' => 2, 'type' => 'heartbeat', 'occurred_at' => $first_cutoff),
));
$old_result = Events_Controller::handle(new WP_REST_Request($old_packet));
security_assert($old_result instanceof WP_REST_Response && $old_result->data['acknowledged'], 'An erased old outbox receives a terminal ACK');
security_expect(2, $old_result->data['policyDropped'], 'Events before and exactly at erasure are discarded');
security_expect(0, security_counts(7)['participations'], 'Old outbox retry cannot recreate an erased participation');
usleep(10000);
$new_packet = $old_packet;
$new_packet['events'][] = array('seq' => 3, 'type' => 'activity', 'occurred_at' => Clock::now_ms(), 'meta' => array('visible' => true, 'focused' => true, 'len_delta' => 1));
$new_result = Events_Controller::handle(new WP_REST_Request($new_packet));
security_assert($new_result instanceof WP_REST_Response && $new_result->data['stored'] > 0, 'An already-open tab may collect new events after erasure');
security_expect(2, $new_result->data['policyDropped'], 'Old events in a mixed packet stay discarded');
security_expect(1, security_counts(7)['participations'], 'New events create one fresh protected source segment');
$fresh = Participation_Repository::find($new_result->data['participation_id']);
security_expect($first_cutoff + 1, $fresh['started_at_ms'], 'The new source lifetime cannot extend back across erasure');
$first_protected_uid = $fresh['session_uid'];
$again = Events_Controller::handle(new WP_REST_Request($new_packet));
security_expect(0, $again->data['stored'], 'Fresh post-erasure events remain idempotent');
$protection_export = Personal_Data::export_data('learner@example.test');
security_assert(str_contains(json_encode($protection_export, JSON_UNESCAPED_UNICODE), 'discardEventsThroughMs'), 'Retained deletion watermark is visible in the personal export');
$new_learning = Check_Service::next(7, array('problem_id' => 123));
security_expect('ready', $new_learning['status'], 'A remaining student may start new records after erasure completes');
do { $result = Personal_Data::erase_data('learner@example.test'); } while (is_array($result) && ! $result['done']);
security_assert(is_array($result) && $result['done'], 'New learning records remain erasable');
$second_cutoff = Privacy_Guard::cutoff(7);
security_assert($second_cutoff > $first_cutoff, 'A second erasure advances rather than clears the watermark');
$replay = Events_Controller::handle(new WP_REST_Request($new_packet));
security_expect(3, $replay->data['policyDropped'], 'The second erasure also discards previously new outbox records');
security_expect(0, security_counts(7)['events'], 'Repeated erasure never restores old raw evidence');
usleep(10000);
$new_packet['events'] = array(array('seq' => 4, 'type' => 'activity', 'occurred_at' => Clock::now_ms(), 'meta' => array('visible' => true, 'focused' => true)));
$resumed = Events_Controller::handle(new WP_REST_Request($new_packet));
security_assert($resumed instanceof WP_REST_Response && $resumed->data['stored'] > 0, 'New learning can resume after multiple erasures');
$latest = Participation_Repository::find($resumed->data['participation_id']);
security_assert($latest['session_uid'] !== $first_protected_uid, 'A new erasure creates a different stable source segment');
security_expect($second_cutoff + 1, $latest['started_at_ms'], 'The next lifetime starts after the latest erasure only');
do { $result = Personal_Data::erase_data('learner@example.test'); } while (is_array($result) && ! $result['done']);

$rolled_back = Privacy_Guard::run(7, static function () {
    add_option('focus_tracker_guard_rollback_test', 'not committed', '', false);
    throw new RuntimeException('test rollback');
});
security_error($rolled_back, 503, 'Guard catches storage/processing exceptions');
security_expect(false, get_option('focus_tracker_guard_rollback_test', false), 'Guard rolls back writes from a failed derived-data callback');

security_seed(9, $activity, 4);
$before_account = security_counts(9);
$wpdb->fail_pattern = 'DELETE FROM ' . Installer::scores_table();
$aborted = false;
try { Personal_Data::delete_user(9); } catch (RuntimeException $error) { $aborted = true; }
security_assert($aborted, 'Account deletion stops if personal data cannot be removed');
security_expect($before_account, security_counts(9), 'Account-deletion failure rolls back child deletions');
foreach ($GLOBALS['focus_test_hooks']['delete_user'] as $callback) { $callback(9); }
security_expect(array_fill_keys(array_keys($before_account), 0), security_counts(9), 'Actual registered account-deletion callback removes all own data');
foreach ($GLOBALS['focus_test_hooks']['deleted_user'] as $callback) { $callback(9); }
security_expect('deleted', Privacy_Guard::state(9), 'A minimal tombstone protects the deleted account');
$GLOBALS['focus_test_user_id'] = 9;
security_error(Check_Service::next(9, array('problem_id' => 123)), 423, 'A previously authenticated request cannot recreate deleted-account checks');
security_error(Events_Controller::handle(new WP_REST_Request(array())), 423, 'A previously authenticated request cannot recreate deleted-account sessions');
security_error(Statistics_Controller::self_statistics(new WP_REST_Request()), 423, 'A previously authenticated report cannot recreate deleted-account aggregates');
security_expect($peer_counts, security_counts(8), 'Idempotent post-delete cleanup never touches peers');
security_assert(Activity_Repository::find($activity) !== null, 'Account deletion leaves shared activity intact');

update_option('focus_tracker_cleanup_status', array('failures' => 1));
update_option('focus_tracker_cleanup_progress', array('cursor' => 1));
update_option('focus_tracker_schema_health', array('ready' => true));
define('WP_UNINSTALL_PLUGIN', true);
require dirname(__DIR__) . '/uninstall.php';
security_expect(false, get_option('focus_tracker_cleanup_status', false), 'Uninstall removes operational status');
security_expect(false, get_option('focus_tracker_cleanup_progress', false), 'Uninstall removes continuation metadata');
security_expect(false, get_option('focus_tracker_audit_log', false), 'Uninstall removes all audit metadata');
security_expect(false, get_option('focus_tracker_schema_health', false), 'Uninstall removes schema status');
security_expect(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'focus!_tracker!_privacy!_%' ESCAPE '!'"), 'Uninstall removes protection tombstones');
security_expect(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'focus!_tracker!_limit!_%' ESCAPE '!'"), 'Uninstall removes request-budget metadata');
security_expect(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'focus!_tracker!_diagnostic!_%' ESCAPE '!'"), 'Uninstall removes anonymous operational counters');
security_expect('1', get_option('focusXtrackerXlimitXunrelated'), 'Uninstall preserves other plugins options');

echo "privacy-security: {$checks} SQL-backed checks passed\n";
