<?php
declare(strict_types=1);

require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/statistics-fixture.php';

use FocusTracker\Database\Check_Repository;
use FocusTracker\Database\Installer;
use FocusTracker\Database\Statistics_Repository as Repository;
use FocusTracker\Privacy\Privacy_Guard;
use FocusTracker\Reporting\Statistics_Service as Statistics;
use FocusTracker\Support\Settings;

$assertions = 0;
function sqp_check(bool $condition, string $label): void {
    global $assertions;
    ++$assertions;
    if (! $condition) { throw new RuntimeException($label); }
}
function sqp_ms(string $time): int { return (new DateTimeImmutable($time, wp_timezone()))->getTimestamp() * 1000; }
function sqp_check_row(int $user, int $at, string $status = 'pending'): array {
    return Check_Repository::create(array('user_id' => $user, 'problem_id' => 123, 'submission_id' => null,
        'context_type' => 'general', 'context_id' => null, 'problem_hash' => str_repeat('a', 64),
        'source_kind' => 'problem', 'source_hash' => str_repeat('b', 64), 'candidate_json' => '{}',
        'issued_at_ms' => $at, 'expires_at_ms' => $at + 600000, 'status' => $status));
}
function sqp_query_counts(array $queries): array {
    $counts = array('total' => count($queries), 'privacy' => 0, 'sessions' => 0, 'checks' => 0, 'dailyReads' => 0, 'dailyWrites' => 0, 'eventReads' => 0);
    foreach ($queries as $query) {
        if (str_contains($query, 'SELECT option_value') && str_contains($query, 'focus_tracker_privacy_')) { ++$counts['privacy']; }
        if (str_contains($query, 'SELECT * FROM ' . Installer::participations_table())) { ++$counts['sessions']; }
        if (str_contains($query, 'FROM ' . Installer::checks_table()) && str_starts_with($query, 'SELECT')) { ++$counts['checks']; }
        if (str_contains($query, 'SELECT * FROM ' . Installer::statistics_table())) { ++$counts['dailyReads']; }
        if (str_contains($query, 'INSERT INTO ' . Installer::statistics_table())) { ++$counts['dailyWrites']; }
        if (str_contains($query, 'FROM ' . Installer::events_table()) && str_starts_with($query, 'SELECT')) { ++$counts['eventReads']; }
    }
    return $counts;
}
function sqp_day(array $report, string $date): array {
    foreach ($report['days'] as $day) { if ($day['date'] === $date) { return $day; } }
    throw new RuntimeException('Missing day in query-performance fixture.');
}

$now = sqp_ms('2026-05-02 12:00:00');
update_option('focus_tracker_statistics_coverage_since', sqp_ms('2026-01-01'));
for ($day = new DateTimeImmutable('2026-03-01', wp_timezone()); $day < new DateTimeImmutable('2026-05-01', wp_timezone()); $day = $day->modify('+1 day')) {
    $start = $day->setTime(10, 0)->getTimestamp() * 1000;
    statistics_fixture_session(1, 123, $start, 60, 'sqp-' . $day->format('Y-m-d'));
    if ((int) $day->format('j') % 5 === 0) { sqp_check_row(1, $start + 1000, 'incorrect'); }
}
$wpdb->queries = array();
$first = Statistics::report(1, 'month', '2026-04-12', array('type' => 'self', 'id' => ''), $now);
$first_counts = sqp_query_counts($wpdb->queries);
$wpdb->queries = array();
$second = Statistics::report(1, 'month', '2026-04-12', array('type' => 'self', 'id' => ''), $now);
$second_counts = sqp_query_counts($wpdb->queries);
$evidence = array('first' => $first_counts, 'repeat' => $second_counts, 'reportSha256' => hash('sha256', serialize($first)));
if (in_array('--baseline', $argv, true)) { echo json_encode($evidence, JSON_THROW_ON_ERROR) . "\n"; exit; }

sqp_check($first === $second, 'Repeated unchanged reports preserve every metric, category, comparison, and status.');
sqp_check($evidence['reportSha256'] === '51bf6f23bfc9791e980db2168c2f5c7a46cae50dc19d80b75cb1ca7f6c817db6', 'The complete response matches the pre-optimization baseline byte for byte.');
sqp_check($first_counts['total'] < 220 && $second_counts['total'] < 160, 'Seven-day metadata batches reduce two-month query counts without widening raw event reads.');
sqp_check($second_counts['dailyWrites'] === 0, 'Unchanged metrics and signatures never issue a daily upsert.');
sqp_check($first_counts['eventReads'] === 61 && $second_counts['eventReads'] === 61, 'Raw observations keep the original one-day read scope.');

// A daily source signature does not include mutable question states.
$check_at = sqp_ms('2026-04-15 10:00:00');
$check = sqp_check_row(2, $check_at);
$pending = Statistics::report(2, 'month', '2026-04-12', array('type' => 'self', 'id' => ''), $now);
$cached_pending = Repository::get(2, array('type' => 'self', 'id' => ''), '2026-04-15', wp_timezone()->getName());
sqp_check($pending['totals']['checks']['unanswered'] === 1, 'Expired pending questions are still normalized at the requested time.');
$wpdb->pdo->exec('UPDATE ' . Installer::checks_table() . " SET status='correct' WHERE id=" . (int) $check['id']);
$wpdb->queries = array();
$answered = Statistics::report(2, 'month', '2026-04-12', array('type' => 'self', 'id' => ''), $now);
$changed_counts = sqp_query_counts($wpdb->queries);
$cached_answered = Repository::get(2, array('type' => 'self', 'id' => ''), '2026-04-15', wp_timezone()->getName());
sqp_check($cached_pending['source_signature'] === $cached_answered['source_signature'], 'Question state changes leave the old session-only signature unchanged.');
sqp_check($answered['totals']['checks']['correct'] === 1 && $answered['totals']['checks']['unanswered'] === 0, 'A matching signature never hides a newly changed question response.');
sqp_check($changed_counts['dailyWrites'] === 1, 'Only the changed question day is persisted.');

// Every request obtains a new erasure watermark; only its own seven-day batch shares it.
statistics_fixture_session(3, 123, sqp_ms('2026-04-14 10:00:00'), 60, 'sqp-cutoff-before');
statistics_fixture_session(3, 123, sqp_ms('2026-04-16 10:00:00'), 60, 'sqp-cutoff-after');
$privacy_key = (new ReflectionClass(Privacy_Guard::class))->getMethod('key')->invoke(null, 3);
$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", $privacy_key, 'open:' . sqp_ms('2026-04-15 12:00:00')));
$cutoff_report = Statistics::report(3, 'month', '2026-04-12', array('type' => 'self', 'id' => ''), $now);
sqp_check(sqp_day($cutoff_report, '2026-04-14')['activeSeconds'] === null, 'Pre-erasure days remain unavailable.');
sqp_check(sqp_day($cutoff_report, '2026-04-16')['activeSeconds'] === 60.0, 'New post-erasure learning remains measurable.');
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s", 'open:' . sqp_ms('2026-04-17 12:00:00'), $privacy_key));
$new_cutoff_report = Statistics::report(3, 'month', '2026-04-12', array('type' => 'self', 'id' => ''), $now);
sqp_check(sqp_day($new_cutoff_report, '2026-04-16')['activeSeconds'] === null, 'A new report cannot reuse a previous request watermark.');

// A long timezone archive history must use the original conservative day lookup.
$empty_metrics = Statistics::day(4, array('type' => 'self', 'id' => ''), new DateTimeImmutable('2026-04-15', wp_timezone()), sqp_ms('2026-04-16'), $now, false);
$empty_metrics['_checksArchived'] = true;
for ($i = 0; $i < 257; ++$i) {
    Repository::save(4, array('type' => 'self', 'id' => ''), '2026-04-15', 'Invalid/Zone-' . $i, $empty_metrics, str_repeat('c', 64), true);
}
$range = Repository::range_inputs(4, array('type' => 'self', 'id' => ''), sqp_ms('2026-04-13'), sqp_ms('2026-04-20'), '2026-04-13', '2026-04-19', wp_timezone()->getName());
sqp_check($range['archivedCheckCandidates'] === null, 'Large archive candidate sets are bounded and marked for original-day fallback.');
sqp_check(Repository::has_archived_check_overlap(4, sqp_ms('2026-04-15'), sqp_ms('2026-04-16')), 'The sixteen-row conservative overlap policy survives fallback.');
sqp_check(! Repository::archived_check_overlap(array_fill(0, 16, array('local_day' => '2026-06-01', 'time_zone' => 'Invalid/Zone')), sqp_ms('2026-04-15'), sqp_ms('2026-04-16')), 'Other days in a batch cannot create false archived overlap.');
sqp_check(Repository::archived_check_overlap(array(array('local_day' => '2026-04-30', 'time_zone' => 'UTC')), sqp_ms('2026-05-01'), sqp_ms('2026-05-02')), 'Timezone-shifted archived days still overlap the same actual instants.');

// The original archive path remains live and is never replaced by normal-report cache reuse.
$archived_start = sqp_ms('2026-04-10 10:00:00');
statistics_fixture_session(5, 123, $archived_start, 60, 'sqp-raw-expired');
$archive_day = new DateTimeImmutable('2026-04-10', wp_timezone());
$before_archive = Statistics::day(5, array('type' => 'self', 'id' => ''), $archive_day, sqp_ms('2026-04-11'), $now, true, true);
$wpdb->pdo->exec('DELETE FROM ' . Installer::events_table() . ' WHERE participation_id IN (SELECT id FROM ' . Installer::participations_table() . ' WHERE user_id=5)');
$wpdb->pdo->exec('UPDATE ' . Installer::participations_table() . ' SET raw_events_expired=1 WHERE user_id=5');
$archived_report = Statistics::report(5, 'month', '2026-04-12', array('type' => 'self', 'id' => ''), $now);
$archived_day = sqp_day($archived_report, '2026-04-10');
sqp_check($archived_day['status'] === 'archived' && $archived_day['activeSeconds'] === 60.0, 'Raw expiry preserves the frozen daily aggregate through a batched report.');
sqp_check($archived_day['pageCategories'] === $before_archive['pageCategories'], 'Every frozen category survives raw-event expiry unchanged.');
$partial_archive = Statistics::day(5, array('type' => 'self', 'id' => ''), $archive_day, $archived_start + 30000, $now, false);
sqp_check($partial_archive['status'] === 'unavailable', 'A daily archive never invents a partial-day comparison.');
$settings_before = Settings::all();
Settings::update(array('retention_days' => 1, 'check_retention_days' => 1, 'aggregate_retention_days' => 15));
$expired_aggregate = Statistics::report(5, 'month', '2026-04-12', array('type' => 'self', 'id' => ''), $now);
sqp_check(sqp_day($expired_aggregate, '2026-04-10')['status'] === 'unavailable', 'Expired daily aggregates are not resurrected by metadata batching.');
Settings::update($settings_before);

// Query errors and invalid snapshots abort the report rather than return partial metrics.
$wpdb->before_results = static function (string $sql): void {
    if (str_contains($sql, 'SELECT id, problem_id, context_type, context_id, issued_at_ms') && str_contains($sql, Installer::checks_table())) { $GLOBALS['wpdb']->last_error = 'Injected batch read failure'; }
};
$wpdb->queries = array(); $failed = false;
try { Statistics::report(99, 'month', '2026-04-12', array('type' => 'self', 'id' => ''), $now); }
catch (RuntimeException $error) { $failed = true; }
$wpdb->before_results = null; $wpdb->last_error = '';
sqp_check($failed && sqp_query_counts($wpdb->queries)['dailyWrites'] === 0, 'A failed batched question read aborts before any derived write.');
$snapshot = Repository::get(1, array('type' => 'self', 'id' => ''), '2026-04-15', wp_timezone()->getName());
$wpdb->pdo->exec('UPDATE ' . Installer::statistics_table() . " SET metrics_json='invalid-json' WHERE id=" . (int) $snapshot['id']);
$failed = false;
try { Statistics::report(1, 'month', '2026-04-12', array('type' => 'self', 'id' => ''), $now); }
catch (RuntimeException $error) { $failed = true; }
sqp_check($failed, 'Invalid daily JSON in a batch remains a fail-closed storage error.');

echo 'Statistics query performance: ' . $assertions . ' checks passed. ' . json_encode($evidence, JSON_THROW_ON_ERROR) . "\n";
