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
function event_insert_queries(): array {
    global $wpdb;
    return array_values(array_filter($wpdb->queries, static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO ' . Installer::events_table())));
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
$wpdb->queries = array();
$result = post_events('preopened', $start, $batch, true);
check($result instanceof WP_REST_Response && $result->data['stored'] === 0, 'Retry acknowledged as duplicate');
check(event_insert_queries() === array(), 'A complete replay never issues an events INSERT, including duplicate synthetic controls');
check($result->data['participation_ids'] === array($participation['id']), 'A complete replay still acknowledges its original logical session');
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

// Repartitioning cannot create an empty new logical run just to replay events
// whose physical sequence numbers were already stored in an older run.
$sessionCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::participations_table());
$recordedReplay = array_map(static fn (array $row): array => array('seq' => (int) $row['sequence_number'], 'type' => $row['event_type'], 'occurred_at' => (int) $row['occurred_at_ms']),
    $wpdb->get_results('SELECT sequence_number,event_type,occurred_at_ms FROM ' . Installer::events_table() . ' WHERE participation_id=' . $participation['id'] . ' AND sequence_number>0 AND sequence_number<2147483648 ORDER BY sequence_number', ARRAY_A));
Activities::update($a['id'], array('starts_at' => Clock::ms_to_mysql($now - 115000)));
try { $movedReplay = post_events('preopened', $start, $recordedReplay, true); }
finally { Activities::update($a['id'], array('starts_at' => $a['starts_at'])); }
check($movedReplay instanceof WP_REST_Response && $movedReplay->data['stored'] === 0 && $movedReplay->data['participation_ids'] === array($participation['id']), 'A moved run acknowledges sequences already belonging to the original run');
check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::participations_table()) === $sessionCount, 'Already stored real events cannot create a new empty segment after a schedule edit');

// A replay may carry new final/deadline metadata even though no real event is
// new. Preserve that metadata without re-inserting duplicate event/control rows.
$finalEvents = array(array('seq' => 1, 'type' => 'heartbeat', 'occurred_at' => $now - 3000));
$finalInitial = post_events('final-flag-replay', $now - 5000, $finalEvents);
$finalId = (int) $finalInitial->data['participation_id'];
$finalRevision = Participations::find($finalId)['event_revision'];
$wpdb->queries = array();
$finalReplay = post_events('final-flag-replay', $now - 5000, $finalEvents, true);
check($finalReplay instanceof WP_REST_Response && $finalReplay->data['stored'] === 0 && event_insert_queries() === array(), 'A final-flag-only replay writes no duplicate events');
check(Participations::find($finalId)['ended_at_ms'] === $now - 3000 && Participations::find($finalId)['end_reason'] === 'pagehide', 'A new final flag still closes an existing source at its observation');
check(Participations::find($finalId)['event_revision'] === $finalRevision, 'Final metadata alone does not change the event revision');

$deadlineInitial = post_events('deadline-replay', $now - 5000, $finalEvents);
$deadlineId = (int) $deadlineInitial->data['participation_id'];
Activities::update($current_activity['id'], array('ends_at' => Clock::ms_to_mysql($now - 1000)));
try {
    $deadlineReplay = post_events('deadline-replay', $now - 5000, $finalEvents);
    check($deadlineReplay instanceof WP_REST_Response && $deadlineReplay->data['stored'] === 1, 'A shortened ended window stores only its previously absent synthetic end control');
    check(Participations::find($deadlineId)['ended_at_ms'] === $now - 1000, 'A duplicate real packet still applies the current shortened deadline');
    $deadlineRevision = Participations::find($deadlineId)['event_revision'];
    $wpdb->queries = array();
    $deadlineAgain = post_events('deadline-replay', $now - 5000, $finalEvents);
    check($deadlineAgain instanceof WP_REST_Response && $deadlineAgain->data['stored'] === 0 && event_insert_queries() === array() && Participations::find($deadlineId)['event_revision'] === $deadlineRevision, 'A second deadline replay does not reinsert either reserved control or increment revision');
} finally { Activities::update($current_activity['id'], array('ends_at' => $current_activity['ends_at'])); }

$mixedInitial = post_events('mixed-sequence-replay', $now - 5000, array(array('seq' => 1, 'type' => 'heartbeat', 'occurred_at' => $now - 4000)));
$mixedId = (int) $mixedInitial->data['participation_id'];
$mixedRevision = Participations::find($mixedId)['event_revision'];
$mixed = post_events('mixed-sequence-replay', $now - 5000, array(
    array('seq' => 1, 'type' => 'heartbeat', 'occurred_at' => $now - 4000),
    array('seq' => 2, 'type' => 'activity', 'occurred_at' => $now - 2000, 'meta' => array('len_delta' => 3)),
    array('seq' => 2, 'type' => 'activity', 'occurred_at' => $now - 1000, 'meta' => array('len_delta' => 999, 'queue_dropped' => true)),
));
check($mixed instanceof WP_REST_Response && $mixed->data['stored'] === 1 && Participations::find($mixedId)['event_revision'] === $mixedRevision + 1, 'A partial replay and within-packet duplicates store only the one new physical sequence');
$mixedSaved = $wpdb->get_row('SELECT occurred_at_ms,meta FROM ' . Installer::events_table() . ' WHERE participation_id=' . $mixedId . ' AND sequence_number=2', ARRAY_A);
check((int) $mixedSaved['occurred_at_ms'] === $now - 2000 && (json_decode($mixedSaved['meta'], true)['len_delta'] ?? null) === 3, 'The first occurrence of a packet sequence owns its stored timestamp and metadata');
check(! Participations::find($mixedId)['has_delivery_gaps'] && Participations::find($mixedId)['last_heartbeat_at_ms'] === $now - 2000, 'A duplicate conflicting payload cannot forge a gap or heartbeat');

// Retention may lock an existing cold source independently of the learner
// guard. Change its metadata after the prefix snapshot, before the next read.
$cold = post_events('retention-current-metadata', $now - 5000, array(array('seq' => 1, 'type' => 'heartbeat', 'occurred_at' => $now - 1000)));
check($cold instanceof WP_REST_Response && $cold->data['stored'] > 0, 'An ordinary source exists before the retention metadata race');
$coldId = (int) $cold->data['participation_id'];
$coldBefore = Participations::find($coldId);
$coldCount = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id = ' . $coldId);
$retentionInterleaved = false;
$originalDb = $wpdb;
$wpdb = new class($originalDb, static function () use ($originalDb, $coldId, &$retentionInterleaved): void {
    $retentionInterleaved = true;
    $originalDb->update(Installer::participations_table(), array('raw_events_expired' => 1), array('id' => $coldId));
}) {
    private bool $used = false;
    public function __construct(private object $db, private Closure $afterPrefix) {}
    public function __get(string $name): mixed { return $this->db->$name; }
    public function __set(string $name, mixed $value): void { $this->db->$name = $value; }
    public function __isset(string $name): bool { return isset($this->db->$name); }
    public function __call(string $name, array $arguments): mixed { return $this->db->$name(...$arguments); }
    public function get_results(string $sql, mixed $mode = ARRAY_A): array {
        $rows = $this->db->get_results($sql, $mode);
        if (! $this->used && str_contains($sql, 'WHERE session_uid >=') && str_contains($sql, Installer::participations_table())) {
            $this->used = true; ($this->afterPrefix)();
        }
        return $rows;
    }
};
try { $coldRetry = post_events('retention-current-metadata', $now - 5000, array(array('seq' => 2, 'type' => 'activity', 'occurred_at' => $now))); }
finally { $wpdb = $originalDb; }
check($retentionInterleaved, 'Retention metadata changes after the original source snapshot');
check($coldRetry instanceof WP_REST_Response && $coldRetry->data['stored'] === 0 && $coldRetry->data['policyDropped'] === 1, 'The fresh expired target is acknowledged without recreating raw evidence');
check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id = ' . $coldId) === $coldCount, 'The expired target keeps its original source rows unchanged');
check(Participations::find($coldId)['event_revision'] === $coldBefore['event_revision'] && Participations::find($coldId)['last_heartbeat_at_ms'] === $coldBefore['last_heartbeat_at_ms'], 'An excluded expired packet cannot alter archived revision or observation bounds');

// One long-lived physical source can span separated lessons. Expiry of an old
// logical run cannot discard legitimate observations in the next logical run.
$oldSegment = post_events('expired-earlier-run', $start, array(array('seq' => 1, 'type' => 'heartbeat', 'occurred_at' => $now - 110000)));
check($oldSegment instanceof WP_REST_Response && $oldSegment->data['stored'] > 0, 'A physical source has an earlier authorized logical run');
$oldSegmentId = (int) $oldSegment->data['participation_id'];
$wpdb->update(Installer::participations_table(), array('raw_events_expired' => 1), array('id' => $oldSegmentId));
$newSegment = post_events('expired-earlier-run', $start, array(array('seq' => 2, 'type' => 'heartbeat', 'occurred_at' => $now)));
check($newSegment instanceof WP_REST_Response && $newSegment->data['stored'] > 0 && (int) $newSegment->data['participation_id'] !== $oldSegmentId, 'A fresh later lesson is stored despite an expired earlier segment of the same physical source');
check(! Participations::find((int) $newSegment->data['participation_id'])['raw_events_expired'], 'The later logical run retains independent live provenance');

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
