<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';
require __DIR__ . '/statistics-fixture.php';

use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Check_Repository;
use FocusTracker\Database\Event_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Database\Statistics_Repository;
use FocusTracker\Reporting\Statistics_Service as Statistics;
use FocusTracker\Rest\Controllers\Statistics_Controller as Api;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;

$checks = 0;
function stat_expect(mixed $expected, mixed $actual, string $label): void {
    global $checks, $wpdb;
    ++$checks;
    if ($expected !== $actual) { throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ' SQL: ' . $wpdb->last_error); }
}
function stat_assert(bool $condition, string $label): void { stat_expect(true, $condition, $label); }
function stat_ms(string $local): int { return (new DateTimeImmutable($local, wp_timezone()))->getTimestamp() * 1000; }
function stat_day(array $report, string $date): array {
    foreach ($report['days'] as $day) { if ($day['date'] === $date) { return $day; } }
    throw new RuntimeException('Day missing: ' . $date);
}
function stat_session(int $user, int $problem, int $start, int $seconds, string $uid, array $extra = array(), bool $heartbeat = true): array {
    $p = Participation_Repository::find_or_create(array('activity_id' => null, 'user_id' => $user, 'problem_id' => $problem, 'session_uid' => $uid, 'started_at_ms' => $start));
    $events = array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $start, 'meta' => array('visible' => true, 'focused' => true)));
    $seq = 2;
    if ($heartbeat) {
        for ($at = $start + 15000; $at < $start + $seconds * 1000; $at += 15000) {
            $events[] = array('seq' => $seq++, 'type' => 'heartbeat', 'occurred_at' => $at);
        }
    }
    foreach ($extra as $event) {
        $event['seq'] = $seq++;
        $event['occurred_at'] = $start + $event['offset'] * 1000;
        unset($event['offset']);
        $events[] = $event;
    }
    $events[] = array('seq' => $seq, 'type' => 'session_end', 'occurred_at' => $start + $seconds * 1000);
    stat_assert(false !== Event_Repository::insert_batch($p['id'], $events), 'Fixture events stored');
    Participation_Repository::touch_heartbeat($p['id'], $start + $seconds * 1000);
    Participation_Repository::close($p['id'], $start + $seconds * 1000, 'pagehide');
    Participation_Repository::record_event_revision($p['id'], count($events));
    return Participation_Repository::find($p['id']);
}
function stat_activity(string $type, string $id, int $start, int $end, ?array $problems): int {
    return Activity_Repository::create(array('name' => '통계 범위', 'owner_user_id' => 1, 'context_type' => $type, 'context_id' => $id,
        'problem_ids' => $problems, 'starts_at' => Clock::ms_to_mysql($start), 'ends_at' => Clock::ms_to_mysql($end),
        'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
}
function stat_check(int $user, int $problem, int $at, string $type, ?string $id, string $status): array {
    return Check_Repository::create(array('user_id' => $user, 'problem_id' => $problem, 'submission_id' => null, 'context_type' => $type, 'context_id' => $id,
        'problem_hash' => str_repeat('a', 64), 'source_kind' => 'problem', 'source_hash' => str_repeat('b', 64), 'candidate_json' => '{}',
        'issued_at_ms' => $at, 'expires_at_ms' => $at + 600000, 'status' => $status));
}

platform_fixture_connect_runtime(1, array(1, 2));
$GLOBALS['focus_test_user_id'] = 1;
$today = (new DateTimeImmutable('now', wp_timezone()))->setTime(0, 0);
$day = $today->modify('-2 days');
$date = $day->format('Y-m-d');
$now = Clock::now_ms();
$start = $day->setTime(10, 0)->getTimestamp() * 1000;
$markers = array(array('type' => 'activity', 'offset' => 1), array('type' => 'run', 'offset' => 2), array('type' => 'submit', 'offset' => 3));
$tab_a = stat_session(1, 123, $start, 60, 'stats-a', $markers);
$tab_b = stat_session(1, 123, $start + 30000, 60, 'stats-b', $markers);
$tab_other = stat_session(1, 124, $start + 3600000, 30, 'stats-private-group', $markers);
stat_session(9, 123, $start, 600, 'stats-other-user', $markers);
$report = Statistics::report(1, 'week', $date, array('type' => 'self', 'id' => ''), $now);
stat_expect(7, count($report['days']), 'Weekly daily series always has seven calendar days');
stat_expect('1', (new DateTimeImmutable($report['startDate']))->format('N'), 'Week begins Monday');
stat_expect(120.0, $report['totals']['activeSeconds'], 'Overlapping tabs union once and separate sessions add');
stat_expect(120.0, $report['totals']['observedSeconds'], 'Denominator excludes the hour between sessions');
stat_expect(1.0, $report['totals']['focusRatio'], 'Ratios are fractions rather than percentages');
stat_expect(3, $report['totals']['editCount'], 'One count per physical edit event');
stat_expect(3, $report['totals']['runCount'], 'Run signals counted separately');
stat_expect(3, $report['totals']['submitCount'], 'Submit signals counted separately');
stat_expect(null, stat_day($report, $day->modify('-1 day')->format('Y-m-d'))['focusRatio'], 'An unobserved day is not zero focus');
stat_expect(null, stat_day($report, $day->modify('-1 day')->format('Y-m-d'))['editCount'], 'An unobserved day is not zero work');
stat_expect(false, $report['affectsFocusScore'], 'Statistics never create a grade');

$group_one = array('type' => 'group', 'id' => 'group:1');
$group_two = array('type' => 'group', 'id' => 'group:2');
$activity_one = stat_activity('group', 'group:1', $start, $start + 90000, array(123));
$activity_overlap = stat_activity('group', 'group:1', $start + 15000, $start + 75000, array(123));
$activity_other = stat_activity('group', 'group:2', $start, $start + 7200000, array(124));
foreach (array($tab_a, $tab_b) as $tab) {
    Participation_Repository::link_activity($tab['id'], $activity_one);
    Participation_Repository::link_activity($tab['id'], $activity_overlap);
}
Participation_Repository::link_activity($tab_other['id'], $activity_other);
stat_session(1, 123, $start, 90, 'stats-unlinked-same-problem', $markers);
$context_report = Statistics::report(1, 'week', $date, $group_one, $now);
stat_expect(90.0, $context_report['totals']['activeSeconds'], 'Teacher activity windows merge and exclude other group problem');
stat_expect(2, $context_report['totals']['editCount'], 'Overlapping activities do not duplicate event counts');
stat_expect(30.0, Statistics::report(1, 'week', $date, $group_two, $now)['totals']['activeSeconds'], 'Other context has its own window/problem subset');
$short_activity = stat_activity('group', 'group:1', $start, $start + 30000, array(123));
$short_link = stat_session(5, 123, $start, 90, 'stats-linked-short-only', array(array('type' => 'run', 'offset' => 40)));
Participation_Repository::link_activity($short_link['id'], $short_activity);
$short_report = Statistics::report(5, 'week', $date, $group_one, $now);
stat_expect(30.0, $short_report['totals']['activeSeconds'], 'Each tab uses only its own linked activity windows');
stat_expect(0, $short_report['totals']['runCount'], 'Unlinked longer activity cannot include a later private marker');
stat_check(1, 123, $start + 5000, 'group', '1', 'incorrect');
stat_check(1, 123, $start + 6000, 'group', '2', 'correct');
stat_check(1, 123, $start + 7000, 'general', null, 'unknown');
stat_check(1, 124, $start + 8000, 'group', '1', 'correct');
$context_report = Statistics::report(1, 'week', $date, $group_one, $now);
stat_expect(1, $context_report['totals']['checks']['incorrect'], 'Typed opaque scope matches its numeric core check context');
stat_expect(0, $context_report['totals']['checks']['correct'], 'Other group and wrong-problem checks stay private');
stat_expect(0, $context_report['totals']['checks']['unknown'], 'General checks are not leaked into teacher context');
stat_expect(1.0, $context_report['totals']['focusRatio'], 'Incorrect check does not lower browser participation');

// Weighted ratio: 30/60 and 30/30 are 60/90 together, not mean(50%,100%).
stat_session(2, 123, $start, 60, 'stats-weight-a', array(array('type' => 'hidden', 'offset' => 30)));
stat_session(2, 123, $start + 600000, 30, 'stats-weight-b');
$weighted = Statistics::report(2, 'week', $date, array('type' => 'self', 'id' => ''), $now);
stat_expect(60.0, $weighted['totals']['activeSeconds'], 'Known hidden duration excluded from active numerator');
stat_expect(90.0, $weighted['totals']['observedSeconds'], 'Known observed hidden duration remains denominator');
stat_expect(0.6667, $weighted['totals']['focusRatio'], 'Period ratio uses duration weights');
stat_session(3, 123, $start, 600, 'stats-gap', array(), false);
$gap = Statistics::report(3, 'week', $date, array('type' => 'self', 'id' => ''), $now);
stat_expect(null, $gap['totals']['focusRatio'], 'Long heartbeat gap suppresses confident focus ratio');
stat_expect(true, $gap['totals']['hasObservationGaps'], 'Long gap is explicit quality evidence');
stat_expect(45.0, $gap['totals']['observedSeconds'], 'Overnight-like silent gap is not invented observed duration');

// Distinct same-time edit markers from two tabs must both survive.
stat_session(4, 123, $start, 30, 'stats-same-time-a', $markers);
stat_session(4, 123, $start, 30, 'stats-same-time-b', $markers);
$same = Statistics::report(4, 'week', $date, array('type' => 'self', 'id' => ''), $now);
stat_expect(30.0, $same['totals']['activeSeconds'], 'Identical tab intervals are not doubled');
stat_expect(2, $same['totals']['editCount'], 'Distinct same-millisecond edits are not collapsed');

// Handler-level security also protects callers bypassing permission callbacks.
stat_expect(true, Api::authorize_self(), 'Self-report capability accepted');
$self_api = Api::self_statistics(new WP_REST_Request(array(), array('period' => 'week', 'date' => $date)));
stat_assert($self_api instanceof WP_REST_Response && $self_api->data['userId'] === 1, 'Self identity comes from authenticated session');
stat_expect(403, Api::self_statistics(new WP_REST_Request(array(), array('user_id' => 2)))->data['status'], 'Self endpoint cannot select peer');
$teacher_params = array('context_type' => 'group', 'context_id' => 'group:1', 'user_id' => 1, 'period' => 'week', 'date' => $date);
$teacher_api = Api::report(new WP_REST_Request(array(), $teacher_params));
stat_assert($teacher_api instanceof WP_REST_Response && $teacher_api->data['totals']['activeSeconds'] === 90.0, 'Teacher result is restricted to authorised context');
$bad_roster = $teacher_params;
$bad_roster['user_id'] = 9;
stat_expect(403, Api::report(new WP_REST_Request(array(), $bad_roster))->data['status'], 'Student outside current roster denied');
$GLOBALS['focus_test_statistics_deny_context'] = true;
stat_expect(403, Api::report(new WP_REST_Request(array(), $teacher_params))->data['status'], 'Context gateway denial fails closed');
$GLOBALS['focus_test_statistics_deny_context'] = false;
$GLOBALS['focus_test_allowed'] = false;
stat_expect(403, Api::report(new WP_REST_Request(array(), $teacher_params))->data['status'], 'Feature capability alone cannot be bypassed');
$GLOBALS['focus_test_allowed'] = true;
stat_expect(400, Api::self_statistics(new WP_REST_Request(array(), array('period' => 'year')))->data['status'], 'Unsupported period rejected');
stat_expect(400, Api::self_statistics(new WP_REST_Request(array(), array('date' => '2026-02-30')))->data['status'], 'Impossible date rejected');

$leap = Statistics::report(10, 'month', '2024-02-12', array('type' => 'self', 'id' => ''), stat_ms('2024-03-02'));
stat_expect(29, count($leap['days']), 'Leap-year February has 29 local days');
stat_expect('2024-02-29', $leap['endDate'], 'Month ends at actual calendar boundary');
stat_expect('2024-01-01', $leap['previous']['startDate'], 'Previous month comparison uses full calendar month');
$cross_year = Statistics::report(10, 'week', '2027-01-01', array('type' => 'self', 'id' => ''), stat_ms('2027-01-05'));
stat_expect(array('2026-12-28', '2027-01-03'), array($cross_year['startDate'], $cross_year['endDate']), 'Week handles year boundary');
stat_expect(null, $leap['delta']['focusRatio'], 'No evidence is never a false improvement from zero');

$comparison_now = stat_ms('2026-10-22 12:00:00');
stat_session(31, 123, stat_ms('2026-10-21 10:00:00'), 60, 'stats-compare-current', array(array('type' => 'hidden', 'offset' => 30)));
stat_session(31, 123, stat_ms('2026-10-14 10:00:00'), 60, 'stats-compare-previous');
stat_session(31, 123, stat_ms('2026-10-16 10:00:00'), 600, 'stats-compare-after-elapsed');
$comparison = Statistics::report(31, 'week', '2026-10-22', array('type' => 'self', 'id' => ''), $comparison_now);
stat_expect(true, $comparison['provisional'], 'Current partial week is provisional');
stat_expect(60.0, $comparison['previous']['totals']['observedSeconds'], 'Previous comparison clips to equal elapsed calendar portion');
stat_expect(-0.5, $comparison['delta']['focusRatio'], 'Known ratio delta compares like-for-like observations');
stat_expect(null, stat_day($comparison, '2026-10-23')['activeSeconds'], 'Future days do not become zero participation');

$live_start = stat_ms('2026-10-22 11:59:30');
$live = Participation_Repository::find_or_create(array('activity_id' => null, 'user_id' => 32, 'problem_id' => 123, 'session_uid' => 'stats-live', 'started_at_ms' => $live_start));
Event_Repository::insert_batch($live['id'], array(array('type' => 'session_start', 'seq' => 1, 'occurred_at' => $live_start, 'meta' => array('visible' => true, 'focused' => true))));
$live_report = Statistics::report(32, 'week', '2026-10-22', array('type' => 'self', 'id' => ''), $comparison_now);
stat_expect(30.0, $live_report['totals']['observedSeconds'], 'Fresh live session extends only through current observation time');
Event_Repository::insert_batch($live['id'], array(array('type' => 'hidden', 'seq' => 2, 'occurred_at' => $live_start + 10000)));
Participation_Repository::record_event_revision($live['id'], 1);
$live_late = Statistics::report(32, 'week', '2026-10-22', array('type' => 'self', 'id' => ''), $comparison_now);
stat_expect(10.0, $live_late['totals']['activeSeconds'], 'Late delivered transition recalculates live day');
stat_expect(0.3333, $live_late['totals']['focusRatio'], 'Live ratios update after late events');

// Local midnight splitting and DST preserve actual elapsed seconds.
$midnight = stat_ms($day->format('Y-m-d') . ' 23:59:30');
stat_session(11, 123, $midnight, 60, 'stats-midnight');
$midnight_report = Statistics::report(11, 'month', $date, array('type' => 'self', 'id' => ''), $now);
stat_expect(30.0, stat_day($midnight_report, $date)['activeSeconds'], 'Midnight clips first local day');
stat_expect(30.0, stat_day($midnight_report, $day->modify('+1 day')->format('Y-m-d'))['activeSeconds'], 'Midnight clips second local day');
$GLOBALS['focus_test_timezone'] = 'America/New_York';
$dst_start = stat_ms('2024-03-10 01:59:00');
stat_session(12, 123, $dst_start, 120, 'stats-dst');
$dst = Statistics::report(12, 'week', '2024-03-10', array('type' => 'self', 'id' => ''), stat_ms('2024-03-11'));
stat_expect(120.0, stat_day($dst, '2024-03-10')['observedSeconds'], 'Skipped DST hour does not become an extra hour of study');
stat_expect('America/New_York', $dst['timeZone'], 'Site timezone is explicit');
$GLOBALS['focus_test_timezone'] = 'Asia/Seoul';

// Retention protects a full day's union before deleting the first tab. Later
// source changes after deletion invalidate confidence, never fabricate history.
$old_day = $today->modify('-70 days');
$coverage_marker = $old_day->modify('-35 days')->getTimestamp() * 1000;
update_option('focus_tracker_statistics_coverage_since', $coverage_marker);
Settings::update(array('retention_days' => 7));
Installer::install();
stat_expect((int) $coverage_marker, (int) get_option('focus_tracker_statistics_coverage_since'), 'Feature upgrade and retention edits never move historical coverage boundary');
Settings::update(array('retention_days' => 60));
$before_boundary = $old_day->modify('-36 days');
$no_history = Statistics::report(99, 'week', $before_boundary->format('Y-m-d'), array('type' => 'self', 'id' => ''), $now);
stat_expect('unavailable', stat_day($no_history, $before_boundary->format('Y-m-d'))['status'], 'Dates before original coverage boundary are unknown rather than empty history');
$old_start = $old_day->setTime(9, 0)->getTimestamp() * 1000;
$old_a = stat_session(21, 123, $old_start, 60, 'stats-old-a', $markers);
$old_b = stat_session(21, 123, $old_start + 30000, 60, 'stats-old-b', $markers);
$old_scope_activity = stat_activity('group', 'group:archive', $old_start, $old_start + 90000, array(123));
Participation_Repository::link_activity($old_a['id'], $old_scope_activity);
Participation_Repository::link_activity($old_b['id'], $old_scope_activity);
stat_check(21, 123, $old_start + 5000, 'group', 'archive', 'incorrect');
$wpdb->fail_pattern = "VALUES (21, '";
\FocusTracker\Jobs\Retention_Cleanup::run();
stat_assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id = ' . $old_a['id']) > 0, 'Failed daily aggregate preserves raw event source');
\FocusTracker\Jobs\Retention_Cleanup::run();
stat_expect(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id IN (' . $old_a['id'] . ',' . $old_b['id'] . ')'), 'Protected old events are purged');
$archive = Statistics::report(21, 'week', $old_day->format('Y-m-d'), array('type' => 'self', 'id' => ''), $now);
$archived_day = stat_day($archive, $old_day->format('Y-m-d'));
stat_expect(90.0, $archived_day['activeSeconds'], 'Archived daily total preserves overlapping-tab union');
stat_expect('archived', $archived_day['status'], 'Archived provenance is explicit');
stat_expect(90.0, $archive['totals']['activeSeconds'], 'Known empty dates allow usable historical weekly totals after purge');
stat_expect(1.0, $archive['totals']['focusRatio'], 'Historical weekly ratio uses preserved observed-day totals');
stat_expect(1, $archived_day['checks']['incorrect'], 'Check totals survive raw question deletion');
stat_expect(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::checks_table() . ' WHERE user_id = 21'), 'Archived old question source is removed');
$saved_json = (string) $wpdb->get_var('SELECT metrics_json FROM ' . Installer::statistics_table() . ' WHERE user_id = 21 LIMIT 1');
stat_assert(! str_contains($saved_json, 'candidate_json') && ! str_contains($saved_json, 'source') && ! str_contains($saved_json, 'session_uid'), 'Daily aggregates store totals without raw code or identifiers');
$partial = Statistics::day(21, array('type' => 'self', 'id' => ''), $old_day, $old_start + 45000, $now, false);
stat_expect('unavailable', $partial['status'], 'Full archived day cannot answer a partial-day comparison');
stat_expect(null, $partial['focusRatio'], 'Unavailable partial archive cannot create a misleading delta');
$full_again = Statistics::day(21, array('type' => 'self', 'id' => ''), $old_day, $old_day->modify('+1 day')->getTimestamp() * 1000, $now, false);
stat_expect(90.0, $full_again['activeSeconds'], 'Partial query never overwrites full-day snapshot');
$frozen = Statistics_Repository::get(21, array('type' => 'self', 'id' => ''), $old_day->format('Y-m-d'), wp_timezone()->getName());
$racing_metrics = $frozen['metrics'];
$racing_metrics['activeSeconds'] = 0;
stat_assert(Statistics_Repository::save(21, array('type' => 'self', 'id' => ''), $old_day->format('Y-m-d'), wp_timezone()->getName(), $racing_metrics, str_repeat('0', 64), false), 'Simulated delayed normal report write completes safely');
$after_race = Statistics_Repository::get(21, array('type' => 'self', 'id' => ''), $old_day->format('Y-m-d'), wp_timezone()->getName());
stat_expect($frozen['metrics'], $after_race['metrics'], 'Normal report cannot overwrite already archived daily snapshot');
stat_expect(true, $after_race['archived'], 'Normal report cannot unfreeze archive provenance');
Participation_Repository::record_event_revision($old_a['id'], 1);
$changed_archive = Statistics::report(21, 'week', $old_day->format('Y-m-d'), array('type' => 'self', 'id' => ''), $now);
stat_expect(null, stat_day($changed_archive, $old_day->format('Y-m-d'))['focusRatio'], 'Late source revision after purge removes confidence');
stat_expect(true, stat_day($changed_archive, $old_day->format('Y-m-d'))['coverageUnknown'], 'Late archived-source change explicitly marks unknown coverage');
stat_expect(null, $changed_archive['delta']['focusRatio'], 'Changed archived evidence cannot imply improvement');

$legacy = stat_session(22, 123, $old_start, 30, 'stats-legacy-expired');
$wpdb->query('DELETE FROM ' . Installer::events_table() . ' WHERE participation_id = ' . $legacy['id']);
$wpdb->update(Installer::participations_table(), array('raw_events_expired' => 1), array('id' => $legacy['id']));
$unknown_history = Statistics::report(22, 'month', $old_day->format('Y-m-d'), array('type' => 'self', 'id' => ''), $now);
stat_expect('unavailable', stat_day($unknown_history, $old_day->format('Y-m-d'))['status'], 'Pre-feature deleted logs cannot be reconstructed');
stat_expect(null, stat_day($unknown_history, $old_day->format('Y-m-d'))['activeSeconds'], 'Unknown old participation is not zero participation');

// Check retention is bounded and makes progress without an unsafe bulk delete.
for ($i = 0; $i < 120; ++$i) { stat_check(41, 123, $old_start + $i, 'general', null, 'unknown'); }
stat_check(42, 123, $old_start, 'general', null, 'pending');
\FocusTracker\Jobs\Retention_Cleanup::run();
stat_expect(20, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::checks_table() . ' WHERE user_id = 41'), 'First bounded sweep leaves unprocessed raw question rows');
$batch_archive = Statistics::report(41, 'week', $old_day->format('Y-m-d'), array('type' => 'self', 'id' => ''), $now);
stat_expect(120, stat_day($batch_archive, $old_day->format('Y-m-d'))['checks']['unknown'], 'First snapshot preserves full daily count before deleting any question');
\FocusTracker\Jobs\Retention_Cleanup::run();
stat_expect(0, (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::checks_table() . ' WHERE user_id = 41'), 'Second sweep processes remaining bounded backlog');
$batch_again = Statistics::report(41, 'week', $old_day->format('Y-m-d'), array('type' => 'self', 'id' => ''), $now);
stat_expect(120, stat_day($batch_again, $old_day->format('Y-m-d'))['checks']['unknown'], 'Later batches do not replace full archive with partial remaining count');
\FocusTracker\Jobs\Retention_Cleanup::run();
$expired_check_archive = Statistics::report(42, 'week', $old_day->format('Y-m-d'), array('type' => 'self', 'id' => ''), $now);
stat_expect(1, stat_day($expired_check_archive, $old_day->format('Y-m-d'))['checks']['unanswered'], 'Frozen old pending questions are normalised to unanswered before deletion');

// A disconnected gated tab cannot keep its original, longer deadline in the
// student's personal report after the teacher shortens the activity.
$gated_activity = stat_activity('group', 'group:shortened', $start, $start + 60000, array(123));
$gated = stat_session(51, 123, $start, 20, 'g6-shortened-window', array(array('type' => 'activity', 'offset' => 15)));
Participation_Repository::link_activity($gated['id'], $gated_activity);
$gated_before = Statistics::report(51, 'week', $date, array('type' => 'self', 'id' => ''), $now);
stat_expect(20.0, stat_day($gated_before, $date)['activeSeconds'], 'Gated self history includes only its linked schedule');
Activity_Repository::update($gated_activity, array('ends_at' => Clock::ms_to_mysql($start + 10000), 'status' => 'closed'));
$gated_after = Statistics::report(51, 'week', $date, array('type' => 'self', 'id' => ''), $now);
stat_expect(10.0, stat_day($gated_after, $date)['activeSeconds'], 'Offline gated tab is clipped to a teacher-shortened deadline');
stat_expect(0, stat_day($gated_after, $date)['editCount'], 'Shortened schedule excludes later edit signals from own cumulative report');
$gated_unlinked = stat_session(52, 123, $start, 20, 'g6-no-current-authorization');
$unlinked_report = Statistics::report(52, 'week', $date, array('type' => 'self', 'id' => ''), $now);
stat_expect(null, stat_day($unlinked_report, $date)['activeSeconds'], 'New gated sessions cannot regain unscheduled time without a saved activity authorization');

echo 'statistics: ' . $checks . " SQL-backed checks passed\n";
