<?php
declare(strict_types=1);

namespace FocusTracker\Scoring;

use FocusTracker\Database\Event_Repository;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Database\Score_Repository;
use FocusTracker\Privacy\Privacy_Guard;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;

/** Live observations are recomputed; only settled activity windows reuse a cache. */
final class Activity_Score_Service {
    public static function score_for_learner(array $activity, int $user_id, bool $force_refresh = false, bool $for_retention = false): array {
        $activity_id = (int) $activity['id'];
        $now_ms = Clock::now_ms();
        $start = Clock::mysql_to_ms((string) $activity['starts_at']);
        $end = Clock::mysql_to_ms((string) $activity['ends_at']);
        $window_end = max($start, min($now_ms, $end));
        $finalized = $end > $start && $now_ms > $end + Score_Calculator::HEARTBEAT_GRACE_MS;
        $privacy_cutoff = Privacy_Guard::cutoff($user_id);
        $privacy_gap = $privacy_cutoff > 0 && $start <= $privacy_cutoff;
        if ($privacy_gap && ! $for_retention) {
            $metrics = self::empty_metrics('unavailable', $finalized);
            $metrics['has_observation_gaps'] = true;
            return $metrics;
        }
        $retention_boundary = Settings::aggregate_cutoff($now_ms);
        if (! $for_retention && $retention_boundary !== null && $end <= $retention_boundary->getTimestamp() * 1000) {
            return self::empty_metrics('unavailable', $finalized);
        }
        $participations = self::participations_for_activity(
            $activity, Participation_Repository::list_for_activity_and_user($activity_id, $user_id), $now_ms
        );
        $signature = self::signature($activity, $participations);
        if ($privacy_cutoff > 0) { $signature = hash('sha256', $signature . ':privacy:' . $privacy_cutoff); }
        $has_gaps = ! empty(array_filter($participations, static fn (array $p): bool => ! empty($p['has_delivery_gaps'])));
        $expired = ! empty(array_filter($participations, static fn (array $p): bool => ! empty($p['raw_events_expired'])));
        $cached = Score_Repository::get($activity_id, $user_id);

        // A refresh or changed weight must not replace a historical snapshot
        // with a score reconstructed from already-deleted source events.
        if ($expired || (null !== $cached && ! empty($cached['raw_events_expired']))) {
            if (null !== $cached && ! empty($cached['finalized'])) {
                $metrics = self::present_cached($cached);
                $metrics['data_status'] = 'archived';
                $metrics['needs_review'] = $metrics['needs_review'] || ($cached['cache_signature'] ?? '') !== $signature;
                $metrics['has_delivery_gaps'] = $metrics['has_delivery_gaps'] || $has_gaps;
                $metrics['needs_review'] = $metrics['needs_review'] || $metrics['has_delivery_gaps'];
                return $metrics;
            }
            return self::empty_metrics('unavailable', $finalized, $has_gaps);
        }
        if (! $force_refresh && $finalized && null !== $cached && ! empty($cached['finalized']) && ($cached['cache_signature'] ?? '') === $signature) {
            $metrics = self::present_cached($cached);
            $metrics['has_delivery_gaps'] = $metrics['has_delivery_gaps'] || $has_gaps;
            $metrics['data_status'] = empty($participations) ? 'no_data' : (($metrics['has_delivery_gaps'] || $metrics['has_observation_gaps']) ? 'incomplete' : 'complete');
            $metrics['needs_review'] = $metrics['needs_review'] || $metrics['has_delivery_gaps'] || empty($participations);
            return $metrics;
        }
        if (empty($participations) || $window_end <= $start) {
            $metrics = self::empty_metrics('no_data', $finalized, $has_gaps);
        } else {
            $inputs = self::participation_inputs($participations);
            // Legacy retention or a failed write may have left participation
            // boundaries but no source events. Boundaries alone prove no focus.
            $observed_inputs = array_values(array_filter($inputs, static fn (array $p): bool => ! empty($p['events'])));
            if (empty($observed_inputs)) {
                return self::empty_metrics('unavailable', $finalized, $has_gaps);
            }
            $missing_source = count($observed_inputs) !== count($inputs);
            $has_gaps = $has_gaps || $missing_source;
            $metrics = Score_Calculator::calculate_for_participations($observed_inputs, $start, $window_end, array(
                'focus' => (float) $activity['weight_focus'],
                'selfreg' => (float) $activity['weight_selfreg'],
                'activity' => (float) $activity['weight_activity'],
            ), (int) Settings::get('activity_window_seconds'), $now_ms);
            $metrics['data_status'] = ($has_gaps || $metrics['has_observation_gaps']) ? 'incomplete' : ($finalized ? 'complete' : 'provisional');
            $metrics['has_delivery_gaps'] = $has_gaps;
            $metrics['needs_review'] = $metrics['needs_review'] || $has_gaps;
            $metrics['finalized'] = $finalized;
            if ($missing_source) {
                $metrics['cached'] = false;
                $metrics['cache_saved'] = false;
                return $metrics;
            }
        }
        $metrics['cache_signature'] = $signature;
        if ($privacy_gap) {
            $metrics['has_observation_gaps'] = true;
            $metrics['needs_review'] = true;
            $metrics['data_status'] = 'incomplete';
        }
        $metrics['raw_events_expired'] = false;
        $metrics['participation_count'] = count($participations);
        $metrics['cache_saved'] = Score_Repository::upsert($activity_id, $user_id, $metrics);
        $metrics['cached'] = false;
        return $metrics;
    }

    public static function scores_for_activity(array $activity, array $learner_ids, bool $force_refresh = false): array {
        $scores = array();
        foreach ($learner_ids as $learner_id) {
            $scores[$learner_id] = self::score_for_learner($activity, $learner_id, $force_refresh);
        }
        return $scores;
    }

    /** Source state shared by the scorer and REST timeline. */
    public static function participations_for_activity(array $activity, array $participations, ?int $now_ms = null): array {
        $now_ms = $now_ms ?? Clock::now_ms();
        $start = Clock::mysql_to_ms((string) $activity['starts_at']);
        $end = min($now_ms, Clock::mysql_to_ms((string) $activity['ends_at']));
        $problems = $activity['problem_ids'] ?? null;
        return array_values(array_filter($participations, static function (array $p) use ($now_ms, $start, $end, $problems): bool {
            if ($end <= $start || (null !== $problems && ! in_array((int) ($p['problem_id'] ?? 0), $problems, true))) {
                return false;
            }
            $participation_end = $p['ended_at_ms'] ?? null;
            if (null === $participation_end) {
                $heartbeat = min($now_ms, (int) $p['last_heartbeat_at_ms']);
                $participation_end = $now_ms - $heartbeat <= Score_Calculator::HEARTBEAT_GRACE_MS ? $now_ms : $heartbeat;
            }
            return (int) $p['started_at_ms'] < $end && (int) $participation_end > $start;
        }));
    }

    /** Source state shared by the scorer and REST timeline. */
    public static function participation_inputs(array $participations): array {
        $events = Event_Repository::list_for_participations(array_map(static fn (array $p): int => (int) $p['id'], $participations));
        return array_map(static function (array $p) use ($events): array {
            return array_merge($p, array('events' => $events[(int) $p['id']] ?? array()));
        }, $participations);
    }

    public static function invalidate(int $activity_id, int $user_id): void {
        Score_Repository::invalidate($activity_id, $user_id);
    }

    private static function signature(array $activity, array $participations): string {
        $problems = $activity['problem_ids'] ?? null;
        if (is_array($problems)) { sort($problems, SORT_NUMERIC); }
        $revisions = array_map(static fn (array $p): array => array(
            (int) $p['id'], (int) $p['started_at_ms'], $p['ended_at_ms'],
            (int) $p['last_heartbeat_at_ms'], (int) ($p['event_revision'] ?? 0), ! empty($p['has_delivery_gaps']), $p['problem_id'] ?? null,
        ), $participations);
        usort($revisions, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        return hash('sha256', (string) json_encode(array(
            Score_Calculator::VERSION, (string) $activity['starts_at'], (string) $activity['ends_at'],
            (float) $activity['weight_focus'], (float) $activity['weight_selfreg'], (float) $activity['weight_activity'],
            (int) Settings::get('activity_window_seconds'),
            $problems, $revisions,
        )));
    }

    private static function present_cached(array $cached): array {
        $metrics = array();
        foreach (array('total_active_seconds', 'total_hidden_seconds', 'blur_count') as $key) {
            $metrics[$key] = (int) $cached[$key];
        }
        foreach (array('focus_ratio', 'avg_recovery_seconds', 'activity_window_ratio', 'score_focus', 'score_selfreg', 'score_activity', 'score_total') as $key) {
            $metrics[$key] = (float) $cached[$key];
        }
        $metrics['abandoned_without_return'] = ! empty($cached['abandoned_without_return']);
        $metrics['needs_review'] = $metrics['focus_ratio'] >= 95.0 && $metrics['activity_window_ratio'] <= 5.0;
        $metrics['finalized'] = ! empty($cached['finalized']);
        $metrics['has_delivery_gaps'] = ! empty($cached['has_delivery_gaps']);
        $metrics['has_observation_gaps'] = ! empty($cached['has_observation_gaps']);
        $metrics['needs_review'] = $metrics['needs_review'] || $metrics['has_observation_gaps'] || $metrics['has_delivery_gaps'];
        $metrics['data_status'] = $metrics['finalized'] ? 'complete' : 'provisional';
        $metrics['cached'] = true;
        $metrics['cache_saved'] = true;
        return $metrics;
    }

    private static function empty_metrics(string $status, bool $finalized, bool $has_gaps = false): array {
        return array(
            'total_active_seconds' => 0, 'total_hidden_seconds' => 0, 'focus_ratio' => 0.0,
            'blur_count' => 0, 'avg_recovery_seconds' => 0.0, 'abandoned_without_return' => false,
            'activity_window_ratio' => 0.0, 'score_focus' => 0.0, 'score_selfreg' => 0.0,
            'score_activity' => 0.0, 'score_total' => 0.0, 'needs_review' => true,
            'data_status' => $status, 'finalized' => $finalized, 'cached' => false, 'has_delivery_gaps' => $has_gaps,
            'has_observation_gaps' => false,
            'cache_saved' => false,
        );
    }
}
