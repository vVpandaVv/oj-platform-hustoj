<?php
declare(strict_types=1);

namespace FocusTracker\Jobs;

use FocusTracker\Database\Activity_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Participation_Repository;
use FocusTracker\Database\Score_Repository;
use FocusTracker\Database\Statistics_Repository;
use FocusTracker\Reporting\Statistics_Service;
use FocusTracker\Scoring\Activity_Score_Service;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Settings;
use Throwable;

/** Bounded, resumable retention. Failed source units are preserved for retry. */
final class Retention_Cleanup {
    public const STATUS_OPTION = 'focus_tracker_cleanup_status';
    private const HOOK = 'focus_tracker_cleanup';
    private const CONTINUE_HOOK = 'focus_tracker_cleanup_continue';
    private const LOCK_OPTION = 'focus_tracker_cleanup_lock';
    private const PROGRESS_OPTION = 'focus_tracker_cleanup_progress';
    private const BATCH_LIMIT = 100;
    private const TIME_BUDGET_SECONDS = 8;
    private const TIMEOUT_GRACE_SECONDS = 2 * MINUTE_IN_SECONDS;

    public static function register(): void {
        add_action('init', array(__CLASS__, 'maybe_schedule'));
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action(self::CONTINUE_HOOK, array(__CLASS__, 'run'));
    }

    public static function maybe_schedule(): void {
        if (! wp_next_scheduled(self::HOOK)) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK); }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::CONTINUE_HOOK);
    }

    public static function run(): void {
        $lock = self::acquire_lock();
        if ($lock === null) { self::schedule_followup(125); return; }
        $now = Clock::now_ms();
        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        $previous = get_option(self::STATUS_OPTION, array());
        $previous = is_array($previous) ? $previous : array();
        $progress = get_option(self::PROGRESS_OPTION, array());
        $progress = array_merge(array('raw' => 0, 'checks' => 0, 'daily' => 0, 'scores' => 0, 'metadata' => 0), is_array($progress) ? $progress : array());
        $status = array('schemaVersion' => 1, 'lastRunAtMs' => $now, 'lastSuccessAtMs' => $previous['lastSuccessAtMs'] ?? null,
            'lastOutcome' => 'running', 'counts' => array('closed' => 0, 'rawAttempted' => 0, 'rawPurged' => 0, 'checkAttempted' => 0, 'checkPurged' => 0, 'aggregatePurged' => 0, 'metadataPurged' => 0, 'failed' => 0),
            'backlog' => array('raw' => null, 'checks' => null, 'aggregates' => null, 'metadata' => null, 'abandoned' => null),
            'oldest' => array('rawAtMs' => null, 'checkAtMs' => null, 'aggregateDate' => null, 'metadataAtMs' => null),
            'retry' => array('scheduledAtMs' => null, 'consecutiveFailures' => (int) ($previous['retry']['consecutiveFailures'] ?? 0)));
        update_option(self::STATUS_OPTION, $status, false);
        try {
            // Leave a wake-up behind even if PHP exits before finally runs.
            // It fires after the abandoned worker's lock lease has expired.
            wp_clear_scheduled_hook(self::CONTINUE_HOOK);
            self::schedule_followup(180);
            $raw_days = max(1, (int) apply_filters('focus_tracker_event_retention_days', Settings::get('retention_days')));
            $raw_cutoff = Clock::ms_to_mysql($now - $raw_days * DAY_IN_SECONDS * 1000);
            $check_cutoff = $now - max(1, (int) Settings::get('check_retention_days')) * DAY_IN_SECONDS * 1000;
            $status['counts']['closed'] = self::close_abandoned();
            foreach (self::raw_candidates($raw_cutoff, (int) $progress['raw']) as $row) {
                if (microtime(true) >= $deadline) { break; }
                $progress['raw'] = (int) $row['id'];
                update_option(self::PROGRESS_OPTION, $progress, false);
                ++$status['counts']['rawAttempted'];
                $deleted = self::purge_participation((int) $row['id'], $raw_cutoff);
                if ($deleted === false) { ++$status['counts']['failed']; }
                else { $status['counts']['rawPurged'] += $deleted; }
            }
            foreach (Statistics_Repository::old_checks($check_cutoff, (int) $progress['checks']) as $row) {
                if (microtime(true) >= $deadline) { break; }
                $progress['checks'] = (int) $row['id'];
                update_option(self::PROGRESS_OPTION, $progress, false);
                ++$status['counts']['checkAttempted'];
                if (Statistics_Service::archive_check($row, $check_cutoff)) { ++$status['counts']['checkPurged']; }
                else { ++$status['counts']['failed']; }
            }
            $boundary = Settings::aggregate_cutoff($now);
            if ($boundary !== null) {
                foreach (array('daily', 'scores') as $kind) {
                    foreach (self::aggregate_candidates($kind, $boundary, (int) $progress[$kind]) as $row) {
                        if (microtime(true) >= $deadline) { break; }
                        $progress[$kind] = (int) $row['id'];
                        update_option(self::PROGRESS_OPTION, $progress, false);
                        $deleted = self::purge_aggregate($kind, $row, $boundary);
                        if ($deleted === false) { ++$status['counts']['failed']; }
                        else { $status['counts']['aggregatePurged'] += $deleted; }
                    }
                }
                foreach (self::metadata_candidates($boundary, (int) $progress['metadata']) as $row) {
                    if (microtime(true) >= $deadline) { break; }
                    $progress['metadata'] = (int) $row['id'];
                    update_option(self::PROGRESS_OPTION, $progress, false);
                    $deleted = self::purge_metadata((int) $row['id'], $boundary);
                    if ($deleted === false) { ++$status['counts']['failed']; }
                    else { $status['counts']['metadataPurged'] += $deleted; }
                }
            }
            $diagnostics = self::diagnostics($raw_cutoff, $check_cutoff, $boundary);
            $status['backlog'] = $diagnostics['backlog'];
            $status['oldest'] = $diagnostics['oldest'];
            $stage_ahead = array(
                'raw' => ! empty(self::raw_candidates($raw_cutoff, (int) $progress['raw'], 1)),
                'checks' => ! empty(Statistics_Repository::old_checks($check_cutoff, (int) $progress['checks'], 1)),
                'daily' => $boundary !== null && ! empty(self::aggregate_candidates('daily', $boundary, (int) $progress['daily'], 1)),
                'scores' => $boundary !== null && ! empty(self::aggregate_candidates('scores', $boundary, (int) $progress['scores'], 1)),
                'metadata' => $boundary !== null && ! empty(self::metadata_candidates($boundary, (int) $progress['metadata'], 1)),
            );
            $ahead = in_array(true, $stage_ahead, true);
            $remaining = array_sum($status['backlog']) > 0;
            foreach ($stage_ahead as $kind => $has_next) { if (! $has_next) { $progress[$kind] = 0; } }
            $failed = $status['counts']['failed'] > 0;
            $status['lastOutcome'] = $failed ? 'deferred' : ($remaining ? 'continuing' : 'complete');
            if (! $failed) { $status['lastSuccessAtMs'] = Clock::now_ms(); }
            $status['retry']['consecutiveFailures'] = $failed ? $status['retry']['consecutiveFailures'] + 1 : 0;
            wp_clear_scheduled_hook(self::CONTINUE_HOOK);
            if ($remaining) {
                $status['retry']['scheduledAtMs'] = self::schedule_followup($ahead ? 30 : 300);
                if ($status['retry']['scheduledAtMs'] === null) {
                    ++$status['counts']['failed'];
                    $status['lastOutcome'] = 'error';
                    $status['retry']['consecutiveFailures'] = (int) ($previous['retry']['consecutiveFailures'] ?? 0) + 1;
                }
            }
        } catch (Throwable $error) {
            ++$status['counts']['failed'];
            $status['lastOutcome'] = 'error';
            ++$status['retry']['consecutiveFailures'];
            wp_clear_scheduled_hook(self::CONTINUE_HOOK);
            $status['retry']['scheduledAtMs'] = self::schedule_followup(300);
        } finally {
            // Cursors contain internal row IDs and stay separate from the
            // operational status exposed to administrators.
            update_option(self::PROGRESS_OPTION, $progress, false);
            update_option(self::STATUS_OPTION, $status, false);
            self::release_lock($lock);
        }
    }

    /** Shared with privacy erasure so an archive cannot recreate removed data. */
    public static function acquire_lock(): ?string {
        $value = wp_json_encode(array('token' => bin2hex(random_bytes(16)), 'expiresAtMs' => Clock::now_ms() + 120000));
        if (add_option(self::LOCK_OPTION, $value, '', false)) { return $value; }
        $old = get_option(self::LOCK_OPTION, '');
        $data = is_string($old) ? json_decode($old, true) : null;
        if (is_array($data) && (int) ($data['expiresAtMs'] ?? 0) > Clock::now_ms()) { return null; }
        if (! is_string($old) || ! self::release_lock($old)) { return null; }
        return add_option(self::LOCK_OPTION, $value, '', false) ? $value : null;
    }

    public static function release_lock(string $value): bool {
        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->options . ' WHERE option_name = %s AND option_value = %s', self::LOCK_OPTION, $value));
        if ($deleted === 1) { wp_cache_delete(self::LOCK_OPTION, 'options'); return true; }
        return false;
    }

    private static function schedule_followup(int $seconds): ?int {
        $due = time() + $seconds;
        $existing = wp_next_scheduled(self::CONTINUE_HOOK);
        if ($existing !== false && $existing <= $due) { return (int) $existing * 1000; }
        if ($existing !== false) { wp_clear_scheduled_hook(self::CONTINUE_HOOK); }
        $scheduled = wp_schedule_single_event($due, self::CONTINUE_HOOK);
        return $scheduled === false || is_wp_error($scheduled) ? null : $due * 1000;
    }

    private static function close_abandoned(): int {
        global $wpdb;
        $table = Installer::participations_table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::TIMEOUT_GRACE_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$table} WHERE ended_at IS NULL AND last_heartbeat_at < %s ORDER BY id ASC LIMIT %d", $cutoff, self::BATCH_LIMIT), ARRAY_A);
        Statistics_Repository::assert_read();
        if (empty($rows)) { return 0; }
        $ids = array_map('intval', array_column($rows, 'id'));
        $updated = $wpdb->query($wpdb->prepare("UPDATE {$table} SET ended_at = last_heartbeat_at, ended_at_ms = last_heartbeat_at_ms, end_reason = 'timeout' WHERE id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ') AND ended_at IS NULL', $ids));
        if ($updated === false) { throw new \RuntimeException('Participation cleanup failed.'); }
        return $updated;
    }

    private static function raw_candidates(string $cutoff, int $after_id, int $limit = self::BATCH_LIMIT): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT p.id FROM ' . Installer::participations_table() . ' p JOIN ' . Installer::events_table() . ' e ON e.participation_id = p.id
            WHERE p.id > %d AND p.last_heartbeat_at < %s GROUP BY p.id HAVING MAX(e.occurred_at) < %s ORDER BY p.id ASC LIMIT %d', $after_id, $cutoff, $cutoff, $limit), ARRAY_A);
        Statistics_Repository::assert_read();
        return $rows;
    }

    private static function purge_participation(int $id, string $cutoff): int|false {
        global $wpdb;
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            $participation = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::participations_table() . ' WHERE id = %d FOR UPDATE', $id), ARRAY_A);
            Statistics_Repository::assert_read();
            if ($participation === null || $participation['last_heartbeat_at'] >= $cutoff) { $wpdb->query('ROLLBACK'); return false; }
            $effective_end = Statistics_Service::retention_end($participation);
            if ($participation['ended_at_ms'] !== null && $effective_end < (int) $participation['ended_at_ms']) {
                $bounds = array('ended_at_ms' => $effective_end, 'ended_at' => Clock::ms_to_mysql($effective_end));
                if (false === $wpdb->update(Installer::participations_table(), $bounds, array('id' => $id))) { $wpdb->query('ROLLBACK'); return false; }
                $participation = array_merge($participation, $bounds);
            }
            if (! Statistics_Service::archive_participation($participation)) { $wpdb->query('ROLLBACK'); return false; }
            $activity_ids = Participation_Repository::activity_ids($id);
            Statistics_Repository::assert_read();
            foreach ($activity_ids as $activity_id) {
                $activity = Activity_Repository::find($activity_id);
                Statistics_Repository::assert_read();
                if ($activity === null) { continue; }
                if ($activity['status'] === Activity_Repository::STATUS_DELETED && $activity['ends_at'] <= $activity['starts_at']) { continue; }
                $metrics = Activity_Score_Service::score_for_learner($activity, (int) $participation['user_id'], true, true);
                if (empty($metrics['finalized']) || empty($metrics['cache_saved']) || ! Score_Repository::mark_events_expired($activity_id, (int) $participation['user_id'])) { $wpdb->query('ROLLBACK'); return false; }
            }
            if (false === $wpdb->update(Installer::participations_table(), array('raw_events_expired' => 1), array('id' => $id))) { $wpdb->query('ROLLBACK'); return false; }
            $deleted = $wpdb->query($wpdb->prepare('DELETE FROM ' . Installer::events_table() . ' WHERE participation_id = %d AND occurred_at < %s', $id, $cutoff));
            if ($deleted === false || false === $wpdb->query('COMMIT')) { $wpdb->query('ROLLBACK'); return false; }
            return $deleted;
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return false; }
    }

    private static function aggregate_candidates(string $kind, \DateTimeImmutable $boundary, int $after_id, int $limit = self::BATCH_LIMIT): array {
        global $wpdb;
        $query = $kind === 'daily'
            ? $wpdb->prepare('SELECT * FROM ' . Installer::statistics_table() . ' WHERE id > %d AND local_day < %s ORDER BY id ASC LIMIT %d', $after_id, $boundary->format('Y-m-d'), $limit)
            : $wpdb->prepare('SELECT s.*, a.ends_at FROM ' . Installer::scores_table() . ' s JOIN ' . Installer::activities_table() . ' a ON a.id = s.activity_id WHERE s.id > %d AND a.ends_at < %s ORDER BY s.id ASC LIMIT %d', $after_id, Clock::ms_to_mysql($boundary->getTimestamp() * 1000), $limit);
        $rows = $wpdb->get_results($query, ARRAY_A);
        Statistics_Repository::assert_read();
        return $rows;
    }

    private static function purge_aggregate(string $kind, array $row, \DateTimeImmutable $boundary): int|false {
        global $wpdb;
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            $table = $kind === 'daily' ? Installer::statistics_table() : Installer::scores_table();
            $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d FOR UPDATE", (int) $row['id']), ARRAY_A);
            Statistics_Repository::assert_read();
            if ($current === null) { $wpdb->query('COMMIT'); return 0; }
            if ($kind === 'daily') {
                $day = new \DateTimeImmutable($current['local_day'] . ' 00:00:00', new \DateTimeZone($current['time_zone']));
                $from = $day->getTimestamp() * 1000;
                $to = $day->modify('+1 day')->getTimestamp() * 1000;
                $raw = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::events_table() . ' e JOIN ' . Installer::participations_table() . ' p ON p.id = e.participation_id WHERE p.user_id = %d AND e.occurred_at_ms >= %d AND e.occurred_at_ms < %d', (int) $current['user_id'], $from, $to));
                Statistics_Repository::assert_read();
                $checks = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::checks_table() . ' WHERE user_id = %d AND issued_at_ms >= %d AND issued_at_ms < %d', (int) $current['user_id'], $from, $to));
                Statistics_Repository::assert_read();
                if ((int) $raw > 0 || (int) $checks > 0) { $wpdb->query('ROLLBACK'); return false; }
            } else {
                $raw = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::events_table() . ' e JOIN ' . Installer::participations_table() . ' p ON p.id = e.participation_id WHERE p.user_id = %d AND (p.activity_id = %d OR EXISTS (SELECT 1 FROM ' . Installer::activity_links_table() . ' l WHERE l.participation_id = p.id AND l.activity_id = %d))', (int) $current['user_id'], (int) $current['activity_id'], (int) $current['activity_id']));
                Statistics_Repository::assert_read();
                if ((int) $raw > 0) { $wpdb->query('ROLLBACK'); return false; }
            }
            $deleted = $wpdb->delete($table, array('id' => (int) $row['id']));
            if ($deleted === false || false === $wpdb->query('COMMIT')) { $wpdb->query('ROLLBACK'); return false; }
            return $deleted;
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return false; }
    }

    private static function diagnostics(string $raw_cutoff, int $check_cutoff, ?\DateTimeImmutable $boundary): array {
        global $wpdb;
        $raw = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS total, MIN(oldest_at) AS oldest_at FROM (SELECT p.id, MIN(e.occurred_at_ms) AS oldest_at FROM ' . Installer::participations_table() . ' p JOIN ' . Installer::events_table() . ' e ON e.participation_id = p.id WHERE p.last_heartbeat_at < %s GROUP BY p.id HAVING MAX(e.occurred_at) < %s) ftk_backlog', $raw_cutoff, $raw_cutoff), ARRAY_A);
        Statistics_Repository::assert_read();
        $checks = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS total, MIN(issued_at_ms) AS oldest_at FROM ' . Installer::checks_table() . ' WHERE issued_at_ms < %d', $check_cutoff), ARRAY_A);
        Statistics_Repository::assert_read();
        $abandoned = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::participations_table() . ' WHERE ended_at IS NULL AND last_heartbeat_at < %s', gmdate('Y-m-d H:i:s', time() - self::TIMEOUT_GRACE_SECONDS)));
        Statistics_Repository::assert_read();
        $aggregate_count = 0;
        $metadata_count = 0;
        $metadata_oldest = null;
        $oldest_date = null;
        if ($boundary !== null) {
            $daily = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS total, MIN(local_day) AS oldest_day FROM ' . Installer::statistics_table() . ' WHERE local_day < %s', $boundary->format('Y-m-d')), ARRAY_A);
            Statistics_Repository::assert_read();
            $scores = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS total, MIN(a.ends_at) AS oldest_end FROM ' . Installer::scores_table() . ' s JOIN ' . Installer::activities_table() . ' a ON a.id = s.activity_id WHERE a.ends_at < %s', Clock::ms_to_mysql($boundary->getTimestamp() * 1000)), ARRAY_A);
            Statistics_Repository::assert_read();
            $aggregate_count = (int) $daily['total'] + (int) $scores['total'];
            $oldest_date = $daily['oldest_day'] ?? null;
            if ($scores['oldest_end'] !== null) { $score_date = (new \DateTimeImmutable($scores['oldest_end'], new \DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d'); $oldest_date = $oldest_date === null ? $score_date : min($oldest_date, $score_date); }
            $metadata = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS total, MIN(p.started_at_ms) AS oldest_at FROM ' . Installer::participations_table() . ' p WHERE p.raw_events_expired = 1 AND p.ended_at_ms < %d AND NOT EXISTS (SELECT 1 FROM ' . Installer::events_table() . ' e WHERE e.participation_id = p.id)', $boundary->getTimestamp() * 1000), ARRAY_A);
            Statistics_Repository::assert_read();
            $metadata_count = (int) $metadata['total'];
            $metadata_oldest = $metadata['oldest_at'] === null ? null : (int) $metadata['oldest_at'];
        }
        return array('backlog' => array('raw' => (int) $raw['total'], 'checks' => (int) $checks['total'], 'aggregates' => $aggregate_count, 'metadata' => $metadata_count, 'abandoned' => (int) $abandoned),
            'oldest' => array('rawAtMs' => $raw['oldest_at'] === null ? null : (int) $raw['oldest_at'], 'checkAtMs' => $checks['oldest_at'] === null ? null : (int) $checks['oldest_at'], 'aggregateDate' => $oldest_date, 'metadataAtMs' => $metadata_oldest));
    }

    private static function metadata_candidates(\DateTimeImmutable $boundary, int $after_id, int $limit = self::BATCH_LIMIT): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT p.id FROM ' . Installer::participations_table() . ' p WHERE p.id > %d AND p.raw_events_expired = 1 AND p.ended_at_ms < %d AND NOT EXISTS (SELECT 1 FROM ' . Installer::events_table() . ' e WHERE e.participation_id = p.id) ORDER BY p.id ASC LIMIT %d', $after_id, $boundary->getTimestamp() * 1000, $limit), ARRAY_A);
        Statistics_Repository::assert_read();
        return $rows;
    }

    /** Provenance is retained while any still-kept aggregate may depend on it. */
    private static function purge_metadata(int $id, \DateTimeImmutable $boundary): int|false {
        global $wpdb;
        if (false === $wpdb->query('START TRANSACTION')) { return false; }
        try {
            $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::participations_table() . ' WHERE id = %d FOR UPDATE', $id), ARRAY_A);
            Statistics_Repository::assert_read();
            if ($p === null) { $wpdb->query('COMMIT'); return 0; }
            if (empty($p['raw_events_expired']) || $p['ended_at_ms'] === null || (int) $p['ended_at_ms'] >= $boundary->getTimestamp() * 1000) { $wpdb->query('ROLLBACK'); return false; }
            $raw = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::events_table() . ' WHERE participation_id = %d', $id));
            Statistics_Repository::assert_read();
            $ids = Participation_Repository::activity_ids($id);
            Statistics_Repository::assert_read();
            if ((int) $raw > 0) { $wpdb->query('ROLLBACK'); return false; }
            if ($ids !== array()) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $scores = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::scores_table() . " WHERE user_id = %d AND activity_id IN ({$placeholders})", array_merge(array((int) $p['user_id']), $ids)));
                Statistics_Repository::assert_read();
                $active = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::activities_table() . " WHERE id IN ({$placeholders}) AND ends_at > starts_at AND ends_at >= %s", array_merge($ids, array(Clock::ms_to_mysql($boundary->getTimestamp() * 1000)))));
                Statistics_Repository::assert_read();
                if ((int) $scores > 0 || (int) $active > 0) { $wpdb->query('ROLLBACK'); return false; }
            }
            // A local day may differ from UTC by more than one calendar date.
            // A conservative two-day margin keeps surviving daily signatures
            // intact until their summaries have also expired and been removed.
            $daily = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::statistics_table() . ' WHERE user_id = %d AND local_day >= %s AND local_day <= %s', (int) $p['user_id'], gmdate('Y-m-d', intdiv((int) $p['started_at_ms'], 1000) - 2 * DAY_IN_SECONDS), gmdate('Y-m-d', intdiv((int) $p['ended_at_ms'], 1000) + 2 * DAY_IN_SECONDS)));
            Statistics_Repository::assert_read();
            if ((int) $daily > 0) { $wpdb->query('ROLLBACK'); return false; }
            if (false === $wpdb->delete(Installer::activity_links_table(), array('participation_id' => $id))) { $wpdb->query('ROLLBACK'); return false; }
            $deleted = $wpdb->delete(Installer::participations_table(), array('id' => $id));
            if ($deleted === false || false === $wpdb->query('COMMIT')) { $wpdb->query('ROLLBACK'); return false; }
            return $deleted;
        } catch (Throwable $error) { $wpdb->query('ROLLBACK'); return false; }
    }
}
