<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';

use FocusTracker\Database\Activity_Repository as Activities;
use FocusTracker\Database\Event_Repository as Events;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository as Participations;
use FocusTracker\Database\Score_Repository as Scores;
use FocusTracker\Jobs\Retention_Cleanup as Cleanup;
use FocusTracker\Reporting\Statistics_Service as Statistics;
use FocusTracker\Rest\Controllers\Activities_Controller as Api;
use FocusTracker\Rest\Controllers\Events_Controller as Ingest;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;
use FocusTracker\Tracking\Activity_Window_Gate as Gate;

$checks = 0;
function deletion_expect(mixed $expected, mixed $actual, string $label): void {
    global $checks, $wpdb;
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ' SQL: ' . $wpdb->last_error); }
}
function deletion_assert(bool $value, string $label): void { deletion_expect(true, $value, $label); }
function deletion_activity(string $name, int $from, int $to): int {
    $id = Activities::create(array('name' => $name, 'owner_user_id' => 1, 'context_type' => 'class', 'context_id' => 'class:1', 'problem_ids' => array(123),
        'starts_at' => Clock::ms_to_mysql($from), 'ends_at' => Clock::ms_to_mysql($to), 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
    if ($id === null) { throw new RuntimeException('Activity fixture failed.'); }
    return $id;
}
function deletion_source(int $user, int $start, int $seconds, string $uid, array $ids, ?int $hard_end = null): array {
    $p = Participations::find_or_create(array('activity_id' => null, 'user_id' => $user, 'problem_id' => 123, 'session_uid' => $uid, 'started_at_ms' => $start));
    $rows = array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $start, 'meta' => array('visible' => true, 'focused' => true)));
    $seq = 2;
    for ($at = $start + 15000; $at < $start + $seconds * 1000; $at += 15000) { $rows[] = array('seq' => $seq++, 'type' => 'heartbeat', 'occurred_at' => $at); }
    $rows[] = array('seq' => $seq++, 'type' => 'activity', 'occurred_at' => $start + 1000);
    if ($hard_end === null) { $rows[] = array('seq' => $seq, 'type' => 'session_end', 'occurred_at' => $start + $seconds * 1000); }
    if (Events::insert_batch($p['id'], $rows) === false) { throw new RuntimeException('Source fixture failed.'); }
    Participations::touch_heartbeat($p['id'], $start + $seconds * 1000);
    Participations::close($p['id'], $hard_end ?? $start + $seconds * 1000, 'activity_window');
    Participations::record_event_revision($p['id'], count($rows));
    foreach ($ids as $id) { Participations::link_activity($p['id'], $id); }
    return Participations::find($p['id']);
}
function deletion_delete(int $id): void {
    $result = Api::delete(new WP_REST_Request(array(), array('id' => $id, '_wpnonce' => 'focus-test-nonce')));
    deletion_assert($result instanceof WP_REST_Response && $result->data['deleted'] === true, 'The real activity delete controller succeeds');
}
function deletion_day(int $user, DateTimeImmutable $day, string $scope = 'self', ?int $through = null): array {
    return Statistics::day($user, array('type' => $scope, 'id' => $scope === 'self' ? '' : 'class:1'), $day,
        $through ?? $day->modify('+1 day')->getTimestamp() * 1000, Clock::now_ms(), false);
}
function deletion_raw(int $id): int { global $wpdb; return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id = ' . $id); }

platform_fixture_connect_runtime(1, array(1, 2, 3));
$GLOBALS['focus_test_user_id'] = 1;
$today = (new DateTimeImmutable('now', wp_timezone()))->setTime(0, 0);
$pastDay = $today->modify('-2 days');
$past = $pastDay->setTime(10, 0)->getTimestamp() * 1000;
$pastA = deletion_activity('완료 활동 A', $past, $past + 120000);
$pastB = deletion_activity('완료 활동 B', $past + 60000, $past + 180000);
$shared = deletion_source(1, $past, 180, 'g6-delete-shared-history', array($pastA, $pastB));
$rawCount = deletion_raw($shared['id']);
$beforeSelf = deletion_day(1, $pastDay);
$beforeGroup = deletion_day(1, $pastDay, 'class');
deletion_expect(180.0, $beforeSelf['activeSeconds'], 'Overlapping source history is counted only once');
deletion_expect(180.0, $beforeGroup['activeSeconds'], 'Context history uses the original authorization union');
deletion_delete($pastA);
deletion_expect(Clock::ms_to_mysql($past + 120000), Activities::find($pastA)['ends_at'], 'Deleting a completed activity keeps its historical window unchanged');
deletion_expect($rawCount, deletion_raw($shared['id']), 'Activity deletion does not remove shared raw evidence');
deletion_expect(array($pastA, $pastB), Participations::activity_ids($shared['id']), 'Both historical activity links survive deletion');
deletion_expect($beforeSelf['activeSeconds'], deletion_day(1, $pastDay)['activeSeconds'], 'Own history survives removal of one schedule');
deletion_expect($beforeGroup['activeSeconds'], deletion_day(1, $pastDay, 'class')['activeSeconds'], 'Teacher context history includes the deleted historical authorization');
deletion_assert(Activities::find($pastB)['status'] !== Activities::STATUS_DELETED, 'Deleting one activity leaves its overlapping peer active');
deletion_delete($pastB);
deletion_expect(180.0, deletion_day(1, $pastDay)['activeSeconds'], 'Removing all visible schedules does not erase prior own learning');
deletion_expect(180.0, deletion_day(1, $pastDay, 'class')['activeSeconds'], 'Removing all visible schedules does not erase prior context learning');
deletion_expect($rawCount, deletion_raw($shared['id']), 'Visible activity deletion preserves the original raw event count');

$now = intdiv(Clock::now_ms(), 1000) * 1000;
$liveA = deletion_activity('진행 활동 A', $now - 120000, $now + 120000);
$liveB = deletion_activity('진행 활동 B', $now - 60000, $now + 180000);
$future = deletion_activity('예정 활동', $now + 60000, $now + 120000);
$payload = array('user_id' => 1, 'problem_id' => 123, 'context_type' => 'class', 'context_id' => 'class:1', 'session_uid' => 'deletion-real-outbox', 'session_started_at' => $now - 90000,
    'events' => array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $now - 90000, 'meta' => array('visible' => true, 'focused' => true)),
        array('seq' => 2, 'type' => 'activity', 'occurred_at' => $now - 80000), array('seq' => 3, 'type' => 'heartbeat', 'occurred_at' => $now - 10000, 'meta' => array('visible' => true, 'focused' => true))));
$stored = Ingest::handle(new WP_REST_Request($payload));
deletion_assert($stored instanceof WP_REST_Response && $stored->data['stored'] > 0, 'Live observations use the actual production ingestion pipeline');
$liveSource = Participations::find((int) $stored->data['participation_id']);
$liveRawCount = deletion_raw($liveSource['id']);
$liveBefore = deletion_day(1, $today, 'self', $now);
deletion_delete($liveA);
deletion_delete($future);
$futureRow = Activities::find($future);
deletion_expect($futureRow['starts_at'], $futureRow['ends_at'], 'A removed future activity has an empty historical window');
deletion_assert(Gate::state(1, 123, 'class', 'class:1')['active'], 'Another overlapping live activity still authorizes collection');
deletion_expect($liveRawCount, deletion_raw($liveSource['id']), 'Live removal leaves already collected shared observations intact');
deletion_expect($liveBefore['activeSeconds'], deletion_day(1, $today, 'self', $now)['activeSeconds'], 'Deletion does not remove the already observed own interval');
$retry = $payload;
$retry['events'] = array($payload['events'][1]);
$oldRetry = Ingest::handle(new WP_REST_Request($retry));
deletion_expect(0, $oldRetry->data['stored'], 'A deleted-only old outbox event is acknowledged without duplicate storage');
deletion_expect(1, $oldRetry->data['policyDropped'], 'Deleted-only authorization does not revive on replay');
deletion_expect($liveRawCount, deletion_raw($liveSource['id']), 'Rejecting a replay never removes its already persisted historical observation');
$new = $payload;
$new['events'] = array(array('seq' => 4, 'type' => 'activity', 'occurred_at' => $now - 10000, 'meta' => array('visible' => true, 'focused' => true)));
$stillAllowed = Ingest::handle(new WP_REST_Request($new));
deletion_assert($stillAllowed instanceof WP_REST_Response && $stillAllowed->data['stored'] > 0, 'The other activity can accept a genuinely new shared-source event');
deletion_delete($liveB);
deletion_expect(false, Gate::state(1, 123, 'class', 'class:1')['active'], 'Deleting the last live authorization stops the gate');
$new['events'][0]['seq'] = 5;
$afterDelete = Ingest::handle(new WP_REST_Request($new));
deletion_expect(0, $afterDelete->data['stored'], 'Deleted activities cannot authorize more persisted work');
deletion_expect(1, $afterDelete->data['policyDropped'], 'A late outbox is policy-acknowledged instead of retried forever');
deletion_assert(deletion_day(1, $today, 'class', $now)['activeSeconds'] > 0, 'Historical context statistics remain after live authorization disappears');

// An archived completed window must preserve its exact source signature.
$oldDay = $today->modify('-70 days');
$old = $oldDay->setTime(10, 0)->getTimestamp() * 1000;
$archivedActivity = deletion_activity('보관된 완료 활동', $old, $old + 60000);
$archivedSource = deletion_source(1, $old, 60, 'g6-delete-archived-source', array($archivedActivity));
Cleanup::run();
deletion_expect(0, deletion_raw($archivedSource['id']), 'Existing retention stores aggregates before removing old raw evidence');
$archivedBefore = deletion_day(1, $oldDay, 'class');
$selfArchivedBefore = deletion_day(1, $oldDay);
$scoreBefore = Scores::get($archivedActivity, 1);
deletion_expect('archived', $archivedBefore['status'], 'Historical context has a frozen daily aggregate');
deletion_delete($archivedActivity);
deletion_expect($archivedBefore, deletion_day(1, $oldDay, 'class'), 'A deleted completed activity preserves its entire archived context aggregate');
deletion_expect($selfArchivedBefore, deletion_day(1, $oldDay), 'A deleted completed activity preserves its entire archived self aggregate');
deletion_expect($scoreBefore, Scores::get($archivedActivity, 1), 'Previously archived activity metrics remain unchanged');
deletion_assert(Participations::find($archivedSource['id']) !== null, 'Default unlimited retention keeps archived provenance');

// A removed zero-window schedule must neither require an impossible final
// grade nor keep a stale future physical deadline open forever.
$retainedActivity = deletion_activity('공유된 과거 활동', $old, $old + 60000);
$unusedFuture = deletion_activity('삭제할 먼 예정 활동', $now + 3600000, $now + 2 * DAY_IN_SECONDS * 1000);
$hardDeadline = $now + 2 * DAY_IN_SECONDS * 1000;
$staleDeadline = deletion_source(2, $old, 60, 'g6-delete-stale-future-bound', array($retainedActivity, $unusedFuture), $hardDeadline);
deletion_delete($unusedFuture);
deletion_expect($old + 60000, Statistics::retention_end($staleDeadline), 'The remaining valid historical window bounds a stale physical deadline');
Cleanup::run();
deletion_expect(0, deletion_raw($staleDeadline['id']), 'A deleted zero-length activity cannot permanently block raw cleanup');
$clipped = Participations::find($staleDeadline['id']);
deletion_expect($old + 60000, $clipped['ended_at_ms'], 'Retained physical provenance uses the actual remaining authorization end');
deletion_expect(true, $clipped['raw_events_expired'], 'A successful snapshot permits source expiry after deadline normalization');
deletion_expect(60.0, deletion_day(2, $oldDay, 'class')['activeSeconds'], 'Shared valid context learning survives cleanup after future deletion');
deletion_expect('archived', deletion_day(2, $oldDay)['status'], 'Deadline clipping occurs before snapshot signing so the archive remains consistent');
deletion_expect(null, Scores::get($unusedFuture, 2), 'A deleted empty window never invents an activity grade');

$longActivity = deletion_activity('보존해야 할 긴 활동', $old, $hardDeadline);
$sharedFuture = deletion_source(3, $old, 60, 'g6-delete-shared-future-protection', array($longActivity, $unusedFuture), $hardDeadline);
deletion_expect($hardDeadline, Statistics::retention_end($sharedFuture), 'Another still-valid future authorization retains its full physical bound');
Cleanup::run();
deletion_assert(deletion_raw($sharedFuture['id']) > 0, 'An unfinished shared activity keeps its source evidence');
deletion_assert(Activities::find($longActivity)['status'] !== Activities::STATUS_DELETED, 'Retention and another schedule deletion never remove the shared live activity');

Settings::update(array('aggregate_retention_days' => 61));
Cleanup::run();
deletion_expect(null, Participations::find($staleDeadline['id']), 'Finite retention can remove expired provenance despite the deleted future tombstone');
deletion_expect(array(), Participations::activity_ids($staleDeadline['id']), 'Finite retention removes only the expired source links');
deletion_assert(Participations::find($sharedFuture['id']) !== null && deletion_raw($sharedFuture['id']) > 0, 'A peer with a still-live shared activity is preserved during metadata expiry');
deletion_assert(Activities::find($unusedFuture) !== null, 'Shared activity tombstones remain separate from student retention');

echo "Activity deletion statistics: {$checks} SQL-backed checks passed\n";
