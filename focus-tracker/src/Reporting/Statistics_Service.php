<?php
declare(strict_types=1);

namespace FocusTracker\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use FocusTracker\Database\Event_Repository;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Database\Statistics_Repository as Repository;
use FocusTracker\Privacy\Privacy_Guard;
use FocusTracker\Scoring\Score_Calculator;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;
use InvalidArgumentException;
use RuntimeException;

/** Longitudinal browser participation, independent of activity grades. */
final class Statistics_Service {
    private const VERSION = '1';
    private const METADATA_CHUNK_DAYS = 7;
    private const CHECK_STATUSES = array('pending', 'correct', 'incorrect', 'unknown', 'skipped', 'unanswered', 'invalidated');

    public static function report(int $user_id, string $period, ?string $date = null, array $scope = array('type' => 'self', 'id' => ''), ?int $now_ms = null): array {
        if (! in_array($period, array('week', 'month'), true)) { throw new InvalidArgumentException('기간은 week 또는 month여야 합니다.'); }
        $now_ms = $now_ms ?? Clock::now_ms();
        $zone = wp_timezone();
        $now = self::at($now_ms, $zone);
        if ($date !== null && $date !== '' && (strlen($date) !== 10 || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1)) { throw new InvalidArgumentException('날짜는 YYYY-MM-DD 형식이어야 합니다.'); }
        $anchor = $date === null || $date === '' ? $now->setTime(0, 0) : DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if (! $anchor || ($date !== null && $date !== '' && $anchor->format('Y-m-d') !== $date)) { throw new InvalidArgumentException('날짜는 YYYY-MM-DD 형식이어야 합니다.'); }
        $start = $period === 'week' ? $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days') : $anchor->modify('first day of this month');
        $end = $start->modify($period === 'week' ? '+7 days' : '+1 month');
        $previous_start = $start->modify($period === 'week' ? '-7 days' : '-1 month');
        $through = max(self::ms($start), min($now_ms, self::ms($end)));
        $elapsed_days = (int) $start->diff(self::at($through, $zone)->setTime(0, 0))->format('%a');
        $through_time = self::at($through, $zone);
        $previous_through = $through >= self::ms($end) ? self::ms($start) : min(self::ms($start), self::ms($previous_start->modify('+' . $elapsed_days . ' days')->setTime((int) $through_time->format('H'), (int) $through_time->format('i'), (int) $through_time->format('s'), (int) $through_time->format('u'))));
        $days = self::series($user_id, $scope, $start, $end, $through, $now_ms);
        $previous_days = self::series($user_id, $scope, $previous_start, $start, $previous_through, $now_ms);
        $totals = self::sum($days);
        $previous = self::sum($previous_days);
        $delta = array();
        foreach (array('focusRatio', 'activeSeconds', 'observedSeconds') as $key) {
            $delta[$key] = $totals[$key] === null || $previous[$key] === null || in_array($totals['status'], array('incomplete', 'unavailable', 'no_data'), true) || in_array($previous['status'], array('incomplete', 'unavailable', 'no_data'), true) ? null : round($totals[$key] - $previous[$key], 4);
        }
        return array(
            'period' => $period, 'startDate' => $start->format('Y-m-d'), 'endDate' => $end->modify('-1 day')->format('Y-m-d'),
            'today' => $now->format('Y-m-d'), 'timeZone' => $zone->getName(), 'userId' => $user_id, 'scope' => $scope,
            'provisional' => $now_ms < self::ms($end), 'totals' => $totals, 'days' => $days,
            'pageCategories' => $totals['pageCategories'], 'pageCategoryCoverageUnknown' => $totals['pageCategoryCoverageUnknown'],
            'pageAttribution' => 'active_then_latest_focus_then_session',
            'previous' => array('startDate' => $previous_start->format('Y-m-d'), 'endDate' => $start->modify('-1 day')->format('Y-m-d'), 'totals' => $previous),
            'delta' => $delta, 'affectsFocusScore' => false,
            'historyKnownFrom' => self::at(self::coverage_since($now_ms), $zone)->format('Y-m-d'),
        );
    }

    private static function series(int $user_id, array $scope, DateTimeImmutable $start, DateTimeImmutable $end, int $through, int $now): array {
        $result = array();
        $days = array();
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $days[] = array('day' => $day, 'limit' => min(self::ms($day->modify('+1 day')), $through));
        }
        foreach (array_chunk($days, self::METADATA_CHUNK_DAYS) as $chunk) {
            $prepared = self::prepare_range($user_id, $scope, $chunk, $now);
            foreach ($chunk as $input) {
                $day = $input['day']; $limit = $input['limit'];
                $metrics = $limit <= self::ms($day) ? self::empty('no_data', true)
                    : self::calculate_day($user_id, $scope, $day, $limit, $now, false, false,
                        $prepared === null ? null : self::day_inputs($prepared, $scope, $day, $limit));
                unset($metrics['_hasSources'], $metrics['_checksArchived']);
                $result[] = array_merge(array('date' => $day->format('Y-m-d'), 'provisional' => $limit > self::ms($day) && $limit < self::ms($day->modify('+1 day')), 'isFuture' => $limit <= self::ms($day)), $metrics);
            }
        }
        return $result;
    }

    /** Request-local metadata, never reused across a privacy transaction or report. */
    private static function prepare_range(int $user_id, array $scope, array $days, int $now): ?array {
        $retention = Settings::aggregate_cutoff($now);
        $readable = array_values(array_filter($days, static fn (array $input): bool => $input['limit'] > self::ms($input['day'])
            && ($retention === null || self::ms($input['day']->modify('+1 day')) > self::ms($retention))));
        if ($readable === array()) { return null; }
        $cutoff = Privacy_Guard::cutoff($user_id);
        $readable = array_values(array_filter($readable, static fn (array $input): bool => $input['limit'] > $cutoff));
        if ($readable === array()) { return array('privacyCutoff' => $cutoff); }
        $first = $readable[0]['day']; $last = $readable[count($readable) - 1];
        return Repository::range_inputs($user_id, $scope, self::ms($first), $last['limit'], $first->format('Y-m-d'), $last['day']->format('Y-m-d'), $first->getTimezone()->getName())
            + array('privacyCutoff' => $cutoff);
    }

    private static function day_inputs(array $range, array $scope, DateTimeImmutable $day, int $end): array {
        if (! isset($range['sessions'])) { return $range; }
        $start = self::ms($day);
        $sessions = array_values(array_filter($range['sessions'], static fn (array $row): bool => $row['started_at_ms'] < $end
            && ($row['ended_at_ms'] ?? $row['last_heartbeat_at_ms'] + Score_Calculator::HEARTBEAT_GRACE_MS) > $start));
        if ($scope['type'] === 'self') {
            $ids = array();
            foreach ($sessions as $session) {
                if (str_starts_with((string) $session['session_uid'], 'g6-')) { foreach ($session['_activityIds'] as $id) { $ids[(int) $id] = true; } }
            }
            $activities = array_values(array_filter($range['activities'], static fn (array $row): bool => isset($ids[(int) $row['id']])));
        } else {
            // The repository's historical query deliberately includes exact boundaries.
            $activities = array_values(array_filter($range['activities'], static fn (array $row): bool => Clock::mysql_to_ms($row['starts_at']) <= $end && Clock::mysql_to_ms($row['ends_at']) >= $start));
        }
        return array('privacyCutoff' => $range['privacyCutoff'], 'sessions' => $sessions, 'activities' => $activities,
            'cached' => $range['cached'][$day->format('Y-m-d')] ?? null,
            'checks' => array_values(array_filter($range['checks'], static fn (array $row): bool => (int) $row['issued_at_ms'] >= $start && (int) $row['issued_at_ms'] < $end)),
            'archivedCheckOverlap' => $range['archivedCheckCandidates'] === null ? null : Repository::archived_check_overlap($range['archivedCheckCandidates'], $start, $end));
    }

    /** Full-day snapshots are frozen only by the retention guard below. */
    public static function day(int $user_id, array $scope, DateTimeImmutable $day, int $through, int $now, bool $archive, bool $checks_archived = false): array {
        return self::calculate_day($user_id, $scope, $day, $through, $now, $archive, $checks_archived, null);
    }

    private static function calculate_day(int $user_id, array $scope, DateTimeImmutable $day, int $through, int $now, bool $archive, bool $checks_archived, ?array $prepared): array {
        $retention_boundary = Settings::aggregate_cutoff($now);
        if (! $archive && $retention_boundary !== null && self::ms($day->modify('+1 day')) <= self::ms($retention_boundary)) {
            return self::empty('unavailable', false);
        }
        $start = self::ms($day);
        $end = min(self::ms($day->modify('+1 day')), $through);
        $privacy_cutoff = $prepared === null ? Privacy_Guard::cutoff($user_id) : $prepared['privacyCutoff'];
        if ($privacy_cutoff > 0 && $end <= $privacy_cutoff) { return self::empty('unavailable', false); }
        $privacy_gap = $privacy_cutoff > 0 && $start <= $privacy_cutoff;
        $full_day = $end === self::ms($day->modify('+1 day'));
        $zone = $day->getTimezone()->getName();
        $activities = $prepared === null ? Repository::activities($scope, $start, $end) : $prepared['activities'];
        $sessions = $prepared === null ? Repository::sessions($user_id, $start, $end, $archive) : $prepared['sessions'];
        if ($prepared === null && $scope['type'] === 'self') { $activities = Repository::capture_activities($sessions); }
        $sessions = array_values(array_filter($sessions, static fn (array $p): bool => self::eligible_session($p, $activities, $scope, $start, $end)));
        $signature = self::signature($sessions, $activities, $scope);
        if ($archive) { Repository::finalize_expired_checks($user_id, $start, $end, $now); }
        $cached = $prepared === null ? Repository::get($user_id, $scope, $day->format('Y-m-d'), $zone) : $prepared['cached'];
        $frozen_check_counts = null;
        $unknown_check_counts = false;
        $expired = ! empty(array_filter($sessions, static fn (array $p): bool => $p['raw_events_expired']));
        if (! $full_day && $cached !== null && $cached['archived']) {
            if ($expired) { return self::empty('unavailable', false); }
            // A daily total has no within-day distribution. It cannot answer an
            // equal-elapsed partial comparison after its raw log is removed.
            $unknown_check_counts = ! empty($cached['metrics']['_checksArchived']);
            $cached = null;
        }
        if ($cached !== null && $cached['archived']) {
            $metrics = self::with_page_categories($cached['metrics']);
            if ($privacy_gap) {
                $metrics['status'] = 'incomplete';
                $metrics['hasObservationGaps'] = true;
                $metrics['focusRatio'] = null;
            }
            if ($checks_archived && empty($metrics['_checksArchived'])) {
                $metrics['_checksArchived'] = true;
                if (! Repository::save($user_id, $scope, $day->format('Y-m-d'), $zone, $metrics, $cached['source_signature'], true)) { throw new RuntimeException('Check archive provenance could not be saved.'); }
            }
            if (! hash_equals($cached['source_signature'], $signature)) {
                // Missing source cannot be reconstructed after late delivery,
                // a changed activity window/problem set, or timezone changes.
                if ($expired || empty($sessions)) {
                    $metrics['status'] = 'incomplete';
                    $metrics['coverageUnknown'] = true;
                    $metrics['focusRatio'] = null;
                    $metrics['editCount'] = $metrics['runCount'] = $metrics['submitCount'] = null;
                    $metrics['pageCategories'] = Score_Calculator::empty_page_categories(true);
                    $metrics['pageCategoryCoverageUnknown'] = true;
                    if (! empty($metrics['_checksArchived']) && $scope['type'] !== 'self') { $metrics['checks'] = array_fill_keys(self::CHECK_STATUSES, null); }
                    return $metrics;
                }
                if (! empty($metrics['_checksArchived'])) {
                    // A completed day's self check totals are independent of
                    // later activity edits. A changed teacher window cannot
                    // reconstruct the removed questions' original timestamps.
                    if ($scope['type'] === 'self') { $frozen_check_counts = $metrics['checks']; }
                    else { $unknown_check_counts = true; }
                }
            } else {
                if ($metrics['status'] === 'complete' || $metrics['status'] === 'no_data') { $metrics['status'] = 'archived'; }
                return $metrics;
            }
        }
        if ($expired) { return self::empty('unavailable', false); }
        if ($frozen_check_counts === null && (($prepared['archivedCheckOverlap'] ?? null) ?? Repository::has_archived_check_overlap($user_id, $start, $end))) { $unknown_check_counts = true; }
        $events = Event_Repository::list_for_participations(array_column($sessions, 'id'));
        Repository::assert_read();
        $checks = array_values(array_filter($prepared === null ? Repository::checks($user_id, $start, $end, $archive) : $prepared['checks'], static fn (array $check): bool => self::eligible_check($check, $activities, $scope)));
        if (empty($sessions) && empty($checks)) {
            $old = $start < self::coverage_since($now);
            return self::empty($old || $privacy_gap ? 'unavailable' : 'no_data', false);
        }
        $active = array();
        $observed = array();
        $has_observation_gaps = $privacy_gap;
        $has_delivery_gaps = false;
        $missing = false;
        $counts = array('activity' => 0, 'run' => 0, 'submit' => 0);
        $page_categories = Score_Calculator::empty_page_categories();
        $page_active_ms = $page_observed_ms = array_fill(0, count($page_categories), 0);
        $page_sessions = array();
        foreach ($sessions as &$session) {
            $session['events'] = $events[$session['id']] ?? array();
            $missing = $missing || empty($session['events']);
            $has_delivery_gaps = $has_delivery_gaps || $session['has_delivery_gaps'];
            foreach ($session['events'] as $event) {
                if (isset($counts[$event['type']]) && $event['occurred_at'] >= $start && $event['occurred_at'] < $end && self::session_allowed_at($session, $event['occurred_at'], $activities, $scope)) {
                    ++$counts[$event['type']];
                }
            }
        }
        unset($session);
        // Partition at activity boundaries so each time slice uses only tabs
        // belonging to the context's actual problem/window union.
        $cuts = array($start, $end);
        foreach ($activities as $activity) {
            $cuts[] = max($start, min($end, Clock::mysql_to_ms($activity['starts_at'])));
            $cuts[] = max($start, min($end, Clock::mysql_to_ms($activity['ends_at'])));
        }
        $cuts = array_values(array_unique($cuts));
        sort($cuts, SORT_NUMERIC);
        for ($i = 0; $i + 1 < count($cuts); ++$i) {
            $from = $cuts[$i];
            $to = $cuts[$i + 1];
            if ($to <= $from) { continue; }
            $inputs = array_values(array_filter($sessions, static fn (array $p): bool => ! empty($p['events']) && self::session_allowed_at($p, $from, $activities, $scope)));
            if (empty($inputs)) { continue; }
            $timeline = Score_Calculator::timeline_for_participations($inputs, $from, $to, $now);
            $witnessed = Score_Calculator::timeline_for_participations(Score_Calculator::observed_inputs($inputs), $from, $to, $now);
            $pages = Score_Calculator::page_timeline_for_participations($inputs, $from, $to, $now);
            foreach ($pages['pageCategories'] as $index => $category) {
                $page_active_ms[$index] += (int) round(($category['activeSeconds'] ?? 0) * 1000);
                $page_observed_ms[$index] += (int) round(($category['observedSeconds'] ?? 0) * 1000);
                foreach (array_keys($pages['_categorySessionKeys'][$index] ?? array()) as $identity) { $page_sessions[$index][$identity] = true; }
            }
            $active = array_merge($active, $timeline['intervals']);
            $observed = array_merge($observed, $witnessed['intervals']);
            $has_observation_gaps = $has_observation_gaps || $timeline['has_observation_gaps'] || $witnessed['has_observation_gaps'];
        }
        $active_ms = self::duration($active);
        $observed_ms = self::duration($observed);
        $check_counts = array_fill_keys(self::CHECK_STATUSES, 0);
        foreach ($checks as $check) {
            $status = $check['status'];
            if ($status === 'pending' && (int) $check['expires_at_ms'] <= $now) {
                $status = $check['window_end_ms'] !== null && (int) $check['expires_at_ms'] >= (int) $check['window_end_ms'] ? 'invalidated' : 'unanswered';
            }
            if (isset($check_counts[$status])) { ++$check_counts[$status]; }
        }
        if ($frozen_check_counts !== null) { $check_counts = $frozen_check_counts; }
        if ($unknown_check_counts) { $check_counts = array_fill_keys(self::CHECK_STATUSES, null); }
        $incomplete = $missing || $has_delivery_gaps || $has_observation_gaps || $unknown_check_counts;
        $page_unknown = false;
        foreach ($page_categories as $index => &$category) {
            if (empty($page_sessions[$index])) { continue; }
            $category['activeSeconds'] = $page_active_ms[$index] / 1000;
            $category['observedSeconds'] = $page_observed_ms[$index] / 1000;
            $category['sessionCount'] = $category['pageCount'] = $category['pageViews'] = count($page_sessions[$index]);
            $category['coverageUnknown'] = $category['category'] === 'unclassified' || $missing;
            $category['status'] = $missing || $has_delivery_gaps || $has_observation_gaps ? 'incomplete' : 'complete';
            $page_unknown = $page_unknown || $category['coverageUnknown'];
        }
        unset($category);
        $metrics = array(
            'activeSeconds' => round($active_ms / 1000, 3), 'observedSeconds' => round($observed_ms / 1000, 3),
            'focusRatio' => ! $incomplete && $observed_ms > 0 ? round(min(1, $active_ms / $observed_ms), 4) : null,
            'editCount' => $counts['activity'], 'runCount' => $counts['run'], 'submitCount' => $counts['submit'], 'checks' => $check_counts,
            'status' => $incomplete ? 'incomplete' : 'complete', 'hasObservationGaps' => $has_observation_gaps,
            'hasDeliveryGaps' => $has_delivery_gaps, 'coverageUnknown' => $missing || $unknown_check_counts, '_hasSources' => true,
            '_checksArchived' => $checks_archived || ! empty($cached['metrics']['_checksArchived']),
            'pageCategories' => $page_categories, 'pageCategoryCoverageUnknown' => $page_unknown,
        );
        $unchanged = $cached !== null && $cached['archived'] === $archive && hash_equals($cached['source_signature'], $signature) && $cached['metrics'] === $metrics;
        if ($full_day && ! $unchanged && ! Repository::save($user_id, $scope, $day->format('Y-m-d'), $zone, $metrics, $signature, $archive)) { throw new RuntimeException('Daily statistics could not be saved.'); }
        return $metrics;
    }

    private static function eligible_session(array $p, array $activities, array $scope, int $start, int $end): bool {
        if ($scope['type'] === 'self' && ! str_starts_with((string) $p['session_uid'], 'g6-')) { return true; }
        foreach ($activities as $activity) {
            if (! in_array((int) $activity['id'], $p['_activityIds'], true)) { continue; }
            $from = max($start, (int) $p['started_at_ms'], Clock::mysql_to_ms($activity['starts_at']));
            $to = min($end, (int) ($p['ended_at_ms'] ?? $p['last_heartbeat_at_ms'] + Score_Calculator::HEARTBEAT_GRACE_MS), Clock::mysql_to_ms($activity['ends_at']));
            if ($to > $from && self::problem_allowed($p, $activity)) { return true; }
        }
        return false;
    }

    private static function allowed_at(?int $problem, int $at, array $activities, array $scope, bool $nonproblem = false): bool {
        if ($scope['type'] === 'self') { return true; }
        foreach ($activities as $activity) {
            if (Clock::mysql_to_ms($activity['starts_at']) <= $at && Clock::mysql_to_ms($activity['ends_at']) > $at && ($nonproblem || null === $activity['problem_ids'] || in_array($problem, $activity['problem_ids'], true))) { return true; }
        }
        return false;
    }

    private static function session_allowed_at(array $session, int $at, array $activities, array $scope): bool {
        if ($scope['type'] === 'self' && str_starts_with((string) $session['session_uid'], 'g6-')) { $scope = array('type' => 'capture', 'id' => ''); }
        return self::allowed_at($session['problem_id'], $at, self::session_activities($session, $activities), $scope, self::nonproblem($session));
    }

    private static function nonproblem(array $session): bool {
        $page = Score_Calculator::page_metadata($session);
        return (int) ($session['problem_id'] ?? 0) < 1 && $page['category'] !== 'unclassified' && ! Score_Calculator::page_supports_activity($page);
    }

    private static function problem_allowed(array $session, array $activity): bool {
        return self::nonproblem($session) || null === $activity['problem_ids'] || in_array($session['problem_id'], $activity['problem_ids'], true);
    }

    private static function session_activities(array $session, array $activities): array {
        return array_values(array_filter($activities, static fn (array $activity): bool => in_array((int) $activity['id'], $session['_activityIds'], true)));
    }

    private static function eligible_check(array $check, array $activities, array $scope): bool {
        if ($scope['type'] === 'self') { return true; }
        if ($check['context_type'] !== $scope['type']) { return false; }
        $scope_id = $scope['id'];
        if (preg_match('/^' . preg_quote($scope['type'], '/') . ':([1-9][0-9]*)$/D', $scope_id, $match)) { $scope_id = $match[1]; }
        if ((string) $check['context_id'] !== $scope['id'] && (string) $check['context_id'] !== $scope_id) { return false; }
        return self::allowed_at((int) $check['problem_id'], (int) $check['issued_at_ms'], $activities, $scope);
    }

    private static function signature(array $sessions, array $activities, array $scope): string {
        $parts = array_map(static function (array $p) use ($scope, $activities): array {
            $part = array($p['id'], $p['problem_id'], $p['started_at_ms'], $p['ended_at_ms'], $p['last_heartbeat_at_ms'], $p['event_revision'], $p['has_delivery_gaps'], $scope['type'] === 'self' && ! str_starts_with((string) $p['session_uid'], 'g6-') ? array() : array_values(array_intersect($p['_activityIds'], array_column($activities, 'id'))));
            if ((int) ($p['page_context_version'] ?? 0) > 0) { $part[] = array(Score_Calculator::PAGE_POLICY_VERSION, $p['page_category'], $p['page_subtype'], $p['page_key'], (int) $p['page_context_version']); }
            return $part;
        }, $sessions);
        $windows = array_map(static fn (array $a): array => array($a['id'], $a['starts_at'], $a['ends_at'], $a['problem_ids']), $activities);
        return hash('sha256', (string) json_encode(array(self::VERSION, Score_Calculator::VERSION, $parts, $windows)));
    }

    private static function duration(array $intervals): int {
        usort($intervals, static fn (array $a, array $b): int => $a['startedAt'] <=> $b['startedAt']);
        $total = 0;
        $end = PHP_INT_MIN;
        foreach ($intervals as $interval) {
            $from = max($end, $interval['startedAt']);
            if ($interval['endedAt'] > $from) { $total += $interval['endedAt'] - $from; }
            $end = max($end, $interval['endedAt']);
        }
        return $total;
    }

    private static function empty(string $status, bool $future): array {
        $unknown = $status === 'unavailable' || $status === 'no_data' || $future;
        return array('activeSeconds' => $unknown ? null : 0, 'observedSeconds' => $unknown ? null : 0, 'focusRatio' => null,
            'editCount' => $unknown ? null : 0, 'runCount' => $unknown ? null : 0, 'submitCount' => $unknown ? null : 0,
            'checks' => array_fill_keys(self::CHECK_STATUSES, $unknown ? null : 0), 'status' => $status,
            'hasObservationGaps' => false, 'hasDeliveryGaps' => false, 'coverageUnknown' => $status === 'unavailable', '_hasSources' => false,
            'pageCategories' => Score_Calculator::empty_page_categories($status === 'unavailable'), 'pageCategoryCoverageUnknown' => $status === 'unavailable');
    }

    /** Old frozen totals remain valid; removed raw data cannot invent categories. */
    private static function with_page_categories(array $metrics): array {
        if (! isset($metrics['pageCategories'])) {
            $metrics['pageCategories'] = Score_Calculator::empty_page_categories(($metrics['status'] ?? '') !== 'no_data');
            $metrics['pageCategoryCoverageUnknown'] = ($metrics['status'] ?? '') !== 'no_data';
        }
        return $metrics;
    }

    private static function sum(array $days): array {
        $total = self::empty('no_data', false);
        unset($total['_hasSources']);
        foreach (array('activeSeconds', 'observedSeconds', 'editCount', 'runCount', 'submitCount') as $key) { $total[$key] = 0; }
        $total['checks'] = array_fill_keys(self::CHECK_STATUSES, 0);
        $has_data = false;
        $archived = false;
        $unavailable = false;
        foreach ($days as $day) {
            if (! empty($day['isFuture'])) { continue; }
            if ($day['status'] === 'no_data') { continue; }
            $day = self::with_page_categories($day);
            $total['pageCategoryCoverageUnknown'] = $total['pageCategoryCoverageUnknown'] || $day['pageCategoryCoverageUnknown'];
            foreach ($day['pageCategories'] as $index => $category) {
                if ($category['status'] === 'no_data') { continue; }
                $target = &$total['pageCategories'][$index];
                $was_empty = $target['status'] === 'no_data';
                foreach (array('activeSeconds', 'observedSeconds', 'sessionCount', 'pageCount', 'pageViews') as $key) {
                    $target[$key] = $category[$key] === null || (! $was_empty && $target[$key] === null) ? null : ($was_empty ? 0 : $target[$key]) + $category[$key];
                    if (in_array($key, array('activeSeconds', 'observedSeconds'), true) && $target[$key] !== null) { $target[$key] = round($target[$key], 3); }
                }
                $target['coverageUnknown'] = $target['coverageUnknown'] || $category['coverageUnknown'];
                $target['status'] = in_array($target['status'], array('incomplete', 'unavailable'), true) || in_array($category['status'], array('incomplete', 'unavailable'), true)
                    ? 'incomplete' : ($category['status'] === 'archived' || $target['status'] === 'archived' ? 'archived' : 'complete');
                unset($target);
            }
            $has_data = $has_data || in_array($day['status'], array('complete', 'archived', 'incomplete'), true);
            $archived = $archived || $day['status'] === 'archived';
            $unavailable = $unavailable || $day['status'] === 'unavailable';
            foreach (array('activeSeconds', 'observedSeconds', 'editCount', 'runCount', 'submitCount') as $key) {
                $total[$key] = $total[$key] === null || $day[$key] === null ? null : $total[$key] + $day[$key];
            }
            foreach (self::CHECK_STATUSES as $status) {
                $total['checks'][$status] = $total['checks'][$status] === null || $day['checks'][$status] === null ? null : $total['checks'][$status] + $day['checks'][$status];
            }
            foreach (array('hasObservationGaps', 'hasDeliveryGaps', 'coverageUnknown') as $key) { $total[$key] = $total[$key] || $day[$key]; }
        }
        $bad = $total['hasObservationGaps'] || $total['hasDeliveryGaps'] || $total['coverageUnknown'];
        if (! $has_data && ! $unavailable) { $empty = self::empty('no_data', false); unset($empty['_hasSources']); return $empty; }
        $total['status'] = $unavailable ? ($has_data ? 'incomplete' : 'unavailable') : ($bad ? 'incomplete' : ($has_data ? ($archived ? 'archived' : 'complete') : 'no_data'));
        $total['focusRatio'] = ! $bad && $total['observedSeconds'] > 0 ? round(min(1, $total['activeSeconds'] / $total['observedSeconds']), 4) : null;
        return $total;
    }

    public static function archive_participation(array $participation, ?int $now = null): bool {
        if (Privacy_Guard::state((int) $participation['user_id']) !== 'open') { return false; }
        return self::archive_span((int) $participation['user_id'], (int) $participation['started_at_ms'], self::retention_end($participation), $now ?? Clock::now_ms());
    }

    /** A removed future schedule cannot keep an old physical source open. */
    public static function retention_end(array $participation): int {
        $end = (int) ($participation['ended_at_ms'] ?? $participation['last_heartbeat_at_ms']);
        if (! str_starts_with((string) $participation['session_uid'], 'g6-')) { return $end; }
        $participation['_activityIds'] = Participation_Repository::activity_ids((int) $participation['id']);
        Repository::assert_read();
        $windows = Repository::capture_activities(array($participation));
        $latest = null;
        foreach ($windows as $activity) {
            $from = max((int) $participation['started_at_ms'], Clock::mysql_to_ms($activity['starts_at']));
            $to = Clock::mysql_to_ms($activity['ends_at']);
            if ($to <= $from || ! self::problem_allowed($participation, $activity)) { continue; }
            $latest = max($latest ?? $to, $to);
        }
        return $latest === null ? $end : min($end, $latest);
    }

    /** Called in the purge transaction while its source participation is locked. */
    private static function archive_span(int $user_id, int $from, int $to, int $now, bool $checks_archived = false): bool {
        $zone = wp_timezone();
        $day = self::at($from, $zone)->setTime(0, 0);
        $end = self::at(max($from, $to - 1), $zone)->setTime(0, 0);
        $count = 0;
        for (; $day <= $end; $day = $day->modify('+1 day')) {
            if (++$count > 366 || self::ms($day->modify('+1 day')) > $now) { return false; }
            $scopes = array_merge(array(array('type' => 'self', 'id' => '')), Repository::archive_contexts($user_id, self::ms($day), self::ms($day->modify('+1 day'))));
            if (count($scopes) > 64) { return false; }
            foreach ($scopes as $scope) {
                $metrics = self::day($user_id, $scope, $day, self::ms($day->modify('+1 day')), $now, true, $checks_archived);
                if ($scope['type'] !== 'self' && empty($metrics['_hasSources'])) { continue; }
                if ($metrics['status'] === 'unavailable' || $metrics['coverageUnknown']) { return false; }
            }
        }
        return true;
    }

    public static function archive_checks(int $cutoff): bool {
        try {
            $rows = Repository::old_checks($cutoff);
            $success = true;
            foreach ($rows as $row) {
                $success = self::archive_check($row, $cutoff) && $success;
            }
            return $success;
        } catch (\Throwable $error) { return false; }
    }

    /** One retryable unit; a failed question never blocks a later one. */
    public static function archive_check(array $row, int $cutoff): bool {
        global $wpdb;
        if (Privacy_Guard::state((int) $row['user_id']) !== 'open') { return false; }
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            $current = $wpdb->get_row($wpdb->prepare('SELECT id, user_id, issued_at_ms FROM ' . \FocusTracker\Database\Installer::checks_table() . ' WHERE id = %d AND issued_at_ms < %d FOR UPDATE', (int) $row['id'], $cutoff), ARRAY_A);
            Repository::assert_read();
            if ($current === null) { return false !== $wpdb->query('COMMIT'); }
            $saved = self::archive_span((int) $current['user_id'], (int) $current['issued_at_ms'], (int) $current['issued_at_ms'] + 1, Clock::now_ms(), true);
            if ($saved) {
                $saved = false !== $wpdb->query($wpdb->prepare('DELETE FROM ' . \FocusTracker\Database\Installer::checks_table() . ' WHERE id = %d AND issued_at_ms < %d', (int) $current['id'], $cutoff));
            }
            if (! $saved) { $wpdb->query('ROLLBACK'); return false; }
            if (false === $wpdb->query('COMMIT')) { $wpdb->query('ROLLBACK'); return false; }
            return true;
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); return false; }
    }

    private static function at(int $ms, DateTimeZone $zone): DateTimeImmutable { return (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone($zone); }
    private static function ms(DateTimeImmutable $time): int { return (int) $time->format('U') * 1000 + intdiv((int) $time->format('u'), 1000); }
    private static function coverage_since(int $now): int {
        $marker = get_option('focus_tracker_statistics_coverage_since', false);
        return is_numeric($marker) && (int) $marker > 0 ? (int) $marker : $now - max(1, (int) Settings::get('retention_days')) * DAY_IN_SECONDS * 1000;
    }
}
