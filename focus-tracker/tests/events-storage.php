<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';
platform_fixture_connect_runtime();

use FocusTracker\Database\Activity_Repository as Activities;
use FocusTracker\Database\Participation_Repository as Participations;
use FocusTracker\Database\Event_Repository as Events;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Score_Repository as Scores;
use FocusTracker\Rest\Controllers\Events_Controller as Ingest;
use FocusTracker\Rest\Controllers\Activities_Controller as ActivityApi;
use FocusTracker\Scoring\Activity_Score_Service as Scoring;
use FocusTracker\Support\Clock;

$checks = 0;
function check($condition, string $message): void {
    global $checks, $wpdb;
    $checks++;
    if (! $condition) { throw new RuntimeException($message . ' ' . $wpdb->last_error); }
}
function post_events(string $uid, int $start, array $events, bool $final = false, ?int $problem = 123) {
    return Ingest::handle(new WP_REST_Request(array('user_id' => get_current_user_id(), 'session_uid' => $uid, 'session_started_at' => $start, 'problem_id' => $problem, 'is_final' => $final, 'events' => $events)));
}
function create_activity(int $start, int $end, ?array $problems = array(123)): array {
    $result = ActivityApi::create(new WP_REST_Request(array(
        'name' => '수집·계산 회귀', 'context_type' => 'class', 'context_id' => 'class:1',
        'starts_at' => gmdate('c', intdiv($start, 1000)), 'ends_at' => gmdate('c', intdiv($end, 1000)), 'problem_ids' => $problems,
    )));
    check($result instanceof WP_REST_Response && ! empty($result->data['id']), 'Activity created through actual controller');
    return Activities::find((int) $result->data['id']);
}

// Cookie identity/capability checks and both valid WP nonce ages.
$request = new WP_REST_Request(array(), array('_wpnonce' => 'focus-test-nonce'));
check(Ingest::authorize($request) === true, 'Current nonce accepted');
$GLOBALS['focus_test_nonce_age'] = 2;
check(Ingest::authorize($request) === true, 'Previous nonce tick accepted');
$GLOBALS['focus_test_allowed'] = false;
check(Ingest::authorize($request) instanceof WP_Error, 'Capability denied');
$GLOBALS['focus_test_allowed'] = true;
check(Ingest::authorize(new WP_REST_Request()) instanceof WP_Error, 'Missing nonce denied');
$GLOBALS['focus_test_user_id'] = 0;
check(Ingest::authorize($request) instanceof WP_Error, 'Anonymous denied');
$GLOBALS['focus_test_user_id'] = 1;

$now = intdiv(Clock::now_ms(), 1000) * 1000 - 1000;
$start = $now - 180000;
$first = array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $start, 'meta' => array('visible' => true, 'focused' => true)));
$result = post_events('preopened', $start, $first);
check($result instanceof WP_REST_Response && $result->data['acknowledged'] && $result->data['stored'] === 0 && $result->data['policyDropped'] === 1, 'Preopened observations are acknowledged and excluded before an activity exists');
check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::participations_table()) === 0, 'No session row is created outside authorized activity windows');
$a = create_activity($now - 120000, $now - 60000);
$b = create_activity($now - 90000, $now - 30000);
$other = create_activity($now - 120000, $now - 60000, array(999));
$batch = array();
for ($at = $start + 15000, $seq = 2; $at <= $now; $at += 15000, $seq++) {
    $batch[] = array('seq' => $seq, 'type' => ($seq % 2 ? 'activity' : 'heartbeat'), 'occurred_at' => $at, 'meta' => array('visible' => true, 'focused' => true, 'source' => 'MUST_NOT_BE_STORED'));
}
$batch[] = array('seq' => $seq, 'type' => 'session_end', 'occurred_at' => $now);
$result = post_events('preopened', $start, $batch, true);
check($result instanceof WP_REST_Response && $result->data['acknowledged'], 'Activity batch acknowledged after transaction');
$participation = Participations::find((int) $result->data['participation_id']);
check($participation['started_at_ms'] === $now - 120000 && $participation['ended_at_ms'] === $now - 30000, 'The server bounds a preopened physical page to the authorized continuous activity interval');
$ids = Participations::activity_ids($participation['id']);
sort($ids);
check($ids === array($a['id'], $b['id']), 'Preopened session associated with ALL overlapping windows, excluding wrong problem');
$count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table());
$revision = Participations::find($participation['id'])['event_revision'];
$saved_heartbeat = Participations::find($participation['id'])['last_heartbeat_at_ms'];
$result = post_events('preopened', $start, $batch, true);
check($result instanceof WP_REST_Response && $result->data['stored'] === 0, 'Retry acknowledged as duplicate');
check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table()) === $count, 'No duplicate rows');
check(Participations::find($participation['id'])['event_revision'] === $revision, 'Duplicate retry does not increment revision');
$tampered_retry = array(array('seq' => 2, 'type' => 'heartbeat', 'occurred_at' => $now + 10000, 'meta' => array('queue_dropped' => true)));
post_events('preopened', $start, $tampered_retry);
check(Participations::find($participation['id'])['last_heartbeat_at_ms'] === $saved_heartbeat, 'An excluded retry cannot forge a new heartbeat timestamp');
check(! Participations::find($participation['id'])['has_delivery_gaps'], 'Duplicate sequence cannot alter saved quality metadata');
check(! str_contains((string) $wpdb->get_var('SELECT meta FROM ' . Installer::events_table() . " WHERE event_type = 'activity' LIMIT 1"), 'MUST_NOT_BE_STORED'), 'Only allowed signal metadata persists');
$score = Scoring::score_for_learner($a, 1);
check($score['score_total'] === 20.0, 'Actual stored events compute full observed participation');
check($score['data_status'] === 'complete', 'Settled window marked complete');
$cached = Scoring::score_for_learner($a, 1);
check($cached['cached'] === true && $cached['score_total'] === 20.0, 'Settled snapshot reused');
$latest = Participations::find_latest_activity_participation_for_user(1);
check($latest['activity_id'] === $b['id'], 'Student summary resolves latest linked activity');
$score_request = new WP_REST_Request(array(), array('id' => $a['id'], 'user_id' => 1));
$timeline = \FocusTracker\Rest\Controllers\Scores_Controller::timeline($score_request)->data;
$active_ms = array_sum(array_map(static fn ($span) => $span['endedAt'] - $span['startedAt'], $timeline['spans']));
check($active_ms === 60000 && $score['total_active_seconds'] === 60, 'REST timeline and grade use identical active intervals');

$before_participations = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::participations_table());
$bad = post_events('invalid', $now, array(array('seq' => 1, 'type' => 'activity', 'occurred_at' => $now + 90000)));
check($bad instanceof WP_Error && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::participations_table()) === $before_participations, 'Invalid batch creates no orphan participation');
$current_activity = create_activity($now - 10000, $now + 60000);
$wpdb->fail_pattern = 'INSERT INTO ' . Installer::events_table();
$failed = post_events('write-failure', $now, array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $now)));
check($failed instanceof WP_Error && $failed->data['status'] === 503, 'Failed storage never acknowledged');
check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::participations_table()) === $before_participations, 'Failed batch rolls back the logical participation as well as events');
$retry = post_events('write-failure', $now, array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $now)));
check($retry instanceof WP_REST_Response && $retry->data['acknowledged'], 'Identical failed request can retry');
$GLOBALS['focus_test_user_id'] = 2;
check(post_events('preopened', $start, $batch) instanceof WP_Error, 'Other account cannot write an existing authorized logical session');
$GLOBALS['focus_test_user_id'] = 1;
$changed_account = Ingest::handle(new WP_REST_Request(array('user_id' => 2, 'problem_id' => 123, 'session_uid' => 'new-account', 'session_started_at' => $now, 'events' => $first)));
check($changed_account instanceof WP_Error && $changed_account->code === 'focus_tracker_account_changed', 'Stale page cannot attribute events to new login');

$gap_event = array(array('seq' => $seq + 1, 'type' => 'heartbeat', 'occurred_at' => $now - 110000, 'meta' => array('queue_dropped' => true)));
post_events('preopened', $start, $gap_event);
$gap = Scoring::score_for_learner($a, 1);
check($gap['data_status'] === 'incomplete' && $gap['needs_review'], 'Delivery loss is exposed, never silently complete');
$change = ActivityApi::update(new WP_REST_Request(array('weight_focus' => -1), array('id' => $a['id'])));
check($change instanceof WP_Error, 'Negative weight rejected');
$change = ActivityApi::update(new WP_REST_Request(array('ends_at' => $a['starts_at']), array('id' => $a['id'])));
check($change instanceof WP_Error, 'Inverted window rejected');
$change = ActivityApi::update(new WP_REST_Request(array('weight_focus' => 0), array('id' => $a['id'])));
check($change instanceof WP_REST_Response, 'Valid weight update accepted');
check(Scores::get($a['id'], 1) === null, 'Activity edit invalidates cached score');
check(Scoring::score_for_learner(Activities::find($a['id']), 1)['score_focus'] === 0.0, 'Updated rubric actually recomputed');
$selection = ActivityApi::update(new WP_REST_Request(array('problem_ids' => array(999)), array('id' => $a['id'])));
check($selection instanceof WP_REST_Response && Scoring::score_for_learner(Activities::find($a['id']), 1)['data_status'] === 'no_data', 'Problem change excludes old linked observations');
ActivityApi::update(new WP_REST_Request(array('problem_ids' => array(123)), array('id' => $a['id'])));
check(Scoring::score_for_learner(Activities::find($a['id']), 1)['data_status'] === 'incomplete', 'Restored problem set reconciles recorded sessions');

// Purging old evidence must first freeze every grade using those observations.
$old_start = $now - 70 * DAY_IN_SECONDS * 1000;
$old_activity = create_activity($old_start, $old_start + 60000);
$old_p = Participations::find_or_create(array('activity_id' => $old_activity['id'], 'user_id' => 1, 'problem_id' => 123, 'session_uid' => 'retention-old', 'started_at_ms' => $old_start));
$old_events = array();
for ($at = $old_start, $seq = 1; $at <= $old_start + 60000; $at += 15000, $seq++) {
    $old_events[] = array('seq' => $seq, 'type' => 'activity', 'occurred_at' => $at, 'meta' => array('visible' => true, 'focused' => true));
}
Events::insert_batch($old_p['id'], $old_events);
Participations::touch_heartbeat($old_p['id'], $old_start + 60000);
Participations::close($old_p['id'], $old_start + 60000, 'pagehide');
check(Scores::get($old_activity['id'], 1) === null, 'Historical record has never been scored');
\FocusTracker\Jobs\Retention_Cleanup::run();
$archive = Scoring::score_for_learner($old_activity, 1, true);
check($archive['data_status'] === 'archived' && $archive['score_total'] === 20.0, 'Purge creates then preserves final snapshot');
check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id = ' . $old_p['id']) === 0, 'Old raw events removed only after snapshot saved');
Scores::invalidate($old_activity['id'], 1);
check(Scores::get($old_activity['id'], 1) !== null, 'Archived snapshot cannot be invalidated away');
$old_activity['weight_focus'] = 0;
$archive_changed = Scoring::score_for_learner($old_activity, 1, true);
check($archive_changed['score_total'] === 20.0 && $archive_changed['needs_review'], 'Missing source prevents retrospective invented score after rubric change');

$failure_start = $old_start - 2 * DAY_IN_SECONDS * 1000;
$failure_activity = create_activity($failure_start, $failure_start + 60000);
$failure_p = Participations::find_or_create(array('activity_id' => $failure_activity['id'], 'user_id' => 1, 'problem_id' => 123, 'session_uid' => 'retention-failure', 'started_at_ms' => $failure_start));
$failure_events = array_map(static function (array $event) use ($failure_start, $old_start): array {
    $event['occurred_at'] += $failure_start - $old_start;
    return $event;
}, $old_events);
Events::insert_batch($failure_p['id'], $failure_events);
Participations::touch_heartbeat($failure_p['id'], $failure_start + 60000);
Participations::close($failure_p['id'], $failure_start + 60000, 'pagehide');
check(Scoring::score_for_learner($failure_activity, 1)['score_total'] === 20.0, 'Older final snapshot exists before failed refresh');
Events::insert_batch($failure_p['id'], array(array('seq' => 99, 'type' => 'hidden', 'occurred_at' => $failure_start + 1000, 'meta' => array('visible' => false, 'focused' => false))));
Participations::record_event_revision($failure_p['id'], 1);
$wpdb->fail_pattern = 'INSERT INTO ' . Installer::scores_table();
\FocusTracker\Jobs\Retention_Cleanup::run();
check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id = ' . $failure_p['id']) > 0, 'Failed final refresh preserves raw events even if an older snapshot exists');
check(! Scores::get($failure_activity['id'], 1)['raw_events_expired'], 'Failed snapshot never marks old score as safe archive');

// Required-column verification must detect a partial schema upgrade.
$wpdb->query('ALTER TABLE ' . Installer::scores_table() . ' DROP COLUMN has_observation_gaps');
check(Installer::inspect_schema()['ready'] === false, 'Missing migration column fails readiness');

echo "PASS {$checks} SQL-backed event/storage/retention checks\n";
