<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Scoring/Score_Calculator.php';
use FocusTracker\Scoring\Score_Calculator as Calculator;

$assertions = 0;
function page_expect(mixed $expected, mixed $actual, string $label): void {
    ++$GLOBALS['assertions'];
    if ($expected !== $actual) { throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function page_session(int $id, string $category, int $start, int $end, array $extra = array(), ?string $subtype = null): array {
    $events = array(array('type' => 'session_start', 'occurred_at' => $start, 'seq' => 1, 'meta' => array('visible' => true, 'focused' => true)));
    for ($at = $start + 15000; $at < $end; $at += 15000) { $events[] = array('type' => 'heartbeat', 'occurred_at' => $at); }
    $events = array_merge($events, $extra, array(array('type' => 'session_end', 'occurred_at' => $end)));
    return array('id' => $id, 'session_uid' => 'page-' . $id, 'problem_id' => $category === 'problem' ? 123 : null,
        'started_at_ms' => $start, 'ended_at_ms' => $end, 'last_heartbeat_at_ms' => $end, 'events' => $events,
        'page_context_version' => 1, 'page_category' => $category, 'page_subtype' => $subtype, 'page_key' => $category . ':' . $id);
}
function page_bin(array $timeline, string $category, ?string $subtype = null): array {
    foreach ($timeline['pageCategories'] as $bin) { if ($bin['category'] === $category && $bin['subtype'] === $subtype) { return $bin; } }
    throw new RuntimeException('Missing page category bin.');
}
function page_assert_partition(array $timeline, int $active, int $observed, string $label): void {
    page_expect($active, (int) round(array_sum(array_column($timeline['pageCategories'], 'activeSeconds')) * 1000), $label . ' active category milliseconds exactly equal the union');
    page_expect($observed, (int) round(array_sum(array_column($timeline['pageCategories'], 'observedSeconds')) * 1000), $label . ' observed category milliseconds exactly equal the union');
}

$problem = page_session(1, 'problem', 0, 60000);
$material = page_session(2, 'material', 20000, 60000);
$overlap = Calculator::page_timeline_for_participations(array($problem, $material), 0, 60000);
page_expect(20, page_bin($overlap, 'problem')['activeSeconds'], 'Latest active page owns the simultaneous interval');
page_expect(40, page_bin($overlap, 'material')['activeSeconds'], 'Reading time is attributed without doubling the problem tab');
page_assert_partition($overlap, 60000, 60000, 'Overlapping tabs');
$reversed = Calculator::page_timeline_for_participations(array($material, $problem), 0, 60000);
page_expect($overlap['categorySpans'], $reversed['categorySpans'], 'Category ownership is independent of repository input order');
page_expect($overlap['pageCategories'], $reversed['pageCategories'], 'Category totals are deterministic under reversed tabs');
page_expect(null, page_bin($overlap, 'game')['activeSeconds'], 'A category never observed is not zero focus');
page_expect('no_data', page_bin($overlap, 'game')['status'], 'An absent category has explicit no_data quality');

$problem['events'][] = array('type' => 'focus', 'occurred_at' => 40000);
$focused = Calculator::page_timeline_for_participations(array($problem, $material), 0, 60000);
page_expect(40, page_bin($focused, 'problem')['activeSeconds'], 'Returning focus beats a more recently opened tab');
page_expect(20, page_bin($focused, 'material')['activeSeconds'], 'A heartbeat alone cannot steal deliberate focus');
page_assert_partition($focused, 60000, 60000, 'Focus transition');
$background = $material;
$background['events'][0]['meta'] = array('visible' => false, 'focused' => false);
$preferred = Calculator::page_timeline_for_participations(array($problem, $background), 0, 60000);
page_expect(60, page_bin($preferred, 'problem')['activeSeconds'], 'An active older tab beats a hidden newer tab');
page_expect(0, page_bin($preferred, 'material')['observedSeconds'], 'A background visit is counted but owns no duplicate duration');
page_expect(1, page_bin($preferred, 'material')['pageViews'], 'A real background page visit can have zero exclusive duration');

$legacy = page_session(3, 'problem', 0, 30000);
$legacy['page_context_version'] = 0;
$unknown = Calculator::page_timeline_for_participations(array($legacy), 0, 30000);
page_expect(30, page_bin($unknown, 'unclassified')['activeSeconds'], 'Old problem IDs are not guessed into a page category');
page_expect(true, $unknown['pageCategoryCoverageUnknown'], 'Legacy category coverage is explicitly unknown');
page_expect(null, $unknown['pages'][0]['pageKey'], 'Legacy page keys are not fabricated');
page_assert_partition($unknown, 30000, 30000, 'Legacy observations');

$practice = Calculator::page_timeline_for_participations(array(page_session(4, 'practice', 0, 30000), page_session(5, 'practice', 30000, 60000, array(), 'ml')), 0, 60000);
page_expect(30, page_bin($practice, 'practice')['observedSeconds'], 'General practice excludes the ML subtype');
page_expect(30, page_bin($practice, 'practice', 'ml')['observedSeconds'], 'ML occupies its own exclusive practice bin');
page_assert_partition($practice, 60000, 60000, 'Practice and ML');
$submissions = Calculator::page_timeline_for_participations(array(page_session(6, 'submission', 0, 30000, array(), 'form'), page_session(7, 'submission', 30000, 60000, array(), 'result')), 0, 60000);
page_expect(60, page_bin($submissions, 'submission')['observedSeconds'], 'Submission form and result share one public category total');
page_expect(array('form', 'result'), array_column($submissions['pages'], 'subtype'), 'Raw page history preserves the form/result distinction');
$fractional = Calculator::page_timeline_for_participations(array(page_session(8, 'material', 0, 1001), page_session(9, 'game', 1001, 2000)), 0, 2000);
page_assert_partition($fractional, 2000, 2000, 'Fractional seconds');

$gap = page_session(10, 'material', 0, 90000);
$gap['events'] = array($gap['events'][0]);
$gapTimeline = Calculator::page_timeline_for_participations(array($gap), 0, 90000);
page_assert_partition($gapTimeline, 45000, 45000, 'Unwitnessed heartbeat gap');
page_expect(true, Calculator::calculate_for_participations(array($gap), 0, 90000)['has_observation_gaps'], 'A navigation or observation gap remains unknown rather than fabricated active time');

foreach (array(array('material', null), array('discussion', null), array('other', null), array('game', null), array('practice', null), array('practice', 'ml'), array('submission', 'result'), array('submission', null)) as [$category, $subtype]) {
    $metrics = Calculator::calculate_for_participations(array(page_session(11, $category, 0, 60000, array(), $subtype)), 0, 60000);
    page_expect(false, $metrics['activity_eligible'], $category . ' is neutral when semantic editing is unsupported');
    page_expect(null, $metrics['activity_window_ratio'], $category . ' activity ratio is unevaluated rather than zero');
    page_expect(null, $metrics['score_activity'], $category . ' receives no fabricated edit score');
    page_expect(20.0, $metrics['score_total'], $category . ' keeps the original full scale through eligible-weight normalization');
    page_expect(false, $metrics['needs_review'], $category . ' reading without edits does not trigger low-edit suspicion');
}
$code = Calculator::calculate_for_participations(array(page_session(12, 'submission', 0, 60000, array(), 'form')), 0, 60000);
page_expect(true, $code['activity_eligible'], 'The supported submission editor retains its editing rubric');
page_expect(0.0, $code['score_activity'], 'A genuine eligible editor with no editing retains the existing activity result');
$mixed = Calculator::calculate_for_participations(array(page_session(13, 'problem', 0, 30000, array(array('type' => 'activity', 'occurred_at' => 5000))), page_session(14, 'material', 30000, 60000)), 0, 60000);
page_expect(100.0, $mixed['activity_window_ratio'], 'Reading-only windows are excluded from a mixed activity denominator');
page_expect(20.0, $mixed['score_total'], 'Moving from supported coding into reading causes no score reduction');
$fakeWork = Calculator::calculate_for_participations(array(page_session(15, 'problem', 0, 30000), page_session(16, 'material', 30000, 60000, array(array('type' => 'activity', 'occurred_at' => 35000)))), 0, 60000);
page_expect(0.0, $fakeWork['activity_window_ratio'], 'A neutral page’s generic activity is not invented coding progress');
$legacyScore = Calculator::calculate_for_participations(array($legacy), 0, 30000);
page_expect(true, $legacyScore['activity_eligible'], 'Legacy unclassified sessions retain the previous scoring policy');
page_expect(14.0, $legacyScore['score_total'], 'Legacy low-edit scores are not silently reinterpreted by the category feature');
$noEligible = Calculator::calculate_for_participations(array(page_session(19, 'game', 0, 60000)), 0, 60000, array('focus' => 0, 'selfreg' => 0, 'activity' => 6));
page_expect(null, $noEligible['score_total'], 'Unsupported gameplay cannot receive a zero when only editing was weighted');
page_expect(false, $noEligible['score_eligible'], 'No supported weighted component is explicitly unevaluated');
$zeroSigned = Calculator::calculate_for_participations(array(page_session(19, 'material', 0, 60000)), 0, 60000, array('focus' => 0, 'selfreg' => 0, 'activity' => 0));
page_expect(null, $zeroSigned['score_total'], 'Signed reading with all weights disabled remains unevaluated');
$zeroLegacy = Calculator::calculate_for_participations(array($legacy), 0, 30000, array('focus' => 0, 'selfreg' => 0, 'activity' => 0));
page_expect(0.0, $zeroLegacy['score_total'], 'Legacy zero-weight behavior remains compatible');

// Absolute milliseconds, rather than wall-clock labels, govern DST and day cuts.
foreach (array(
    array('2026-03-08T01:59:45-05:00', '2026-03-08T03:00:15-04:00', 'Spring DST jump'),
    array('2026-11-01T01:59:45-04:00', '2026-11-01T01:00:15-05:00', 'Autumn repeated hour'),
) as [$from, $to, $label]) {
    $a = (new DateTimeImmutable($from))->getTimestamp() * 1000;
    $b = (new DateTimeImmutable($to))->getTimestamp() * 1000;
    page_assert_partition(Calculator::page_timeline_for_participations(array(page_session(20, 'material', $a, $b)), $a, $b), 30000, 30000, $label);
}
foreach (array(array('2028-02-29T23:59:45+00:00', '2028-03-01T00:00:00+00:00', 'Leap month boundary'),
    array('2026-12-31T23:59:45+00:00', '2027-01-01T00:00:00+00:00', 'Calendar year boundary')) as [$from, $midnight, $label]) {
    $a = (new DateTimeImmutable($from))->getTimestamp() * 1000;
    $cut = (new DateTimeImmutable($midnight))->getTimestamp() * 1000;
    $b = $cut + 15000;
    $crossing = page_session(21, 'discussion', $a, $b);
    page_assert_partition(Calculator::page_timeline_for_participations(array($crossing), $a, $cut), 15000, 15000, $label . ' before midnight');
    page_assert_partition(Calculator::page_timeline_for_participations(array($crossing), $cut, $b), 15000, 15000, $label . ' after midnight');
}
$many = array();
for ($id = 1; $id <= 501; ++$id) { $many[] = page_session(100 + $id, 'game', 0, 1000); }
$bounded = Calculator::page_timeline_for_participations($many, 0, 1000);
page_expect(500, count($bounded['pages']), 'Raw page history is bounded to five hundred records');
page_expect(true, $bounded['pagesTruncated'], 'A bounded raw history explicitly reports truncation');
page_expect(501, page_bin($bounded, 'game')['sessionCount'], 'History truncation does not silently truncate category counts');
page_assert_partition($bounded, 1000, 1000, 'Bounded history with many overlapping tabs');
echo 'page-attribution: ' . $assertions . " checks passed\n";
