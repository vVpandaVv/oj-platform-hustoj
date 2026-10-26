<?php
declare(strict_types=1);

// Real orchestration with deterministic in-memory storage and clock. No WP boot.
namespace FocusTracker\Database {
    final class Participation_Repository {
        public static array $rows = array();
        public static function list_for_activity_and_user(int $activity, int $user): array { return self::$rows; }
    }
    final class Event_Repository {
        public static array $rows = array();
        public static int $reads = 0;
        public static function list_for_participations(array $ids): array { ++self::$reads; return self::$rows; }
    }
    final class Score_Repository {
        public static ?array $row = null;
        public static int $writes = 0;
        public static bool $fail_write = false;
        public static function get(int $activity, int $user): ?array { return self::$row; }
        public static function upsert(int $activity, int $user, array $metrics): bool {
            ++self::$writes;
            if (self::$fail_write) { return false; }
            if (empty(self::$row['raw_events_expired'])) { self::$row = $metrics; }
            return true;
        }
        public static function invalidate(int $activity, int $user): void {
            if (empty(self::$row['raw_events_expired'])) { self::$row = null; }
        }
    }
}
namespace FocusTracker\Support {
    final class Clock {
        public static int $now = 0;
        public static function now_ms(): int { return self::$now; }
        public static function mysql_to_ms(string $time): int { return strtotime($time . ' UTC') * 1000; }
    }
    final class Settings {
        public static int $window = 30;
        public static function get(string $key): int { return self::$window; }
        public static function aggregate_cutoff(?int $now_ms = null): ?\DateTimeImmutable { return null; }
    }
}
namespace FocusTracker\Privacy {
    final class Privacy_Guard {
        public static function cutoff(int $user_id): int { return 0; }
    }
}
namespace {
    require_once dirname(__DIR__) . '/src/Scoring/Score_Calculator.php';
    require_once dirname(__DIR__) . '/src/Scoring/Activity_Score_Service.php';
    use FocusTracker\Database\Participation_Repository as Participations;
    use FocusTracker\Database\Event_Repository as Events;
    use FocusTracker\Database\Score_Repository as Scores;
    use FocusTracker\Support\Clock;
    use FocusTracker\Support\Settings;
    use FocusTracker\Scoring\Activity_Score_Service as Service;
    $checks = 0;
    function check(mixed $expected, mixed $actual, string $label): void {
        global $checks;
        ++$checks;
        if ($expected !== $actual) { throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
    }
    function reset_fixture(int $end = 60000): array {
        Clock::$now = 110000;
        Settings::$window = 30;
        Scores::$row = null;
        Scores::$writes = 0;
        Scores::$fail_write = false;
        Events::$reads = 0;
        Participations::$rows = array(array('id' => 1, 'problem_id' => 1, 'started_at_ms' => 0, 'ended_at_ms' => $end, 'last_heartbeat_at_ms' => $end, 'event_revision' => 1, 'raw_events_expired' => 0, 'has_delivery_gaps' => 0));
        Events::$rows = array(1 => array(array('type' => 'session_start', 'occurred_at' => 0, 'meta' => array('visible' => true, 'focused' => true))));
        for ($at = 15000; $at < $end; $at += 15000) { Events::$rows[1][] = array('type' => 'heartbeat', 'occurred_at' => $at); }
        return array('id' => 1, 'starts_at' => '1970-01-01 00:00:00', 'ends_at' => '1970-01-01 00:01:00', 'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6);
    }
    function add_work(): void {
        Events::$rows[1][] = array('type' => 'activity', 'occurred_at' => 5000);
        Events::$rows[1][] = array('type' => 'activity', 'occurred_at' => 35000);
        ++Participations::$rows[0]['event_revision'];
    }

    $activity = reset_fixture(10000);
    Events::$rows[1][] = array('type' => 'activity', 'occurred_at' => 5000);
    Clock::$now = 10000;
    check(20.0, Service::score_for_learner($activity, 1)['score_total'], 'early score is legitimately full so far');
    Clock::$now = 60000;
    $late = Service::score_for_learner($activity, 1);
    check(2.5, $late['score_total'], 'normal read advances abandoned lesson window');
    check(false, $late['cached'], 'no stale progressing cache reused');
    check(false, $late['finalized'], 'end is provisional until heartbeat grace settles');
    Clock::$now = 105000;
    check(false, Service::score_for_learner($activity, 1)['finalized'], 'exact grace boundary still provisional');
    Clock::$now = 105001;
    check(true, Service::score_for_learner($activity, 1)['finalized'], 'after grace final snapshot settles');
    $reads = Events::$reads;
    Clock::$now = 200000;
    check(true, Service::score_for_learner($activity, 1)['cached'], 'settled signature-valid cache reused');
    check($reads, Events::$reads, 'valid final cache avoids event replay');
    $activity['weight_focus'] = 16;
    check(2.7, Service::score_for_learner($activity, 1)['score_focus'], 'weight change invalidates signature');
    $activity['ends_at'] = '1970-01-01 00:10:00';
    check(false, Service::score_for_learner($activity, 1)['finalized'], 'extending activity invalidates final state');

    $activity = reset_fixture();
    check(14.0, Service::score_for_learner($activity, 1)['score_total'], 'idle page follows existing rubric');
    add_work();
    $corrected = Service::score_for_learner($activity, 1);
    check(20.0, $corrected['score_total'], 'late event revision repairs a cached final score');
    check(false, $corrected['cached'], 'same-heartbeat event revision forces replay');
    check(false, $corrected['needs_review'], 'observed work clears idle review');
    Settings::$window = 60;
    $new_window = Service::score_for_learner($activity, 1);
    check(false, $new_window['cached'], 'bucket setting invalidates final cache');
    check(100.0, $new_window['activity_window_ratio'], 'changed bucket is recalculated');
    Participations::$rows[0]['has_delivery_gaps'] = 1;
    $incomplete = Service::score_for_learner($activity, 1);
    check('incomplete', $incomplete['data_status'], 'reported loss has explicit quality status');
    check(true, $incomplete['needs_review'], 'reported loss requires review');
    check(true, Service::score_for_learner($activity, 1)['needs_review'], 'cached loss flag remains visible');

    $activity = reset_fixture();
    add_work();
    $original = Service::score_for_learner($activity, 1);
    Scores::$row['raw_events_expired'] = 1;
    Participations::$rows[0]['raw_events_expired'] = 1;
    Events::$rows = array();
    $archived = Service::score_for_learner($activity, 1, true);
    check($original['score_total'], $archived['score_total'], 'forced refresh cannot rewrite erased-source grade');
    check('archived', $archived['data_status'], 'retained snapshot reports source expiry');
    check(false, $archived['needs_review'], 'unchanged frozen snapshot remains valid');
    $activity['weight_focus'] = 2;
    $changed_archive = Service::score_for_learner($activity, 1, true);
    check($original['score_total'], $changed_archive['score_total'], 'new weight cannot fabricate archived result');
    check(true, $changed_archive['needs_review'], 'changed archived configuration is flagged');
    Service::invalidate(1, 1);
    check(true, Scores::$row !== null, 'event invalidation preserves archived snapshot');
    Scores::$row = null;
    $missing_archive = Service::score_for_learner($activity, 1, true);
    check('unavailable', $missing_archive['data_status'], 'expired source without snapshot is unavailable');
    check(true, $missing_archive['needs_review'], 'unavailable is reviewed rather than graded');
    check(null, Scores::$row, 'missing historical snapshot is never fabricated');

    $activity = reset_fixture();
    Events::$rows = array();
    $missing_raw = Service::score_for_learner($activity, 1);
    check('unavailable', $missing_raw['data_status'], 'legacy purged source is detected');
    check(null, Scores::$row, 'legacy missing events cannot become an invented fourteen');
    Events::$rows[1] = array(array('type' => 'session_start', 'occurred_at' => 0));
    Participations::$rows[] = array('id' => 2, 'started_at_ms' => 30000, 'ended_at_ms' => 60000, 'last_heartbeat_at_ms' => 60000);
    Participations::$rows[0]['ended_at_ms'] = 10000;
    $partial = Service::score_for_learner($activity, 1);
    check('incomplete', $partial['data_status'], 'partially missing raw events require review');
    check(10, $partial['total_active_seconds'], 'missing participation does not invent active duration');
    check(null, Scores::$row, 'incomplete legacy source is not frozen for retention');

    $activity = reset_fixture();
    Participations::$rows = array();
    $none = Service::score_for_learner($activity, 1);
    check('no_data', $none['data_status'], 'absent student has explicit missing-data status');
    check(true, $none['needs_review'], 'no evidence needs review');
    check(0.0, $none['score_total'], 'no data numerical placeholder stays zero');
    check('no_data', Service::score_for_learner($activity, 1)['data_status'], 'no-data cache retains status');

    $activity = reset_fixture();
    add_work();
    Participations::$rows[0]['ended_at_ms'] = null;
    Participations::$rows[0]['last_heartbeat_at_ms'] = 59999;
    Clock::$now = 60000;
    $fresh = Service::score_for_learner($activity, 1);
    check(20.0, $fresh['score_total'], 'service passes real observation time to heartbeat grace');
    Clock::$now = 120000;
    $settled = Service::score_for_learner($activity, 1);
    check(true, $settled['abandoned_without_return'], 'expired grace recomputes provisional tail');
    check(true, Service::score_for_learner($activity, 1)['abandoned_without_return'], 'cached abandonment bool is preserved');

    $activity = reset_fixture(600000);
    $activity['ends_at'] = '1970-01-01 00:10:00';
    Clock::$now = 700000;
    Events::$rows[1] = array(array('type' => 'session_start', 'occurred_at' => 0), array('type' => 'heartbeat', 'occurred_at' => 15000), array('type' => 'heartbeat', 'occurred_at' => 600000));
    $gap_score = Service::score_for_learner($activity, 1);
    check('incomplete', $gap_score['data_status'], 'long observation silence is incomplete');
    check(true, $gap_score['has_observation_gaps'], 'service exposes observation gap');
    check(true, $gap_score['needs_review'], 'service does not grade a guessed active stretch');
    check('incomplete', Service::score_for_learner($activity, 1)['data_status'], 'final gap cache retains incomplete status');
    Scores::$row['raw_events_expired'] = 1;
    Participations::$rows[0]['raw_events_expired'] = 1;
    Events::$rows = array();
    $gap_archive = Service::score_for_learner($activity, 1, true);
    check(true, $gap_archive['has_observation_gaps'], 'archived snapshot retains observation uncertainty');
    check(true, $gap_archive['needs_review'], 'archived uncertainty remains reviewable');

    $activity = reset_fixture();
    Service::score_for_learner($activity, 1);
    add_work();
    Scores::$fail_write = true;
    $failed_save = Service::score_for_learner($activity, 1, true);
    check(false, $failed_save['cache_saved'], 'failed snapshot save is explicit for retention');
    check(20.0, $failed_save['score_total'], 'current observations remain readable after cache-write failure');
    check(14.0, Scores::$row['score_total'], 'failed write leaves old final snapshot distinguishable');

    $activity = reset_fixture();
    add_work();
    $activity['problem_ids'] = array(1);
    check(20.0, Service::score_for_learner($activity, 1)['score_total'], 'matching named problem is scored');
    $activity['problem_ids'] = array(2);
    $changed_problem = Service::score_for_learner($activity, 1);
    check('no_data', $changed_problem['data_status'], 'old problem link cannot grade a new problem selection');
    check(0.0, $changed_problem['score_total'], 'excluded old problem gives no invented score');
    $activity['problem_ids'] = array(1);
    check(20.0, Service::score_for_learner($activity, 1)['score_total'], 'restoring problem invalidates previous no-data signature');
    $activity['starts_at'] = '1970-01-01 00:02:00';
    $activity['ends_at'] = '1970-01-01 00:03:00';
    Clock::$now = 230000;
    $moved = Service::score_for_learner($activity, 1);
    check('no_data', $moved['data_status'], 'moving window excludes nonoverlapping old participation');
    check(0.0, $moved['score_total'], 'old participation cannot earn minimum points in a new window');

    $activity = reset_fixture();
    $activity['starts_at'] = '1970-01-01 00:01:00';
    $activity['ends_at'] = '1970-01-01 00:02:00';
    Clock::$now = 170000;
    check('no_data', Service::score_for_learner($activity, 1)['data_status'], 'touching endpoints do not overlap');

    $activity = reset_fixture();
    add_work();
    Participations::$rows[0]['has_delivery_gaps'] = 1;
    Service::score_for_learner($activity, 1);
    Scores::$row['raw_events_expired'] = 1;
    Participations::$rows[0]['raw_events_expired'] = 1;
    $activity['problem_ids'] = array(2);
    check(true, Service::score_for_learner($activity, 1)['has_delivery_gaps'], 'archived delivery uncertainty survives a changed selection');
    echo 'scoring-service: ' . $checks . " checks passed\n";
}
