<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';

use FocusTracker\Database\Activity_Repository as Activities;
use FocusTracker\Database\Event_Repository as Events;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository as Participations;
use FocusTracker\Database\Score_Repository as Snapshots;
use FocusTracker\Privacy\Privacy_Guard as Privacy;
use FocusTracker\Rest\Controllers\Scores_Controller as Controller;
use FocusTracker\Scoring\Activity_Score_Service as Scores;
use FocusTracker\Support\Clock;

$checks = 0;
function score_query_expect(mixed $expected, mixed $actual, string $label): void {
    global $checks, $wpdb;
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($label . ': ' . var_export($actual, true) . ' DB: ' . $wpdb->last_error); }
}
function score_query_count(string $pattern): int {
    global $wpdb;
    return count(array_filter($wpdb->queries, static fn (string $query): bool => preg_match($pattern, $query) === 1));
}
function score_query_source_reads(): int { return score_query_count('/^SELECT .*FROM wp_focus_tracker_(?:participations|events|scores)\b/s'); }
function score_query_writes(): int { return score_query_count('/^INSERT INTO wp_focus_tracker_scores\b/'); }
function cache_users(array $ids): void { $GLOBALS['score_query_primed_users'][] = $ids; }

$students = range(2, 61);
platform_fixture_connect_runtime(1, $students);
$GLOBALS['focus_test_user_id'] = 1;
$now = intdiv(Clock::now_ms(), 1000) * 1000;
$start = $now - 1200000;
$activity_id = Activities::create(array('name' => 'Query budget', 'owner_user_id' => 1, 'context_type' => 'class', 'context_id' => 'class:1',
    'problem_ids' => array(123), 'starts_at' => Clock::ms_to_mysql($start), 'ends_at' => Clock::ms_to_mysql($now - 1000),
    'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
$other_id = Activities::create(array('name' => 'Unrelated scope', 'owner_user_id' => 1, 'context_type' => 'class', 'context_id' => 'class:2',
    'problem_ids' => null, 'starts_at' => Clock::ms_to_mysql($start), 'ends_at' => Clock::ms_to_mysql($now - 1000),
    'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
$participation_ids = array();
foreach ($students as $student) {
    foreach (array(0, 1) as $tab) {
        $at = $start + 5000 + $tab * 15000;
        $row = Participations::find_or_create(array('activity_id' => $tab === 0 ? $activity_id : null, 'user_id' => $student,
            'problem_id' => $tab === 0 ? 123 : null, 'session_uid' => 'query-' . $student . '-' . $tab,
            'started_at_ms' => $at, 'page_context_version' => $tab, 'page_category' => $tab === 0 ? 'problem' : 'material',
            'page_subtype' => null, 'page_key' => $tab === 0 ? null : 'material:fixture'));
        $events = array(
            array('type' => 'session_start', 'occurred_at' => $at, 'seq' => 1, 'meta' => array('visible' => true, 'focused' => true)),
            array('type' => 'activity', 'occurred_at' => $at + 5000, 'seq' => 2),
            array('type' => 'heartbeat', 'occurred_at' => $at + 15000, 'seq' => 3),
            array('type' => 'session_end', 'occurred_at' => $at + 30000, 'seq' => 4),
        );
        if ($row === null || Events::insert_batch($row['id'], $events) === false) { throw new RuntimeException('Fixture source failed.'); }
        Participations::touch_heartbeat($row['id'], $at + 30000);
        Participations::close($row['id'], $at + 30000, 'pagehide');
        Participations::record_event_revision($row['id'], count($events));
        if ($tab === 1) { Participations::link_activity($row['id'], $activity_id); }
        $participation_ids[$student][] = $row['id'];
    }
}
$foreign = Participations::find_or_create(array('activity_id' => $other_id, 'user_id' => 2, 'problem_id' => 123,
    'session_uid' => 'query-foreign', 'started_at_ms' => $start));
Events::insert_batch($foreign['id'], array(
    array('type' => 'session_start', 'occurred_at' => $start, 'seq' => 1, 'meta' => array('visible' => true, 'focused' => true)),
    array('type' => 'heartbeat', 'occurred_at' => $start + 30000, 'seq' => 2),
    array('type' => 'session_end', 'occurred_at' => $start + 40000, 'seq' => 3),
));
Participations::touch_heartbeat($foreign['id'], $start + 40000);
Participations::close($foreign['id'], $start + 40000, 'pagehide');
Participations::record_event_revision($foreign['id'], 3);
$activity = Activities::find($activity_id);

$wpdb->queries = array();
$single = Privacy::run_many($students, static function () use ($activity, $students): array {
    $scores = array(); foreach ($students as $student) { $scores[$student] = Scores::score_for_learner($activity, $student); }
    return $scores;
});
score_query_expect(false, $single instanceof WP_Error, 'Single-learner reference calls succeed');
$single_source_reads = score_query_source_reads();
score_query_expect(180, $single_source_reads, 'Sixty individual reads require separate participation/cache/event queries');
score_query_expect(0, score_query_writes(), 'Provisional single-learner reads persist no unused cache');
$foreign_score = array_replace($single[2], array('finalized' => true, 'raw_events_expired' => true, 'score_total' => 999));
Snapshots::upsert($other_id, 2, $foreign_score);

$wpdb->queries = array();
$batch = Privacy::run_many($students, static fn () => Scores::scores_for_activity($activity, array_merge($students, array(2))));
score_query_expect($single, $batch, 'Batch metrics exactly match individual calls, including overlapping tabs and mixed page types');
score_query_expect(3, score_query_source_reads(), 'Sixty learners preload participation/cache/event data in three queries');
score_query_expect(1, score_query_count('/^SELECT option_name, option_value FROM wp_options WHERE option_name IN/'), 'Privacy watermarks use one fresh batch read after the locks');
score_query_expect(0, score_query_writes(), 'A provisional roster refresh writes no score snapshots');
score_query_expect($students, array_keys($batch), 'Input order is retained and duplicate learner IDs are read once');
score_query_expect(false, $batch[2]['finalized'], 'An ended activity remains provisional until heartbeat grace settles');
score_query_expect(false, $batch[2]['cache_saved'], 'Skipped provisional persistence is explicit to internal callers');

$presentation = new ReflectionMethod(Controller::class, 'present_metrics');
$presented = $presentation->invoke(null, $batch[2]);
score_query_expect(false, array_key_exists('cache_saved', $presented) || array_key_exists('cacheSaved', $presented), 'Internal skipped persistence is not exposed as a UI save failure');
score_query_expect($single[2]['data_status'], $presented['dataStatus'], 'The existing UI data status is unchanged');

$GLOBALS['score_query_primed_users'] = array();
$roster_response = Controller::activity_scores(new WP_REST_Request(array('id' => $activity_id)));
score_query_expect(true, $roster_response instanceof WP_REST_Response, 'The actual roster controller returns the authorized results');
score_query_expect(array($students), $GLOBALS['score_query_primed_users'], 'Only the authorized learner IDs are primed in the WordPress user cache');
score_query_expect(60, count($roster_response->data['rows']), 'The roster still contains all sixty learner rows');
score_query_expect('학생 2', $roster_response->data['rows'][0]['displayName'], 'The existing WordPress display names are retained');

$wpdb->queries = array();
$timeline = Controller::timeline(new WP_REST_Request(array('id' => $activity_id, 'user_id' => 2)));
score_query_expect(true, $timeline instanceof WP_REST_Response, 'The actual timeline controller accepts the authorized learner');
score_query_expect(1, score_query_count('/^SELECT p\.\* FROM wp_focus_tracker_participations p/'), 'Score and timeline share one participation read');
score_query_expect(1, score_query_count('/^SELECT participation_id, event_type, occurred_at_ms/'), 'Score and timeline share one event read');
score_query_expect(true, count($timeline->data['spans']) > 0 && count($timeline->data['pages']) > 0, 'Shared source still renders activity and page timelines');

$wpdb->update(Installer::participations_table(), array('event_revision' => 20000), array('id' => $participation_ids[3][0]));
$wpdb->queries = array();
$bounded = Privacy::run_many($students, static fn () => Scores::scores_for_activity($activity, $students));
score_query_expect($single[3]['score_total'], $bounded[3]['score_total'], 'A large source revision budget does not change the score');
score_query_expect(3, score_query_count('/^SELECT participation_id, event_type, occurred_at_ms/'), 'A large learner source is isolated between bounded raw preloads');
$wpdb->update(Installer::participations_table(), array('event_revision' => 4), array('id' => $participation_ids[3][0]));
$wpdb->update(Installer::participations_table(), array('event_revision' => 0), array('id' => $participation_ids[2][0]));
$wpdb->queries = array();
$legacy = Privacy::run_many($students, static fn () => Scores::scores_for_activity($activity, $students));
score_query_expect($single[2]['score_total'], $legacy[2]['score_total'], 'Unknown legacy source counts retain the original learner result');
score_query_expect(2, score_query_count('/^SELECT participation_id, event_type, occurred_at_ms/'), 'Unknown legacy source is read alone rather than multiplying peak raw memory');
$wpdb->update(Installer::participations_table(), array('event_revision' => 4), array('id' => $participation_ids[2][0]));

$settled = $activity;
$settled['ends_at'] = Clock::ms_to_mysql($now - 60000);
$wpdb->queries = array();
$final = Privacy::run_many($students, static fn () => Scores::scores_for_activity($settled, $students));
score_query_expect(true, $final[2]['finalized'] && $final[2]['cache_saved'], 'A settled snapshot is still saved for retention');
score_query_expect(60, score_query_writes(), 'Every first settled snapshot is persisted');
$wpdb->queries = array();
$cached = Privacy::run_many($students, static fn () => Scores::scores_for_activity($settled, $students));
score_query_expect(true, $cached[2]['cached'], 'A settled signature-valid batch reuses its cache');
score_query_expect(2, score_query_source_reads(), 'All settled cache hits read only participation and snapshot metadata');
score_query_expect(0, score_query_count('/^SELECT participation_id, event_type, occurred_at_ms/'), 'All settled cache hits avoid raw event reads');
score_query_expect(0, score_query_writes(), 'A settled cache hit performs no score writes');

Events::insert_batch($participation_ids[2][0], array(array('type' => 'activity', 'occurred_at' => $start + 12000, 'seq' => 9)));
Participations::record_event_revision($participation_ids[2][0], 1);
$wpdb->queries = array();
$late = Privacy::run_many($students, static fn () => Scores::scores_for_activity($settled, $students));
score_query_expect(false, $late[2]['cached'], 'A late source revision invalidates only its learner snapshot');
score_query_expect(true, $late[3]['cached'], 'An unrelated learner snapshot remains cached');
score_query_expect(1, score_query_writes(), 'A late revision rewrites only the changed settled score');
score_query_expect(1, score_query_count('/^SELECT participation_id, event_type, occurred_at_ms/'), 'Only changed learner raw source is replayed');

$snapshot_hash = hash('sha256', json_encode($wpdb->get_results('SELECT * FROM ' . Installer::scores_table() . ' ORDER BY user_id', ARRAY_A)));
foreach (array('SELECT p.* FROM wp_focus_tracker_participations p', 'SELECT * FROM wp_focus_tracker_scores WHERE activity_id', 'SELECT participation_id, event_type, occurred_at_ms') as $fault) {
    $wpdb->before_results = static function (string $query) use ($fault, $wpdb): void {
        if (! str_contains($query, $fault)) { return; }
        $wpdb->before_results = null;
        $wpdb->last_error = 'Injected batch read failure';
    };
    $failed = Privacy::run_many($students, static fn () => Scores::scores_for_activity($settled, $students, true));
    score_query_expect(true, $failed instanceof WP_Error, 'A failed batch read fails closed rather than inventing no-data results');
    score_query_expect($snapshot_hash, hash('sha256', json_encode($wpdb->get_results('SELECT * FROM ' . Installer::scores_table() . ' ORDER BY user_id', ARRAY_A))), 'A failed batch leaves every retained score unchanged');
}

$archived_value = Snapshots::get($activity_id, 2)['score_total'];
Snapshots::mark_events_expired($activity_id, 2);
foreach ($participation_ids[2] as $id) {
    $wpdb->update(Installer::participations_table(), array('raw_events_expired' => 1), array('id' => $id));
    $wpdb->query('DELETE FROM ' . Installer::events_table() . ' WHERE participation_id = ' . (int) $id);
}
$wpdb->queries = array();
$archived = Privacy::run(2, static fn () => Scores::scores_for_activity($settled, array(2), true));
score_query_expect('archived', $archived[2]['data_status'], 'Forced refresh preserves archived source status');
score_query_expect((float) $archived_value, $archived[2]['score_total'], 'Forced refresh never reconstructs a removed raw score');
score_query_expect(0, score_query_count('/^SELECT participation_id, event_type, occurred_at_ms/'), 'An archived batch never attempts removed raw reads');
score_query_expect(0, score_query_writes(), 'An archived batch never overwrites its frozen snapshot');

$wpdb->query('START TRANSACTION');
Privacy::lock_for_erasure(61); Privacy::set_state(61, 'open'); $wpdb->query('COMMIT');
$privacy = Privacy::run(61, static fn () => Scores::scores_for_activity($settled, array(61)));
score_query_expect('unavailable', $privacy[61]['data_status'], 'A fresh privacy cutoff wins over preloaded historical metadata');

$large_roster = range(2, 102);
platform_fixture_connect_runtime(1, $large_roster);
$wpdb->queries = array();
$chunked = Privacy::run_many($large_roster, static fn () => Scores::scores_for_activity($activity, $large_roster));
score_query_expect(101, count($chunked), 'Rosters beyond a hundred learners retain every requested result');
score_query_expect(2, score_query_count('/^SELECT p\.\* FROM wp_focus_tracker_participations p/'), 'A roster beyond a hundred uses bounded participation chunks');
score_query_expect(2, score_query_count('/^SELECT \* FROM wp_focus_tracker_scores WHERE activity_id/'), 'A roster beyond a hundred uses bounded snapshot chunks');
score_query_expect(2, score_query_count('/^SELECT option_name, option_value FROM wp_options WHERE option_name IN/'), 'A roster beyond a hundred uses bounded privacy chunks');
score_query_expect('no_data', $chunked[102]['data_status'], 'A learner without source remains no-data rather than receiving another learner score');
$GLOBALS['score_query_primed_users'] = array();
$large_response = Controller::activity_scores(new WP_REST_Request(array('id' => $activity_id)));
score_query_expect(true, $large_response instanceof WP_REST_Response, 'The authorized larger roster remains readable');
score_query_expect(array(array_slice($large_roster, 0, 100), array_slice($large_roster, 100)), $GLOBALS['score_query_primed_users'], 'WordPress user cache priming is bounded to a hundred authorized IDs per call');

$wpdb->queries = array();
score_query_expect(array(), Scores::scores_for_activity($activity, array()), 'An empty roster returns an empty result');
score_query_expect(0, count($wpdb->queries), 'An empty roster issues no queries');

echo "Score query performance: {$checks} SQL-backed checks passed; roster source reads {$single_source_reads} -> 3; provisional score writes 0\n";
