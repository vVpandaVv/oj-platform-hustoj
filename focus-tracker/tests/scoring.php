<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Scoring/Score_Calculator.php';
use FocusTracker\Scoring\Score_Calculator as Calculator;

$checks = 0;
function expect_same(mixed $expected, mixed $actual, string $label): void {
    global $checks;
    ++$checks;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function event(string $type, int $time, array $meta = array(), int $seq = 0): array {
    return array('type' => $type, 'occurred_at' => $time, 'meta' => $meta, 'seq' => $seq);
}
function participation(int $start, ?int $end, array $events = array(), ?int $heartbeat = null): array {
    return array('started_at_ms' => $start, 'ended_at_ms' => $end, 'last_heartbeat_at_ms' => $heartbeat ?? $end ?? $start, 'events' => $events);
}
function score(array $parts, int $start = 0, int $end = 60000, ?int $observed = null): array {
    return Calculator::calculate_for_participations($parts, $start, $end, array(), 30, $observed ?? $end);
}
$work = array(event('activity', 5000), event('activity', 35000));
$a = participation(0, 60000, $work);
expect_same(20.0, score(array($a))['score_total'], 'perfect observations');
expect_same(0.3, Calculator::calculate_for_participations(array($a), 0, 60000, array('focus' => 0.1, 'selfreg' => 0.1, 'activity' => 0.1))['score_total'], 'half-point rounding never exceeds configured total');
expect_same(0.0, Calculator::calculate_for_participations(array($a), 0, 60000, array('focus' => -1, 'selfreg' => INF, 'activity' => NAN))['score_total'], 'invalid pure-calculator weights cannot create negative or nonfinite scores');
$idle = score(array(participation(0, 60000, array(event('heartbeat', 15000), event('heartbeat', 30000), event('heartbeat', 45000)))));
expect_same(14.0, $idle['score_total'], 'rubric unchanged for idle page');
expect_same(true, $idle['needs_review'], 'idle page requires human review');
expect_same(0.0, score(array())['score_total'], 'no participation is not a positive score');
expect_same(0.0, score(array($a), 100, 100)['score_total'], 'zero interval');
expect_same(0.0, score(array($a), 100, 0)['score_total'], 'negative interval');

$live = score(array(participation(0, null, $work, 59999)));
expect_same(20.0, $live['score_total'], 'one millisecond heartbeat tail is alive');
expect_same(false, $live['abandoned_without_return'], 'fresh heartbeat is not abandonment');
expect_same(6.0, $live['score_selfreg'], 'fresh heartbeat earns normal self-regulation');
$stale = score(array(participation(0, null, array(), 10000)), 0, 60000, 60000);
expect_same(10, $stale['total_active_seconds'], 'stale session ends at last actual signal');
expect_same(true, $stale['abandoned_without_return'], 'genuine abandonment remains counted');
expect_same(60, score(array(participation(0, null, $work, 90000)), 0, 60000, 90000)['total_active_seconds'], 'heartbeat after activity end clips to end');
expect_same(10, score(array(participation(0, 60000, $work)), 0, 60000, 10000)['total_active_seconds'], 'never observe future time');

$b = participation(10000, 30000, array(event('session_start', 10000, array('visible' => false, 'focused' => false))));
expect_same(20.0, score(array($a, $b))['score_total'], 'background tab closing cannot hide foreground tab');
expect_same(20.0, score(array($b, $a))['score_total'], 'participation order does not matter');
expect_same(0, score(array($b))['total_active_seconds'], 'initially hidden tab is never invented as active');
$adjacent = array(participation(0, 30000, array(event('activity', 5000))), participation(30000, 60000, array(event('activity', 35000))));
expect_same(20.0, score($adjacent)['score_total'], 'refresh with adjacent sessions stays continuous');
expect_same(0, score($adjacent)['blur_count'], 'refresh has no phantom recovery');
$gapped = array(participation(0, 10000), participation(20000, 60000));
expect_same(1, score($gapped)['blur_count'], 'real gap is one recovery');
expect_same(10.0, score($gapped)['avg_recovery_seconds'], 'real gap recovery length');

$transitions = array(event('blur', 10000), event('hidden', 11000), event('visible', 20000, array('visible' => true, 'focused' => false)), event('focus', 30000));
expect_same(40, score(array(participation(0, 60000, $transitions)))['total_active_seconds'], 'visibility and focus state remain distinct');
expect_same(1, score(array(participation(0, 60000, $transitions)))['blur_count'], 'overlapping away signals count once');
$hidden_focus = array(event('session_start', 0, array('visible' => false, 'focused' => false)), event('focus', 5000), event('visible', 10000), event('heartbeat', 45000));
expect_same(50, score(array(participation(0, 60000, $hidden_focus)))['total_active_seconds'], 'focus alone cannot revive hidden document');
$preopened = participation(-10000, 60000, array(event('session_start', -10000, array('visible' => true, 'focused' => true)), event('activity', 5000), event('activity', 35000)));
expect_same(20.0, score(array($preopened))['score_total'], 'page opened before activity retains state');
$pre_hidden = participation(-10000, 60000, array(event('hidden', -5000), event('visible', 20000)));
expect_same(40, score(array($pre_hidden))['total_active_seconds'], 'state before activity boundary is replayed');
$snapshot = participation(0, 60000, array(event('heartbeat', 10000, array('visible' => false, 'focused' => false)), event('heartbeat', 20000, array('visible' => true, 'focused' => true))));
expect_same(50, score(array($snapshot))['total_active_seconds'], 'heartbeat state repairs missed transition');
$ordered = participation(0, 60000, array(event('focus', 10000, array(), 3), event('blur', 10000, array(), 2), event('heartbeat', 45000)));
expect_same(60, score(array($ordered))['total_active_seconds'], 'sequence resolves same-millisecond transitions');

$hidden_work = participation(0, 60000, array(event('hidden', 5000), event('activity', 10000), event('visible', 15000)));
expect_same(0.0, score(array($hidden_work))['activity_window_ratio'], 'hidden activity cannot earn work credit');
$boundary = participation(0, 60000, array(event('activity', -1), event('activity', 60000)));
expect_same(0.0, score(array($boundary))['activity_window_ratio'], 'work outside half-open activity window is excluded');
$half = participation(0, 30000, array(event('hidden', 15000), event('activity', 10000)));
expect_same(0.0, score(array($half), 0, 30000)['activity_window_ratio'], 'exactly half visible bucket remains ineligible');
$timeline = Calculator::timeline_for_participations(array(participation(0, 60000, array(event('blur', 10000), event('focus', 20000)))), 0, 60000);
expect_same(array(array('startedAt' => 0, 'endedAt' => 10000), array('startedAt' => 20000, 'endedAt' => 60000)), $timeline['intervals'], 'timeline displays actual active intervals');

$network_gap = participation(0, 600000, array(event('session_start', 0), event('heartbeat', 15000), event('heartbeat', 600000)), 600000);
$uncertain = score(array($network_gap), 0, 600000, 600000);
expect_same(60, $uncertain['total_active_seconds'], 'later fresh heartbeat cannot bridge a long observation gap');
expect_same(true, $uncertain['has_observation_gaps'], 'unwitnessed active stretch is explicit');
expect_same(true, $uncertain['needs_review'], 'unwitnessed active stretch requires review');
expect_same(45, score(array(participation(0, 600000)), 0, 600000)['total_active_seconds'], 'explicit close cannot prove ten minutes of activity');
$hidden_long = participation(0, 600000, array(event('session_start', 0, array('visible' => false, 'focused' => false)), event('visible', 590000)));
expect_same(false, score(array($hidden_long), 0, 600000)['has_observation_gaps'], 'known hidden period needs no active heartbeat');
$cover_events = array();
for ($at = 0; $at < 600000; $at += 15000) { $cover_events[] = event('heartbeat', $at); }
$cover = participation(0, 600000, $cover_events);
expect_same(false, score(array($network_gap, $cover), 0, 600000)['has_observation_gaps'], 'another observed tab covers an unknown tab gap');
expect_same(600, score(array($network_gap, $cover), 0, 600000)['total_active_seconds'], 'covered gap has complete presence evidence');
expect_same(false, score(array($network_gap), 600000, 630000, 630000)['has_observation_gaps'], 'gap wholly before activity does not contaminate its quality');

// Exhaust every combination of two tabs' three one-second visibility slots.
// The independent oracle is a per-slot OR; it does not use interval merging.
for ($left = 0; $left < 8; ++$left) {
    for ($right = 0; $right < 8; ++$right) {
        $parts = array();
        $expected_seconds = 0;
        for ($slot = 0; $slot < 3; ++$slot) {
            if (($left & (1 << $slot)) || ($right & (1 << $slot))) { ++$expected_seconds; }
        }
        foreach (array($left, $right) as $pattern) {
            $events = array();
            for ($slot = 0; $slot < 3; ++$slot) {
                $on = (bool) ($pattern & (1 << $slot));
                $events[] = event('heartbeat', $slot * 1000, array('visible' => $on, 'focused' => $on));
            }
            $parts[] = participation(0, 3000, $events);
        }
        $result = score($parts, 0, 3000);
        expect_same($expected_seconds, $result['total_active_seconds'], 'exhaustive union ' . $left . '/' . $right);
        expect_same(3 - $expected_seconds, $result['total_hidden_seconds'], 'exhaustive complement ' . $left . '/' . $right);
        $intervals = Calculator::timeline_for_participations($parts, 0, 3000)['intervals'];
        $length = array_sum(array_map(static fn (array $i): int => $i['endedAt'] - $i['startedAt'], $intervals));
        expect_same($expected_seconds * 1000, $length, 'score/timeline agree ' . $left . '/' . $right);
    }
}
echo 'scoring: ' . $checks . " checks passed\n";
