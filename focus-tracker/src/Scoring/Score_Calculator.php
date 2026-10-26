<?php
declare(strict_types=1);

namespace FocusTracker\Scoring;

/** Browser observations only: these scores do not measure cognitive attention. */
final class Score_Calculator {
    public const HEARTBEAT_GRACE_MS = 45000;
    public const VERSION = '4';
    private const DEFAULT_FOCUS_BANDS = array(
        array(95.0, 1.0), array(90.0, 0.83), array(80.0, 0.67),
        array(70.0, 0.5), array(60.0, 0.33), array(0.0, 0.17),
    );
    private const DEFAULT_RECOVERY_BANDS = array(
        array(10.0, 1.0), array(30.0, 0.8), array(60.0, 0.6),
        array(120.0, 0.4), array(PHP_FLOAT_MAX, 0.2),
    );
    private const ACTIVITY_EVENT_TYPES = array('activity', 'run', 'submit');

    /** Score a single known interval without a WordPress dependency. */
    public static function calculate(array $events, int $session_start_ms, int $session_end_ms, array $weights = array(), int $activity_window_seconds = 30, bool $initial_away = false): array {
        return self::calculate_for_participations(array(array(
            'started_at_ms' => $session_start_ms,
            'ended_at_ms' => $session_end_ms,
            'last_heartbeat_at_ms' => $session_end_ms,
            'initial_away' => $initial_away,
            'events' => $events,
        )), $session_start_ms, $session_end_ms, $weights, $activity_window_seconds, $session_end_ms);
    }

    /** Score the union of each tab's active intervals, clipped to the activity. */
    public static function calculate_for_participations(array $participations, int $window_start_ms, int $window_end_ms, array $weights = array(), int $activity_window_seconds = 30, ?int $observed_at_ms = null): array {
        $timeline = self::timeline_for_participations($participations, $window_start_ms, $window_end_ms, $observed_at_ms);
        $duration_ms = max(0, $window_end_ms - $window_start_ms);
        $active_ms = 0;
        $away_intervals = array();
        $cursor = $window_start_ms;
        foreach ($timeline['intervals'] as $interval) {
            if ($interval['startedAt'] > $cursor) {
                $away_intervals[] = array('start_ms' => $cursor, 'duration_ms' => $interval['startedAt'] - $cursor, 'recovered' => true);
            }
            $active_ms += $interval['endedAt'] - $interval['startedAt'];
            $cursor = $interval['endedAt'];
        }
        $abandoned = $duration_ms > 0 && $cursor < $window_end_ms;
        if ($abandoned) {
            $away_intervals[] = array('start_ms' => $cursor, 'duration_ms' => $window_end_ms - $cursor, 'recovered' => false);
        }
        $recovered = array_values(array_filter($away_intervals, static fn (array $i): bool => $i['recovered']));
        $blur_count = count($recovered);
        $avg_recovery = $blur_count > 0 ? array_sum(array_column($recovered, 'duration_ms')) / $blur_count / 1000 : 0.0;
        $focus_ratio = $duration_ms > 0 ? 100.0 * $active_ms / $duration_ms : 0.0;
        $activity_ratio = self::activity_window_ratio($timeline['markers'], $away_intervals, $window_start_ms, $window_end_ms, $activity_window_seconds);
        $has_data = $duration_ms > 0 && ! empty($participations);
        $weight_focus = self::weight($weights['focus'] ?? 8.0);
        $weight_selfreg = self::weight($weights['selfreg'] ?? 6.0);
        $weight_activity = self::weight($weights['activity'] ?? 6.0);
        $focus = $has_data ? round($weight_focus * self::band_fraction_min(self::DEFAULT_FOCUS_BANDS, $focus_ratio), 1) : 0.0;
        $selfreg_fraction = $abandoned ? 0.2 : ($blur_count === 0 ? 1.0 : self::band_fraction_max(self::DEFAULT_RECOVERY_BANDS, $avg_recovery));
        $selfreg = $has_data ? round($weight_selfreg * $selfreg_fraction, 1) : 0.0;
        $activity = $has_data ? round($weight_activity * $activity_ratio / 100.0, 1) : 0.0;
        $maximum = round($weight_focus + $weight_selfreg + $weight_activity, 10);
        return array(
            'total_active_seconds' => (int) round($active_ms / 1000),
            'total_hidden_seconds' => (int) round(max(0, $duration_ms - $active_ms) / 1000),
            'focus_ratio' => round($focus_ratio, 2),
            'blur_count' => $blur_count,
            'avg_recovery_seconds' => round($avg_recovery, 2),
            'abandoned_without_return' => $has_data && $abandoned,
            'activity_window_ratio' => round($activity_ratio, 2),
            'score_focus' => $focus,
            'score_selfreg' => $selfreg,
            'score_activity' => $activity,
            'score_total' => min($maximum, round(($focus + $selfreg + $activity) * 2) / 2),
            'needs_review' => $timeline['has_observation_gaps'] || ($has_data && $focus_ratio >= 95.0 && $activity_ratio <= 5.0),
            'has_observation_gaps' => $timeline['has_observation_gaps'],
        );
    }

    /**
     * Shared source of truth for score computation and the teacher timeline.
     * Snapshot metadata repairs missed visibility/focus transitions. Legacy
     * sessions without snapshots retain their initially-visible interpretation.
     * @return array{intervals:list<array{startedAt:int,endedAt:int}>,markers:list<array{type:string,occurredAt:int}>,has_observation_gaps:bool}
     */
    public static function timeline_for_participations(array $participations, int $window_start_ms, int $window_end_ms, ?int $observed_at_ms = null): array {
        $observed_at_ms = $observed_at_ms ?? $window_end_ms;
        $intervals = array();
        $unknown = array();
        $markers = array();
        if ($window_end_ms <= $window_start_ms) {
            return array('intervals' => array(), 'markers' => array(), 'has_observation_gaps' => false);
        }
        foreach ($participations as $participation) {
            $start = (int) $participation['started_at_ms'];
            $heartbeat = min($observed_at_ms, max($start, (int) $participation['last_heartbeat_at_ms']));
            $end = $participation['ended_at_ms'] ?? null;
            if (null === $end) {
                $end = $observed_at_ms - $heartbeat <= self::HEARTBEAT_GRACE_MS ? $observed_at_ms : $heartbeat;
            }
            $end = min((int) $end, $window_end_ms, $observed_at_ms);
            if ($end < $window_start_ms || $end < $start || $start > $window_end_ms) {
                continue;
            }
            $events = $participation['events'] ?? array();
            usort($events, static function (array $a, array $b): int {
                $time = (int) $a['occurred_at'] <=> (int) $b['occurred_at'];
                return $time !== 0 ? $time : ((int) ($a['seq'] ?? 0) <=> (int) ($b['seq'] ?? 0));
            });
            $visible = empty($participation['initial_away']);
            $focused = $visible;
            $cursor = $start;
            foreach ($events as $event) {
                $at = (int) $event['occurred_at'];
                if ($at < $start || $at > $end) {
                    continue;
                }
                if ($visible && $focused) {
                    self::append_observed($intervals, $unknown, $cursor, $at, $window_start_ms, $window_end_ms);
                }
                $type = (string) $event['type'];
                $meta = isset($event['meta']) && is_array($event['meta']) ? $event['meta'] : array();
                if ($type === 'hidden') {
                    $visible = false;
                } elseif ($type === 'visible') {
                    $visible = true;
                    $focused = true;
                } elseif ($type === 'blur') {
                    $focused = false;
                } elseif ($type === 'focus') {
                    $focused = true;
                } elseif ($type === 'session_end') {
                    $visible = false;
                    $focused = false;
                }
                if (isset($meta['visible']) && is_bool($meta['visible'])) {
                    $visible = $meta['visible'];
                }
                if (isset($meta['focused']) && is_bool($meta['focused'])) {
                    $focused = $meta['focused'];
                }
                if ($visible && $focused && in_array($type, self::ACTIVITY_EVENT_TYPES, true) && $at >= $window_start_ms && $at < $window_end_ms) {
                    $markers[] = array('type' => $type, 'occurredAt' => $at);
                }
                $cursor = $at;
            }
            if ($visible && $focused) {
                self::append_observed($intervals, $unknown, $cursor, $end, $window_start_ms, $window_end_ms);
            }
        }
        usort($intervals, static fn (array $a, array $b): int => $a['startedAt'] <=> $b['startedAt']);
        $union = array();
        foreach ($intervals as $interval) {
            $last = count($union) - 1;
            if ($last >= 0 && $interval['startedAt'] <= $union[$last]['endedAt']) {
                $union[$last]['endedAt'] = max($union[$last]['endedAt'], $interval['endedAt']);
            } else {
                $union[] = $interval;
            }
        }
        usort($markers, static fn (array $a, array $b): int => $a['occurredAt'] <=> $b['occurredAt']);
        $has_gaps = false;
        foreach ($unknown as $gap) {
            $cursor = $gap['startedAt'];
            foreach ($union as $known) {
                if ($known['startedAt'] > $cursor) { break; }
                if ($known['endedAt'] > $cursor) { $cursor = $known['endedAt']; }
                if ($cursor >= $gap['endedAt']) { break; }
            }
            if ($cursor < $gap['endedAt']) {
                $has_gaps = true;
                break;
            }
        }
        return array('intervals' => $union, 'markers' => $markers, 'has_observation_gaps' => $has_gaps);
    }

    private static function append_observed(array &$intervals, array &$unknown, int $start, int $end, int $window_start, int $window_end): void {
        $confirmed_end = min($end, $start + self::HEARTBEAT_GRACE_MS);
        self::append_clipped($intervals, $start, $confirmed_end, $window_start, $window_end);
        self::append_clipped($unknown, $confirmed_end, $end, $window_start, $window_end);
    }

    private static function append_clipped(array &$intervals, int $start, int $end, int $window_start, int $window_end): void {
        $start = max($start, $window_start);
        $end = min($end, $window_end);
        if ($end > $start) {
            $intervals[] = array('startedAt' => $start, 'endedAt' => $end);
        }
    }

    /** Existing rubric: work in more-than-half-visible buckets is eligible. */
    private static function activity_window_ratio(array $markers, array $away_intervals, int $start, int $end, int $window_seconds): float {
        $window_ms = max(1, $window_seconds) * 1000;
        $duration_ms = max(0, $end - $start);
        if ($duration_ms === 0) { return 0.0; }
        $window_count = (int) ceil($duration_ms / $window_ms);
        $eligible = array_fill(0, $window_count, false);
        $active = array_fill(0, $window_count, false);
        for ($i = 0; $i < $window_count; $i++) {
            $from = $start + $i * $window_ms;
            $to = min($end, $from + $window_ms);
            $hidden = 0;
            foreach ($away_intervals as $interval) {
                $hidden += max(0, min($to, $interval['start_ms'] + $interval['duration_ms']) - max($from, $interval['start_ms']));
            }
            $eligible[$i] = $hidden * 2 < $to - $from;
        }
        foreach ($markers as $marker) {
            $index = (int) floor(($marker['occurredAt'] - $start) / $window_ms);
            if ($index >= 0 && $index < $window_count && $eligible[$index]) { $active[$index] = true; }
        }
        $denominator = count(array_filter($eligible));
        return $denominator > 0 ? 100.0 * count(array_filter($active)) / $denominator : 0.0;
    }

    private static function band_fraction_min(array $bands, float $value): float {
        foreach ($bands as [$threshold, $fraction]) {
            if ($value >= $threshold) { return $fraction; }
        }
        return 0.0;
    }
    private static function weight(mixed $value): float {
        $number = is_numeric($value) ? (float) $value : 0.0;
        return is_finite($number) ? max(0.0, min(100.0, $number)) : 0.0;
    }
    private static function band_fraction_max(array $bands, float $value): float {
        foreach ($bands as [$threshold, $fraction]) {
            if ($value <= $threshold) { return $fraction; }
        }
        return 0.0;
    }
}
