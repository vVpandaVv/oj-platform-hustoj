<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';

use FocusTracker\Admin\Settings_Page;
use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Check_Repository;
use FocusTracker\Database\Event_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Database\Score_Repository;
use FocusTracker\Database\Statistics_Repository;
use FocusTracker\Jobs\Retention_Cleanup as Cleanup;
use FocusTracker\Privacy\Privacy_Guard;
use FocusTracker\Reporting\Statistics_Service as Statistics;
use FocusTracker\Rest\Controllers\Statistics_Controller;
use FocusTracker\Rest\Controllers\Scores_Controller;
use FocusTracker\Scoring\Activity_Score_Service as Scoring;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;

$checks = 0;
function retention_expect(mixed $expected, mixed $actual, string $label): void {
    global $checks, $wpdb;
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ' SQL: ' . $wpdb->last_error); }
}
function retention_assert(bool $value, string $label): void { retention_expect(true, $value, $label); }
function retention_session(int $user, int $at, string $uid, ?int $activity = null): array {
    $p = Participation_Repository::find_or_create(array('activity_id' => $activity, 'user_id' => $user, 'problem_id' => 123, 'session_uid' => $uid, 'started_at_ms' => $at));
    $rows = array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $at, 'meta' => array('visible' => true, 'focused' => true)),
        array('seq' => 2, 'type' => 'activity', 'occurred_at' => $at + 1000), array('seq' => 3, 'type' => 'session_end', 'occurred_at' => $at + 20000));
    if (false === Event_Repository::insert_batch($p['id'], $rows)) { throw new RuntimeException('Fixture events failed.'); }
    Participation_Repository::touch_heartbeat($p['id'], $at + 20000);
    Participation_Repository::close($p['id'], $at + 20000, 'pagehide');
    Participation_Repository::record_event_revision($p['id'], 3);
    return Participation_Repository::find($p['id']);
}
function retention_question(int $user, int $at): array {
    return Check_Repository::create(array('user_id' => $user, 'problem_id' => 123, 'submission_id' => null, 'context_type' => 'general', 'context_id' => null,
        'problem_hash' => str_repeat('a', 64), 'source_kind' => 'problem', 'source_hash' => str_repeat('b', 64), 'candidate_json' => '{}',
        'issued_at_ms' => $at, 'expires_at_ms' => $at + 600000, 'status' => 'unknown'));
}
function retention_raw_count(int $id): int { global $wpdb; return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id = ' . $id); }
function retention_continue(): void { wp_clear_scheduled_hook('focus_tracker_cleanup_continue'); Cleanup::run(); }

update_option('focus_tracker_settings', array('retention_days' => 90));
retention_expect(90, Settings::get('check_retention_days'), 'Legacy single retention setting remains the check-retention fallback');
retention_expect(0, Settings::get('aggregate_retention_days'), 'Existing sites retain aggregates indefinitely by default');
update_option('focus_tracker_settings', Settings::defaults());
retention_expect(60, Settings::get('retention_days'), 'Default raw retention remains sixty days');
retention_expect(60, Settings::get('check_retention_days'), 'Default check retention remains sixty days');
foreach (array(array('retention_days' => 0), array('check_retention_days' => '2.5'), array('aggregate_retention_days' => 60), array('aggregate_retention_days' => -1), array('retention_days' => 36501)) as $invalid) {
    $before = Settings::all();
    try { Settings::update($invalid); $thrown = false; } catch (InvalidArgumentException $error) { $thrown = true; }
    retention_assert($thrown && $before === Settings::all(), 'Invalid or unsafe retention is rejected without changing any setting');
}
Settings::update(array('aggregate_retention_days' => 61));
retention_expect(61, Settings::get('aggregate_retention_days'), 'A finite aggregate period may exceed both source periods by one day');
Settings::update(array('aggregate_retention_days' => 0));
$save = new ReflectionMethod(Settings_Page::class, 'save');
$before = Settings::all();
retention_assert(is_string($save->invoke(null, array('retention_days' => 'abc'))), 'The actual settings save handler reports invalid input');
retention_expect($before, Settings::all(), 'Invalid form submission does not partially change settings');
retention_expect(null, $save->invoke(null, array('retention_days' => '45', 'check_retention_days' => '30', 'aggregate_retention_days' => '90')), 'The actual save handler accepts independently valid periods');
retention_expect(45, Settings::get('retention_days'), 'Raw form value is saved as an integer');
retention_expect(30, Settings::get('check_retention_days'), 'Check form value is independently saved');
retention_expect(90, Settings::get('aggregate_retention_days'), 'Aggregate form value is independently saved');
Settings::update(Settings::defaults());

$today = (new DateTimeImmutable('now', wp_timezone()))->setTime(0, 0);
$oldDay = $today->modify('-70 days');
$old = $oldDay->setTime(10, 0)->getTimestamp() * 1000;
$failedSource = retention_session(900, $old, 'cleanup-front-failure');
for ($i = 0; $i < 120; ++$i) { retention_session(901, $old + $i * 60000, 'cleanup-raw-' . $i); }
for ($i = 0; $i < 120; ++$i) { retention_question(902, $old + $i); }
$wpdb->fail_pattern = 'INSERT INTO ' . Installer::statistics_table();
Cleanup::run();
$first = get_option(Cleanup::STATUS_OPTION);
retention_expect(100, $first['counts']['rawAttempted'], 'A raw batch has a hard one-hundred-unit bound');
retention_expect(297, $first['counts']['rawPurged'], 'A failed front source does not block ninety-nine later successful sources');
retention_expect(100, $first['counts']['checkPurged'], 'Checks have an independent bounded batch');
retention_expect(22, $first['backlog']['raw'], 'Backlog includes the deferred front source and remaining tail');
retention_expect(20, $first['backlog']['checks'], 'Check backlog is visible after the first batch');
retention_expect(1, $first['counts']['failed'], 'The preserved failed unit is reported');
retention_expect('deferred', $first['lastOutcome'], 'A partial failure is operationally visible');
retention_expect(3, retention_raw_count($failedSource['id']), 'Snapshot failure preserves its original raw evidence');
retention_assert($first['retry']['scheduledAtMs'] >= Clock::now_ms() && $first['retry']['scheduledAtMs'] <= Clock::now_ms() + 31000, 'A fresh cursor schedules a near-term continuation');
retention_assert(is_int($first['oldest']['rawAtMs']) && is_int($first['oldest']['checkAtMs']), 'Oldest retained backlog timestamps are available');
retention_assert(! isset($first['cursor']) && ! str_contains(json_encode($first), 'user_id') && ! str_contains(json_encode($first), 'session_uid'), 'Operational status contains no learner identity or internal row cursor');
$progress = get_option('focus_tracker_cleanup_progress');
retention_assert($progress['raw'] > 0 && $progress['checks'] > 0, 'Separate internal cursors survive the first request');
retention_continue();
$second = get_option(Cleanup::STATUS_OPTION);
retention_expect(21, $second['counts']['rawAttempted'], 'The continuation starts beyond the previous raw cursor');
retention_expect(20, $second['counts']['checkPurged'], 'More than one hundred questions are drained by continuation');
retention_expect(1, $second['backlog']['raw'], 'A failed earlier unit remains available for a later retry pass');
retention_expect(0, $second['backlog']['checks'], 'Check tail is completely processed');
retention_assert($second['retry']['scheduledAtMs'] >= Clock::now_ms() + 298000, 'Completing a pass defers preserved failures instead of a busy retry loop');
retention_continue();
$third = get_option(Cleanup::STATUS_OPTION);
retention_expect(0, $third['backlog']['raw'], 'A later retry successfully processes the original failed source');
retention_expect(0, retention_raw_count($failedSource['id']), 'Recovered snapshot storage permits raw deletion');
retention_expect('complete', $third['lastOutcome'], 'The drain finishes with a complete state');
retention_expect(false, wp_next_scheduled('focus_tracker_cleanup_continue'), 'No empty continuation remains scheduled');
retention_assert(is_int($third['lastSuccessAtMs']) && $third['retry']['consecutiveFailures'] === 0, 'Successful recovery updates health without retaining a false failure count');
retention_assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::statistics_table()) > 0, 'Default unlimited aggregate retention preserves daily snapshots');
retention_assert(Participation_Repository::find($failedSource['id']) !== null, 'Default unlimited aggregate retention also preserves source provenance');
$archived = Statistics::day(902, array('type' => 'self', 'id' => ''), $oldDay, $oldDay->modify('+1 day')->getTimestamp() * 1000, Clock::now_ms(), false);
retention_expect(120, $archived['checks']['unknown'], 'Question batches preserve the original full daily count after all sources are removed');

$futureLock = wp_json_encode(array('token' => 'other-worker', 'expiresAtMs' => Clock::now_ms() + 60000));
add_option('focus_tracker_cleanup_lock', $futureLock);
Cleanup::run();
retention_expect($third, get_option(Cleanup::STATUS_OPTION), 'A concurrent worker cannot enter or overwrite the running job state');
retention_assert(wp_next_scheduled('focus_tracker_cleanup_continue') >= time() + 124, 'A busy lock leaves a recovery wake-up instead of losing the only continuation');
delete_option('focus_tracker_cleanup_lock');
$expiredLock = wp_json_encode(array('token' => 'expired-worker', 'expiresAtMs' => Clock::now_ms() - 1));
add_option('focus_tracker_cleanup_lock', $expiredLock);
Cleanup::run();
retention_expect(false, get_option('focus_tracker_cleanup_lock', false), 'An expired lock can be atomically recovered and released');
$replacement = wp_json_encode(array('token' => 'replacement-worker', 'expiresAtMs' => Clock::now_ms() + 60000));
add_option('focus_tracker_cleanup_lock', $replacement);
$release = new ReflectionMethod(Cleanup::class, 'release_lock');
retention_expect(false, $release->invoke(null, $expiredLock), 'An old owner cannot remove a replacement lock');
retention_expect($replacement, get_option('focus_tracker_cleanup_lock'), 'Conditional lock release preserves the new owner');
delete_option('focus_tracker_cleanup_lock');

$middle = $today->modify('-30 days')->setTime(10, 0)->getTimestamp() * 1000;
Settings::update(array('retention_days' => 10, 'check_retention_days' => 60));
$rawShort = retention_session(910, $middle, 'cleanup-short-raw');
$checkLong = retention_question(910, $middle);
retention_continue();
retention_expect(0, retention_raw_count($rawShort['id']), 'Short raw retention removes raw evidence after saving aggregates');
retention_assert(Check_Repository::find($checkLong['id'], 910) !== null, 'Long check retention preserves the same-day question independently');
Settings::update(array('retention_days' => 60, 'check_retention_days' => 10));
$rawLong = retention_session(911, $middle, 'cleanup-long-raw');
$checkShort = retention_question(911, $middle);
retention_continue();
retention_expect(3, retention_raw_count($rawLong['id']), 'Long raw retention preserves evidence independently of question expiry');
retention_expect(null, Check_Repository::find($checkShort['id'], 911), 'Short check retention removes the question after its aggregate is safe');
Event_Repository::insert_batch($rawLong['id'], array(array('seq' => 4, 'type' => 'activity', 'occurred_at' => $middle + 2000)));
Participation_Repository::record_event_revision($rawLong['id'], 1);
$middleDay = $today->modify('-30 days');
$refreshed = Statistics::day(911, array('type' => 'self', 'id' => ''), $middleDay, $middleDay->modify('+1 day')->getTimestamp() * 1000, Clock::now_ms(), false);
retention_expect(2, $refreshed['editCount'], 'Available raw observations may still be recalculated after shorter check retention');
retention_expect(1, $refreshed['checks']['unknown'], 'Recalculating raw observations cannot erase already archived question totals');
$partial = Statistics::day(911, array('type' => 'self', 'id' => ''), $middleDay, $middle + 10000, Clock::now_ms(), false);
retention_expect(null, $partial['checks']['unknown'], 'A full-day archived question total cannot invent partial-day question distribution');
retention_expect(true, $partial['coverageUnknown'], 'An unavailable partial question distribution is explicitly marked incomplete');
$GLOBALS['focus_test_timezone'] = 'UTC';
$utcDay = (new DateTimeImmutable('@' . intdiv($middle, 1000)))->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
$rezoned = Statistics::day(911, array('type' => 'self', 'id' => ''), $utcDay, $utcDay->modify('+1 day')->getTimestamp() * 1000, Clock::now_ms(), false);
retention_expect(null, $rezoned['checks']['unknown'], 'Changing the site timezone cannot turn deleted questions into a known zero count');
retention_expect(true, $rezoned['coverageUnknown'], 'A timezone change cannot invent the within-day distribution of archived questions');
$GLOBALS['focus_test_timezone'] = 'Asia/Seoul';

Settings::update(Settings::defaults());
$activityId = Activity_Repository::create(array('name' => '보관 시험', 'owner_user_id' => 1, 'context_type' => 'class', 'context_id' => 'class:1', 'problem_ids' => array(123),
    'starts_at' => Clock::ms_to_mysql($old), 'ends_at' => Clock::ms_to_mysql($old + 20000), 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
$protected = retention_session(920, $old, 'cleanup-protected-score', $activityId);
Participation_Repository::link_activity($protected['id'], $activityId);
$activity = Activity_Repository::find($activityId);
retention_assert(Scoring::score_for_learner($activity, 920)['cache_saved'], 'A pre-existing activity snapshot is available for aggregate retention');
Statistics::day(920, array('type' => 'self', 'id' => ''), $oldDay, $oldDay->modify('+1 day')->getTimestamp() * 1000, Clock::now_ms(), false);
Event_Repository::insert_batch($protected['id'], array(array('seq' => 99, 'type' => 'activity', 'occurred_at' => $old + 15000)));
Participation_Repository::record_event_revision($protected['id'], 1);
Settings::update(array('retention_days' => 10, 'check_retention_days' => 20, 'aggregate_retention_days' => 21));
$wpdb->fail_pattern = 'INSERT INTO ' . Installer::scores_table();
retention_continue();
retention_expect(4, retention_raw_count($protected['id']), 'Finite aggregate retention cannot discard evidence whose final activity snapshot failed');
retention_assert(Score_Repository::get($activityId, 920) !== null, 'An old activity aggregate remains protected while its raw source is deferred');
retention_assert(Statistics_Repository::get(920, array('type' => 'self', 'id' => ''), $oldDay->format('Y-m-d'), wp_timezone()->getName()) !== null, 'An old daily aggregate remains protected while its raw source is deferred');
retention_continue();
retention_expect(0, retention_raw_count($protected['id']), 'A later successful retry can safely expire the deferred raw source');
retention_expect(null, Score_Repository::get($activityId, 920), 'Finite retention removes an old activity aggregate after source cleanup completes');
retention_expect(null, Statistics_Repository::get(920, array('type' => 'self', 'id' => ''), $oldDay->format('Y-m-d'), wp_timezone()->getName()), 'Finite retention removes the protected daily aggregate only after source cleanup');
$expiredDay = Statistics::day(920, array('type' => 'self', 'id' => ''), $oldDay, $oldDay->modify('+1 day')->getTimestamp() * 1000, Clock::now_ms(), false);
retention_expect('unavailable', $expiredDay['status'], 'Ordinary history reads cannot regenerate an expired daily aggregate');
retention_expect('unavailable', Scoring::score_for_learner($activity, 920)['data_status'], 'Ordinary score reads cannot regenerate an expired activity aggregate');
retention_expect(null, Score_Repository::get($activityId, 920), 'Expired activity reads do not recreate database rows');
retention_expect(null, Statistics_Repository::get(920, array('type' => 'self', 'id' => ''), $oldDay->format('Y-m-d'), wp_timezone()->getName()), 'Expired daily reads do not recreate database rows');
retention_expect(null, Participation_Repository::find($protected['id']), 'Finite aggregate retention also removes expired participation provenance');
retention_expect(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::activity_links_table() . ' WHERE participation_id = ' . $protected['id']), 'Expired provenance cleanup removes only its own activity links');

$privacyRaw = retention_session(930, $old, 'cleanup-privacy-erasing');
$privacyCheck = retention_question(930, $old);
$wpdb->query('START TRANSACTION');
retention_assert(Privacy_Guard::lock_for_erasure(930) && Privacy_Guard::set_state(930, 'erasing'), 'Privacy erasure state is persisted under its user lock');
$wpdb->query('COMMIT');
retention_continue();
retention_expect(3, retention_raw_count($privacyRaw['id']), 'Retention defers raw archiving between privacy erasure pages');
retention_assert(Check_Repository::find($privacyCheck['id'], 930) !== null, 'Retention defers question archiving between erasure pages');
retention_assert(get_option(Cleanup::STATUS_OPTION)['counts']['failed'] >= 2, 'Deferred privacy-bound work remains visible in cleanup health');
$GLOBALS['focus_test_user_id'] = 930;
$privacyReport = Statistics_Controller::self_statistics(new WP_REST_Request(array(), array('period' => 'week', 'date' => $oldDay->format('Y-m-d'))));
retention_assert($privacyReport instanceof WP_Error && $privacyReport->get_error_data()['status'] === 423, 'Statistics cannot recreate derived rows while personal erasure is active');
retention_expect(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::statistics_table() . ' WHERE user_id = 930'), 'A blocked statistics read persists no new personal aggregate');
$GLOBALS['focus_test_user_id'] = 1;
$wpdb->query('START TRANSACTION');
Privacy_Guard::lock_for_erasure(930);
Privacy_Guard::set_state(930, 'open');
$wpdb->query('COMMIT');
retention_continue();
retention_expect(3, retention_raw_count($privacyRaw['id']), 'A new erasure cutoff cannot turn stale pre-erasure sources into valid archived evidence');

// Model an erasure completed two days ago, then fresh learning on that day.
$erasureDay = $today->modify('-2 days');
$erasureAt = $erasureDay->setTime(10, 0)->getTimestamp() * 1000;
$privacyKey = (new ReflectionMethod(Privacy_Guard::class, 'key'))->invoke(null, 940);
add_option($privacyKey, 'open:' . $erasureAt);
$priorDay = $erasureDay->modify('-1 day');
$deletedDay = Statistics::day(940, array('type' => 'self', 'id' => ''), $priorDay, $priorDay->modify('+1 day')->getTimestamp() * 1000, Clock::now_ms(), false);
retention_expect('unavailable', $deletedDay['status'], 'A fully erased date is unavailable rather than observed no-data');
retention_expect(true, $deletedDay['coverageUnknown'], 'Erased history explicitly carries unknown coverage');
$oldActivityId = Activity_Repository::create(array('name' => '삭제 경계 활동', 'owner_user_id' => 1, 'context_type' => 'class', 'context_id' => 'class:1', 'problem_ids' => array(123),
    'starts_at' => Clock::ms_to_mysql($erasureAt - 30000), 'ends_at' => Clock::ms_to_mysql($erasureAt + 30000), 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
$freshAfterErase = retention_session(940, $erasureAt + 1000, 'cleanup-after-erasure', $oldActivityId);
$boundaryDay = Statistics::day(940, array('type' => 'self', 'id' => ''), $erasureDay, $erasureDay->modify('+1 day')->getTimestamp() * 1000, Clock::now_ms(), false);
retention_expect('incomplete', $boundaryDay['status'], 'New learning on an erasure-boundary day does not imply complete old coverage');
retention_expect(null, $boundaryDay['focusRatio'], 'An erasure-boundary day cannot claim a precise full-day ratio');
retention_expect(20.0, $boundaryDay['activeSeconds'], 'Valid post-erasure observations still accumulate');
$oldActivity = Activity_Repository::find($oldActivityId);
$oldScore = Scoring::score_for_learner($oldActivity, 940);
retention_assert($oldScore['data_status'] === 'unavailable' && $oldScore['needs_review'] && $oldScore['has_observation_gaps'], 'An activity crossing the erasure cutoff never becomes a low but seemingly valid grade');
$retentionScore = Scoring::score_for_learner($oldActivity, 940, true, true);
retention_assert($retentionScore['cache_saved'] && $retentionScore['has_observation_gaps'] && $retentionScore['needs_review'], 'Retention may save an explicitly incomplete internal snapshot without blocking source preservation');
retention_expect(1, (int) Score_Repository::get($oldActivityId, 940)['has_observation_gaps'], 'Exported stored metrics retain the erased-evidence quality flag');
$wpdb->query('START TRANSACTION');
$safeBoundaryArchive = Statistics::archive_participation($freshAfterErase);
$wpdb->query($safeBoundaryArchive ? 'COMMIT' : 'ROLLBACK');
retention_assert($safeBoundaryArchive, 'Known intentional erasure gaps do not permanently block post-erasure daily archiving');
$GLOBALS['focus_test_user_id'] = 940;
$timeline = Scores_Controller::timeline(new WP_REST_Request(array(), array('id' => $oldActivityId, 'user_id' => 940)));
retention_expect('unavailable', $timeline->data['dataStatus'], 'The timeline shares erasure-aware score quality');
retention_expect(array(), $timeline->data['spans'], 'Erased intervals are not rendered as a red inactivity timeline');
retention_expect(array(), $timeline->data['markers'], 'Unavailable erased activity has no misleading timeline markers');
$GLOBALS['focus_test_user_id'] = 1;
$newActivityId = Activity_Repository::create(array('name' => '삭제 후 새 활동', 'owner_user_id' => 1, 'context_type' => 'class', 'context_id' => 'class:1', 'problem_ids' => array(123),
    'starts_at' => Clock::ms_to_mysql($erasureAt + 60000), 'ends_at' => Clock::ms_to_mysql($erasureAt + 80000), 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
retention_session(940, $erasureAt + 60000, 'cleanup-new-activity-after-erasure', $newActivityId);
retention_expect('complete', Scoring::score_for_learner(Activity_Repository::find($newActivityId), 940)['data_status'], 'A new activity starting after erasure can be scored normally');
$privacyPeriod = Statistics::report(940, 'week', $erasureDay->format('Y-m-d'));
retention_expect(null, $privacyPeriod['delta']['focusRatio'], 'Erased previous periods cannot imply an improvement or decline');

// Metadata belongs to a single participation. Recent peers and a still-live
// shared activity keep their provenance even when another row expires.
$liveActivityId = Activity_Repository::create(array('name' => '긴 활동', 'owner_user_id' => 1, 'context_type' => 'class', 'context_id' => 'class:1', 'problem_ids' => array(123),
    'starts_at' => Clock::ms_to_mysql($old), 'ends_at' => Clock::ms_to_mysql(Clock::now_ms() + DAY_IN_SECONDS * 1000), 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
$liveMetadata = retention_session(950, $old, 'cleanup-live-provenance', $liveActivityId);
$recentMetadata = retention_session(951, Clock::now_ms() - 5 * DAY_IN_SECONDS * 1000, 'cleanup-recent-peer', $activityId);
$expiredMetadata = retention_session(952, $old, 'cleanup-expired-provenance', $activityId);
foreach (array($liveMetadata, $recentMetadata, $expiredMetadata) as $p) {
    $wpdb->delete(Installer::events_table(), array('participation_id' => $p['id']));
    $wpdb->update(Installer::participations_table(), array('raw_events_expired' => 1), array('id' => $p['id']));
    Participation_Repository::link_activity($p['id'], (int) $p['activity_id']);
}
retention_continue();
retention_expect(null, Participation_Repository::find($expiredMetadata['id']), 'Expired standalone provenance is removed in its bounded metadata stage');
retention_assert(Participation_Repository::find($recentMetadata['id']) !== null, 'A recent peer participation is preserved');
retention_assert(Participation_Repository::find($liveMetadata['id']) !== null, 'A long activity still needing the old source preserves provenance');
retention_expect(1, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::activity_links_table() . ' WHERE participation_id = ' . $recentMetadata['id']), 'Peer activity links survive another participation cleanup');
retention_assert(Activity_Repository::find($liveActivityId) !== null && Activity_Repository::find($activityId) !== null, 'Shared schedule metadata is never deleted by aggregate retention');
retention_assert(get_option(Cleanup::STATUS_OPTION)['backlog']['metadata'] >= 1, 'Protected metadata remains visible in backlog diagnostics');

Cleanup::maybe_schedule();
retention_assert(wp_next_scheduled('focus_tracker_cleanup') !== false, 'The daily safety sweep remains scheduled');
Cleanup::unschedule();
retention_expect(false, wp_next_scheduled('focus_tracker_cleanup'), 'Deactivation removes the daily hook');
retention_expect(false, wp_next_scheduled('focus_tracker_cleanup_continue'), 'Deactivation also removes continuation hooks');
echo "Retention cleanup: {$checks} SQL-backed checks passed\n";
