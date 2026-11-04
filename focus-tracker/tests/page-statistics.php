<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';
require __DIR__ . '/statistics-fixture.php';
use FocusTracker\Database\Activity_Repository as Activities;
use FocusTracker\Database\Event_Repository as Events;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository as Participations;
use FocusTracker\Database\Statistics_Repository as Daily;
use FocusTracker\Jobs\Retention_Cleanup;
use FocusTracker\Reporting\Statistics_Service as Statistics;
use FocusTracker\Scoring\Activity_Score_Service as Scores;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;

$assertions = 0;
function category_expect(mixed $expected, mixed $actual, string $label): void {
    global $assertions, $wpdb;
    ++$assertions;
    if ($expected !== $actual) { throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ' DB: ' . $wpdb->last_error); }
}
function category_bin(array $metrics, string $category, ?string $subtype = null): array {
    foreach ($metrics['pageCategories'] as $row) { if ($row['category'] === $category && $row['subtype'] === $subtype) { return $row; } }
    throw new RuntimeException('Category missing.');
}
function category_activity(string $context, int $start, int $end, ?array $problems = array(123)): int {
    return Activities::create(array('name' => 'Category fixture', 'owner_user_id' => 1, 'context_type' => 'group', 'context_id' => $context,
        'problem_ids' => $problems, 'starts_at' => Clock::ms_to_mysql($start), 'ends_at' => Clock::ms_to_mysql($end),
        'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
}
function category_session(int $user, ?int $problem, string $category, int $start, int $seconds, array $links, ?string $subtype = null, bool $legacy = false): array {
    $uid = 'category-' . $user . '-' . $category . '-' . $start;
    $p = Participations::find_or_create(array('activity_id' => null, 'user_id' => $user, 'problem_id' => $problem,
        'session_uid' => $legacy ? $uid : 'g6-' . substr(hash('sha256', $uid), 0, 32) . '-a', 'started_at_ms' => $start,
        'page_category' => $category, 'page_subtype' => $subtype, 'page_key' => $category . ':private-fixture-' . $start, 'page_context_version' => $legacy ? 0 : 1));
    category_expect(true, is_array($p), 'A categorized participation is stored');
    $events = array(array('seq' => 1, 'type' => 'session_start', 'occurred_at' => $start, 'meta' => array('visible' => true, 'focused' => true)));
    $seq = 2;
    for ($at = $start + 15000; $at < $start + $seconds * 1000; $at += 15000) { $events[] = array('seq' => $seq++, 'type' => 'heartbeat', 'occurred_at' => $at); }
    $events[] = array('seq' => $seq, 'type' => 'session_end', 'occurred_at' => $start + $seconds * 1000);
    category_expect(true, Events::insert_batch($p['id'], $events) !== false, 'Real source observations are stored');
    Participations::touch_heartbeat($p['id'], $start + $seconds * 1000);
    Participations::close($p['id'], $start + $seconds * 1000, 'pagehide');
    Participations::record_event_revision($p['id'], count($events));
    foreach ($links as $activity) { Participations::link_activity($p['id'], $activity); }
    return Participations::find($p['id']);
}

platform_fixture_connect_runtime(1, array(1, 2));
$GLOBALS['focus_test_user_id'] = 1;
$day = (new DateTimeImmutable('now', wp_timezone()))->setTime(0, 0)->modify('last wednesday');
$start = $day->setTime(10, 0)->getTimestamp() * 1000;
$now = Clock::now_ms();
update_option('focus_tracker_statistics_coverage_since', $day->modify('-40 days')->getTimestamp() * 1000);
$scope = array('type' => 'group', 'id' => 'group:1');
$activity = category_activity('group:1', $start, $start + 3600000);
$otherActivity = category_activity('group:2', $start, $start + 3600000);
$problem = category_session(1, 123, 'problem', $start, 60, array($activity));
$material = category_session(1, null, 'material', $start + 30000, 60, array($activity));
category_session(1, null, 'discussion', $start + 90000, 30, array($activity));
category_session(1, null, 'practice', $start + 600000, 15, array($activity), 'ml');
category_session(1, null, 'practice', $start + 660000, 15, array($activity));
category_session(1, 123, 'problem', $start + 1200000, 10, array($activity), null, true);
category_session(1, null, 'game', $start + 120000, 60, array($otherActivity));
category_session(1, null, 'other', $start, 300, array());
category_session(9, null, 'material', $start, 300, array($activity));
// A target-problem mismatch must not expand the score or statistics denominator.
category_session(1, 124, 'problem', $start + 1800000, 60, array($activity));
$report = Statistics::report(1, 'week', $day->format('Y-m-d'), $scope, $now);
$total = $report['totals'];
category_expect(160.0, $total['observedSeconds'], 'Context totals include signed problem-less reading under a problem-filtered schedule');
category_expect(160.0, $total['activeSeconds'], 'Every category participates in overall active union');
category_expect(30.0, category_bin($total, 'problem')['activeSeconds'], 'A later active material tab exclusively owns the overlapping time');
category_expect(60.0, category_bin($total, 'material')['activeSeconds'], 'Material duration is retained without double-counting its problem tab');
category_expect(30.0, category_bin($total, 'discussion')['activeSeconds'], 'Discussion observations contribute to the authorized context');
category_expect(15.0, category_bin($total, 'practice', 'ml')['activeSeconds'], 'ML time is an exclusive practice subtype');
category_expect(15.0, category_bin($total, 'practice')['activeSeconds'], 'General practice does not duplicate its ML subtype');
category_expect(10.0, category_bin($total, 'unclassified')['activeSeconds'], 'Legacy history remains explicitly unclassified');
category_expect(null, category_bin($total, 'game')['activeSeconds'], 'Another group’s game records remain private');
category_expect(null, category_bin($total, 'other')['activeSeconds'], 'An unlinked same-time page is not exposed to a teacher');
category_expect(160000, (int) round(array_sum(array_column($total['pageCategories'], 'activeSeconds')) * 1000), 'Category active milliseconds exactly partition the context union');
category_expect(160000, (int) round(array_sum(array_column($total['pageCategories'], 'observedSeconds')) * 1000), 'Category observed milliseconds exactly partition the context union');
category_expect(true, $report['pageCategoryCoverageUnknown'], 'Legacy category uncertainty does not disappear');
category_expect(1.0, $total['focusRatio'], 'Legacy classification uncertainty does not destroy known overall browser measurements');
category_expect(1, category_bin($total, 'material')['pageViews'], 'Page views count observed page sessions rather than a period-unique URL claim');
category_expect($total['pageCategories'], $report['pageCategories'], 'Top-level category alias uses the same aggregate bins');
$monthly = Statistics::report(1, 'month', $day->format('Y-m-d'), $scope, $now);
category_expect($total['pageCategories'], $monthly['pageCategories'], 'The same raw sessions have consistent calendar week and month totals');
$self = Statistics::report(1, 'week', $day->format('Y-m-d'), array('type' => 'self', 'id' => ''), $now);
category_expect(220.0, $self['totals']['observedSeconds'], 'Self totals include the other authorized group while omitting an unlinked gated source');
category_expect(60.0, category_bin($self['totals'], 'game')['activeSeconds'], 'Self page categories can include a second authorized group');

// Pure reading score policy survives the live cache and raw archival.
$readingActivity = category_activity('group:1', $start + 3600000, $start + 3660000);
// An older problem tab stays open underneath the newer reading page. Metadata
// presence alone cannot reconstruct which category owned the scoring interval.
category_session(2, 123, 'problem', $start + 3599000, 61, array($readingActivity));
$reading = category_session(2, null, 'material', $start + 3600000, 60, array($readingActivity));
$metrics = Scores::score_for_learner(Activities::find($readingActivity), 2);
category_expect(false, $metrics['activity_eligible'], 'Stored pure reading is excluded from editing evaluation');
category_expect(null, $metrics['score_activity'], 'Live reading activity score is null rather than zero');
category_expect(20.0, $metrics['score_total'], 'Reading-only observation keeps the normalized original scoring scale');
$cached = Scores::score_for_learner(Activities::find($readingActivity), 2);
category_expect(true, $cached['cached'], 'The settled reading activity reuses its real SQL cache');
category_expect(false, $cached['activity_eligible'], 'The SQL cache preserves the explicit activity eligibility flag');
category_expect(0, (int) $wpdb->get_var($wpdb->prepare('SELECT activity_eligible FROM ' . Installer::scores_table() . ' WHERE activity_id=%d AND user_id=2', $readingActivity)), 'The raw SQL flag preserves foreground neutrality despite a background coding tab');
category_expect(null, $cached['activity_window_ratio'], 'The SQL cache restores a neutral ratio as null');
$noWeightActivity = category_activity('group:1', $start + 4200000, $start + 4260000);
Activities::update($noWeightActivity, array('weight_focus' => 0, 'weight_selfreg' => 0, 'weight_activity' => 6));
category_session(2, null, 'game', $start + 4200000, 60, array($noWeightActivity));
$noWeight = Scores::score_for_learner(Activities::find($noWeightActivity), 2);
category_expect(null, $noWeight['score_total'], 'A neutral page with no supported weighted score is unevaluated live');
category_expect(false, $noWeight['score_eligible'], 'The explicit no-score eligibility flag is available live');
category_expect(null, Scores::score_for_learner(Activities::find($noWeightActivity), 2)['score_total'], 'The SQL cache preserves an unevaluated total as null');
$dailyBefore = Statistics::day(1, $scope, $day, $day->modify('+1 day')->getTimestamp() * 1000, $now, false);
$snapshotBeforePurge = Daily::get(1, $scope, $day->format('Y-m-d'), wp_timezone()->getName());
Settings::update(array('retention_days' => 1, 'check_retention_days' => 1));
Retention_Cleanup::run();
category_expect(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id=%d', $material['id'])), 'Production retention removes categorized raw events only after successful snapshots');
$retainedMaterial = Participations::find($material['id']);
$pageFields = array_flip(array('page_category', 'page_subtype', 'page_key', 'page_context_version'));
category_expect(array_intersect_key($material, $pageFields), array_intersect_key($retainedMaterial, $pageFields), 'Page classification and identifier remain in retained session provenance');
category_expect(true, (bool) $retainedMaterial['raw_events_expired'], 'Retained categorized provenance explicitly records raw-event expiry');
$snapshotAfterPurge = Daily::get(1, $scope, $day->format('Y-m-d'), wp_timezone()->getName());
category_expect($snapshotBeforePurge['source_signature'], $snapshotAfterPurge['source_signature'], 'The raw-expired flag transition does not invalidate the archived page-source signature');
$archived = Statistics::day(1, $scope, $day, $day->modify('+1 day')->getTimestamp() * 1000, $now, false);
category_expect('archived', $archived['status'], 'Categorized daily aggregates remain after raw retention');
category_expect($dailyBefore['pageCategories'], $archived['pageCategories'], 'All category durations and view counts survive raw-event deletion');
$readingArchived = Scores::score_for_learner(Activities::find($readingActivity), 2);
category_expect('archived', $readingArchived['data_status'], 'Pure reading score is available after raw archival');
category_expect(false, $readingArchived['activity_eligible'], 'Archived reading remains unevaluated for unsupported editing');
category_expect(null, $readingArchived['score_activity'], 'Raw deletion cannot turn neutral reading into an activity zero');
category_expect(20.0, $readingArchived['score_total'], 'The normalized reading total survives raw archival');
$noWeightArchived = Scores::score_for_learner(Activities::find($noWeightActivity), 2);
category_expect('archived', $noWeightArchived['data_status'], 'The no-weight neutral result is archived normally');
category_expect(null, $noWeightArchived['score_total'], 'Raw deletion does not turn an unevaluated total into zero');
category_expect(false, $noWeightArchived['score_eligible'], 'Archived SQL preserves no-score eligibility');
Activities::update($readingActivity, array('weight_focus' => 0, 'weight_selfreg' => 0, 'weight_activity' => 6));
category_expect(20.0, Scores::score_for_learner(Activities::find($readingActivity), 2)['score_total'], 'Current weight changes cannot reinterpret a previously eligible archived twenty-point result');
$snapshot = Daily::get(1, $scope, $day->format('Y-m-d'), wp_timezone()->getName());
category_expect(false, str_contains($snapshot['metrics_json'], 'private-fixture'), 'Persistent daily aggregates contain no individual page keys');
// Simulate an older frozen snapshot: overall totals are not invalidated or guessed.
$old = $snapshot['metrics'];
unset($old['pageCategories'], $old['pageCategoryCoverageUnknown']);
Daily::save(1, $scope, $day->format('Y-m-d'), wp_timezone()->getName(), $old, $snapshot['source_signature'], true);
$legacyArchive = Statistics::day(1, $scope, $day, $day->modify('+1 day')->getTimestamp() * 1000, $now, false);
category_expect(160.0, $legacyArchive['activeSeconds'], 'A pre-feature frozen total remains numerically valid');
category_expect('archived', $legacyArchive['status'], 'The category feature does not invalidate an otherwise valid old snapshot');
category_expect(true, $legacyArchive['pageCategoryCoverageUnknown'], 'Removed historical raw events cannot invent category totals');
category_expect(null, category_bin($legacyArchive, 'material')['activeSeconds'], 'Old archives do not receive guessed material zeroes or durations');
echo 'page-statistics: ' . $assertions . " checks passed\n";
