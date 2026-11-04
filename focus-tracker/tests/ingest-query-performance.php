<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';
platform_fixture_connect_runtime();

use FocusTracker\Database\Activity_Repository as Activities;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository as Participations;
use FocusTracker\Database\Score_Repository as Snapshots;
use FocusTracker\Privacy\Privacy_Guard as Privacy;
use FocusTracker\Rest\Controllers\Events_Controller as Ingest;
use FocusTracker\Scoring\Activity_Score_Service as Scores;
use FocusTracker\Support\Clock;

$checks = 0;
function iqp_check(bool $passed, string $label): void {
    global $checks, $wpdb;
    ++$checks; if (! $passed) { throw new RuntimeException($label . ': ' . $wpdb->last_error); }
}
function iqp_count(string $pattern): int {
    global $wpdb;
    return count(array_filter($wpdb->queries, static fn (string $sql): bool => preg_match($pattern, $sql) === 1));
}
function iqp_post(array $payload): WP_REST_Response {
    $response = Ingest::handle(new WP_REST_Request($payload));
    iqp_check($response instanceof WP_REST_Response && $response->data['acknowledged'], 'Genuine payload is acknowledged');
    return $response;
}
function iqp_rows(string $table): array { global $wpdb; return $wpdb->get_results('SELECT * FROM ' . $table . ' ORDER BY id', ARRAY_A); }

$now = intdiv(Clock::now_ms(), 1000) * 1000;
$start = $now - 60000;
$activity = static fn (string $name, int $end): int => Activities::create(array('name' => $name, 'owner_user_id' => 1,
    'context_type' => 'class', 'context_id' => 'class:1', 'problem_ids' => array(123),
    'starts_at' => Clock::ms_to_mysql($start - 1000), 'ends_at' => Clock::ms_to_mysql($end),
    'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
$a = $activity('Overlapping A', $now + 60000); $b = $activity('Overlapping B', $now + 120000);
$packet = array('user_id' => 1, 'session_uid' => 'query-cost-source', 'session_started_at' => $start, 'problem_id' => 123,
    'events' => array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $start, 'meta' => array('visible' => true, 'focused' => true)),
        array('seq' => 2, 'type' => 'heartbeat', 'occurred_at' => $start + 15000)));
$first = iqp_post($packet); $id = (int) $first->data['participation_id'];
$source = Participations::find($id); $source_rows = iqp_rows(Installer::events_table());
$wpdb->queries = array();
$replay = iqp_post($packet);
iqp_check($replay->data['stored'] === 0 && $replay->data['participation_ids'] === array($id), 'Pure replay keeps the original source identity');
iqp_check(iqp_count('/^(?:INSERT INTO|UPDATE|DELETE FROM) wp_focus_tracker_/') === 0, 'Pure replay writes no source, link, or score table');
iqp_check(iqp_count('/^SELECT .*FROM wp_focus_tracker_scores/s') === 0, 'Unchanged replay does not even read score invalidations');
iqp_check(Participations::find($id) === $source && iqp_rows(Installer::events_table()) === $source_rows, 'Replay preserves every source field and event byte');
iqp_check(iqp_count('/^SELECT participation_id, activity_id FROM wp_focus_tracker_activity_links/') === 1, 'All existing links are read once for the packet');

Scores::score_for_learner(Activities::find($a), 1, true, true);
Scores::score_for_learner(Activities::find($b), 1, true, true);
Scores::score_for_learner(Activities::find($a), 2, true, true);
$wpdb->query('UPDATE ' . Installer::scores_table() . ' SET finalized=1, raw_events_expired=1 WHERE activity_id=' . $b . ' AND user_id=1');
$archived = Snapshots::get($b, 1); $other_user = Snapshots::get($a, 2);
$packet['events'] = array(array('seq' => 3, 'type' => 'heartbeat', 'occurred_at' => $now - 15000));
$wpdb->queries = array();
$fresh = iqp_post($packet);
iqp_check($fresh->data['stored'] === 1, 'Exactly one new heartbeat persists');
iqp_check(iqp_count('/^UPDATE wp_focus_tracker_participations SET/') === 1, 'Heartbeat, revision, and deadline use at most one metadata update');
iqp_check(iqp_count('/^INSERT INTO wp_focus_tracker_activity_links/') === 0, 'Existing overlap links are never reinserted');
iqp_check(iqp_count('/^DELETE FROM wp_focus_tracker_scores WHERE id =/') === 1, 'Only the existing nonarchived score primary key is deleted');
iqp_check(iqp_count('/^DELETE FROM wp_focus_tracker_scores WHERE (?:user_id|activity_id)/') === 0, 'Invalidation never takes an absent score range write lock');
iqp_check(Snapshots::get($a, 1) === null && Snapshots::get($b, 1) === $archived && Snapshots::get($a, 2) === $other_user, 'Only mutable target snapshots are invalidated');
$after = Participations::find($id);
iqp_check($after['event_revision'] === $source['event_revision'] + 1 && $after['last_heartbeat_at_ms'] === $now - 15000, 'Revision and heartbeat retain exact values');

$wpdb->queries = array(); iqp_post($packet);
iqp_check(iqp_count('/^(?:INSERT INTO|UPDATE|DELETE FROM) wp_focus_tracker_/') === 0, 'Replay with an absent live cache still performs zero table writes');
$revision = Participations::find($id)['event_revision'];
$packet['is_final'] = true;
$wpdb->queries = array(); iqp_post($packet);
iqp_check(iqp_count('/^UPDATE wp_focus_tracker_participations SET/') === 1, 'A new final flag updates metadata despite zero newly inserted events');
$final = Participations::find($id);
iqp_check($final['ended_at_ms'] === $now - 15000 && $final['end_reason'] === Participations::END_REASON_PAGEHIDE && $final['event_revision'] === $revision, 'Final-only retry clips the end without inventing a new event revision');

$packet['is_final'] = false; $packet['session_uid'] = 'changed-deadline';
$deadline_id = (int) iqp_post($packet)->data['participation_id'];
Activities::update($a, array('ends_at' => Clock::ms_to_mysql($now + 30000)));
Activities::update($b, array('ends_at' => Clock::ms_to_mysql($now + 30000)));
$wpdb->queries = array(); iqp_post($packet);
iqp_check(iqp_count('/^UPDATE wp_focus_tracker_participations SET/') === 1 && Participations::find($deadline_id)['ended_at_ms'] === $now + 30000, 'Duplicate payload still reconciles a changed teacher deadline');
$before_failure = iqp_rows(Installer::events_table()); $before_meta = Participations::find($deadline_id);
$packet['events'] = array(array('seq' => 4, 'type' => 'heartbeat', 'occurred_at' => $now - 1000));
$wpdb->fail_pattern = 'UPDATE wp_focus_tracker_participations SET';
$failed = Ingest::handle(new WP_REST_Request($packet));
iqp_check($failed instanceof WP_Error && $failed->data['status'] === 503, 'Metadata write failure never acknowledges the new observation');
iqp_check(iqp_rows(Installer::events_table()) === $before_failure && Participations::find($deadline_id) === $before_meta, 'Failed combined metadata update rolls back the inserted event and every source field');

// Batch watermarks are fresh and fail closed; they never replace the guard lock.
$privacy_key = static fn (int $user): string => 'focus_tracker_privacy_' . hash_hmac('sha256', (string) $user, wp_salt('auth'));
foreach (array(81 => 'open:123', 82 => 'erasing:456', 83 => 'deleted', 84 => 'invalid') as $user => $value) { update_option($privacy_key($user), $value, false); }
$wpdb->queries = array(); $cutoffs = Privacy::cutoffs(array(81, 82, 83, 84, 85, 81, 0));
iqp_check($cutoffs === array(81 => 123, 82 => 456, 83 => 0, 84 => PHP_INT_MAX, 85 => 0), 'Batch watermark parsing preserves single-reader semantics and unique positive learners');
iqp_check(iqp_count('/^SELECT option_name, option_value FROM wp_options WHERE option_name IN/') === 1, 'A learner batch uses one watermark read');
update_option($privacy_key(81), 'open:789', false);
iqp_check(Privacy::cutoffs(array(81))[81] === 789, 'Another call immediately sees a changed erasure watermark');
$wpdb->queries = array(); iqp_check(Privacy::cutoffs(array()) === array() && $wpdb->queries === array(), 'An empty watermark batch runs no query');
$wpdb->queries = array();
$wpdb->before_results = static function (string $sql) use ($wpdb): void {
    if (str_starts_with($sql, 'SELECT option_name, option_value FROM wp_options WHERE option_name IN')) {
        $wpdb->before_results = null; throw new RuntimeException('Injected watermark read failure');
    }
};
$cutoffs = Privacy::cutoffs(range(1000, 1200));
iqp_check(count(array_filter(array_slice($cutoffs, 0, 100), static fn (int $value): bool => $value === PHP_INT_MAX)) === 100, 'A failed watermark chunk cannot authorize any learner in that chunk');
iqp_check(count($cutoffs) === 201 && iqp_count('/^SELECT option_name, option_value FROM wp_options WHERE option_name IN/') === 3, 'Large watermark reads are bounded to 100 learners per SQL statement');

echo "Ingest query performance: {$checks} SQL-backed checks passed; pure replay source writes 0; combined metadata update 1\n";
