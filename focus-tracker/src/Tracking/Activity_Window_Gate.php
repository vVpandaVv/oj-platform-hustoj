<?php
declare(strict_types=1);

namespace FocusTracker\Tracking;

use FocusTracker\Addon\Context_Resolver;
use FocusTracker\Database\Activity_Repository;
use FocusTracker\Support\Clock;
use Throwable;
use WP_Error;

/** Authoritative activity windows, shared by browser leases, ingestion and checks. */
final class Activity_Window_Gate {
    private const LEASE_MS = 10000;
    private const HORIZON_MS = 86400000;
    private const MAX_WINDOWS = 128;

    public static function state(int $user_id, ?int $problem_id, string $context_type = 'general', string|int|null $context_id = null, ?int $now_ms = null, bool $lock = false): array|WP_Error {
        $now_ms = $now_ms ?? Clock::now_ms();
        $windows = self::windows($user_id, $problem_id, $now_ms - self::HORIZON_MS, $now_ms + self::HORIZON_MS, $context_type, $context_id, $lock);
        if ($windows instanceof WP_Error) { return $windows; }
        $active = null;
        $next = null;
        foreach (self::union($windows) as $run) {
            if ($run['startMs'] <= $now_ms && $now_ms < $run['endMs']) { $active = $run; }
            elseif ($run['startMs'] > $now_ms) { $next = $next === null ? $run['startMs'] : min($next, $run['startMs']); }
        }
        $valid = min($now_ms + self::LEASE_MS, $active['endMs'] ?? PHP_INT_MAX, $next ?? PHP_INT_MAX);
        $canonical_id = $context_type === 'general' ? null : (string) $context_id;
        foreach ($windows as $window) {
            if ($window['contextType'] === $context_type && self::same_context($context_type, $window['contextId'], (string) $context_id)) { $canonical_id = $window['contextId']; break; }
        }
        return array(
            'serverNowMs' => $now_ms, 'nowMs' => $now_ms, 'active' => $active !== null,
            'windows' => $windows, 'activityIds' => $active['activityIds'] ?? array(),
            'windowStartsAtMs' => $active['startMs'] ?? null, 'windowEndsAtMs' => $active['endMs'] ?? null,
            'activeUntilMs' => $active['endMs'] ?? null, 'nextStartsAtMs' => $next,
            'leaseUntilMs' => $valid, 'validUntilMs' => $valid,
            'contextType' => $context_type, 'contextId' => $canonical_id,
            'reason' => $active !== null ? 'active' : ($next !== null ? 'scheduled' : 'no_activity'),
            'revision' => hash('sha256', (string) wp_json_encode($windows)),
        );
    }

    /** @return list<array{activityId:int,startMs:int,endMs:int,contextType:string,contextId:string}>|WP_Error */
    public static function windows(int $user_id, ?int $problem_id, int $from_ms, int $until_ms, string $context_type = 'general', string|int|null $context_id = null, bool $lock = false): array|WP_Error {
        if ($user_id < 1 || $user_id !== get_current_user_id()) { return new WP_Error('focus_tracker_account_changed', '로그인 계정이 변경되었습니다.', array('status' => 403)); }
        if (! in_array($context_type, array('general', 'class', 'group', 'contest'), true)
            || ($context_type === 'general' ? $context_id !== null && $context_id !== '' : $context_id === null || (string) $context_id === '')
            || ($context_id !== null && (strlen((string) $context_id) > 160 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', (string) $context_id) !== 1))) {
            return new WP_Error('focus_tracker_invalid_context', '학습 문맥이 올바르지 않습니다.', array('status' => 400));
        }
        if ($problem_id === null || $problem_id < 1 || $until_ms <= $from_ms || $context_type === 'contest') { return array(); }
        try {
            $successful = false;
            $contexts = Context_Resolver::list_learning_contexts($user_id, $successful);
            if (! $successful) { return self::unavailable(); }
            $windows = array();
            $authorized_contexts = array();
            foreach ($contexts as $context) {
                if (! in_array($context['type'], array('class', 'group'), true)) { continue; }
                if ($context_type !== 'general' && ($context['type'] !== $context_type || ! self::same_context($context_type, $context['id'], (string) $context_id))) { continue; }
                $authorized_contexts[] = $context;
            }
            if (empty($authorized_contexts)) { return array(); }
            $activities = Activity_Repository::find_overlapping_for_contexts($authorized_contexts, Clock::ms_to_mysql($from_ms), Clock::ms_to_mysql($until_ms), $lock);
            global $wpdb;
            if ('' !== (string) $wpdb->last_error) { return self::unavailable(); }
            foreach ($activities as $activity) {
                if ($activity['status'] === Activity_Repository::STATUS_DELETED) { continue; }
                $start = Clock::mysql_to_ms($activity['starts_at']);
                $end = Clock::mysql_to_ms($activity['ends_at']);
                if ($activity['status'] === Activity_Repository::STATUS_CLOSED && ! empty($activity['updated_at'])) {
                    $end = min($end, Clock::mysql_to_ms($activity['updated_at']));
                }
                if ($start >= $until_ms || $end <= $from_ms || $end <= $start
                    || ($activity['problem_ids'] !== null && ! in_array($problem_id, $activity['problem_ids'], true))) { continue; }
                $windows[$activity['id']] = array('activityId' => $activity['id'], 'startMs' => $start, 'endMs' => $end,
                    'contextType' => $activity['context_type'], 'contextId' => $activity['context_id']);
                if (count($windows) > self::MAX_WINDOWS) { return self::unavailable(); }
            }
            $windows = array_values($windows);
            usort($windows, static fn (array $a, array $b): int => array($a['startMs'], $a['endMs'], $a['activityId']) <=> array($b['startMs'], $b['endMs'], $b['activityId']));
            return $windows;
        } catch (Throwable $error) { return self::unavailable(); }
    }

    /** Merge overlapping and adjacent authorizations without duplicating raw events. */
    public static function union(array $windows): array {
        usort($windows, static fn (array $a, array $b): int => array($a['startMs'], $a['endMs']) <=> array($b['startMs'], $b['endMs']));
        $runs = array();
        foreach ($windows as $window) {
            $last = count($runs) - 1;
            if ($last >= 0 && $window['startMs'] <= $runs[$last]['endMs']) {
                $runs[$last]['endMs'] = max($runs[$last]['endMs'], $window['endMs']);
                $runs[$last]['activityIds'][] = $window['activityId'];
            } else { $runs[] = array('startMs' => $window['startMs'], 'endMs' => $window['endMs'], 'activityIds' => array($window['activityId'])); }
        }
        foreach ($runs as &$run) { $run['activityIds'] = array_values(array_unique($run['activityIds'])); sort($run['activityIds'], SORT_NUMERIC); }
        unset($run);
        return $runs;
    }

    public static function same_context(string $type, string $left, string $right): bool {
        $prefix = $type . ':';
        if (str_starts_with($left, $prefix)) { $left = substr($left, strlen($prefix)); }
        if (str_starts_with($right, $prefix)) { $right = substr($right, strlen($prefix)); }
        return $left !== '' && hash_equals($left, $right);
    }

    private static function unavailable(): WP_Error { return new WP_Error('focus_tracker_schedule_unavailable', '활동 시간과 참여 권한을 확인하지 못했습니다. 잠시 후 다시 시도해 주세요.', array('status' => 503)); }
}
