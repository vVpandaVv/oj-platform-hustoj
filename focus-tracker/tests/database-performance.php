<?php
declare(strict_types=1);
require __DIR__ . '/test-bootstrap.php';
require __DIR__ . '/platform-fixture.php';

use FocusTracker\Database\Activity_Repository as Activities;
use FocusTracker\Database\Installer;
use FocusTracker\Rest\Controllers\Events_Controller as Ingest;
use FocusTracker\Support\Clock;
use FocusTracker\Support\Diagnostics;
use FocusTracker\Tracking\Activity_Window_Gate as Gate;

$checks = 0;
function performance_check(bool $condition, string $message): void {
    global $checks, $wpdb;
    ++$checks;
    if (! $condition) { throw new RuntimeException($message . ' SQL: ' . $wpdb->last_error); }
}
function performance_queries(string $pattern): array {
    global $wpdb;
    return array_values(array_filter($wpdb->queries, static fn (string $sql): bool => preg_match($pattern, $sql) === 1));
}

// An unhealthy engine is still rejected, but ordinary submissions no longer
// repeat eight installations and all metadata reads during the retry window.
$GLOBALS['focus_test_storage_engine'] = 'MyISAM';
update_option('focus_tracker_db_version', 'old-version');
update_option('focus_tracker_schema_health', array('ready' => false));
update_option('focus_tracker_schema_retry', array('version' => 'previous-version', 'afterMs' => Clock::now_ms() + 60000));
$wpdb->queries = array();
Installer::maybe_install();
performance_check(count(performance_queries('/^CREATE TABLE/')) === 8, 'The first failed upgrade attempts every owned table exactly once');
performance_check(! Installer::ready(), 'Backoff never marks a broken engine ready');
performance_check(in_array('wordpress_options:transaction_engine', get_option('focus_tracker_schema_health')['issues'], true), 'Unsafe WordPress options storage remains a visible failure');
$wpdb->queries = array();
for ($index = 0; $index < 50; ++$index) { Installer::maybe_install(); }
performance_check(performance_queries('/^(?:SHOW |CREATE TABLE)/') === array(), 'Fifty subsequent requests perform no repeated DDL or metadata inspection');
performance_check(get_option('focus_tracker_db_version') === 'old-version', 'A failed upgrade cannot advance its installed version');

unset($GLOBALS['focus_test_storage_engine']);
update_option('focus_tracker_schema_retry', array('version' => FOCUS_TRACKER_VERSION, 'afterMs' => Clock::now_ms() - 1));
Installer::maybe_install();
performance_check(Installer::ready(), 'The next eligible retry can recover without disabling readiness checks');
$health = get_option('focus_tracker_schema_health');
$health['checkedAtMs'] = Clock::now_ms() - 600001;
update_option('focus_tracker_schema_health', $health);
delete_option('focus_tracker_schema_retry');
$lease = wp_json_encode(array('token' => 'another-worker', 'expiresAtMs' => Clock::now_ms() + 120000));
add_option('focus_tracker_schema_maintenance_lock', $lease, '', false);
$wpdb->queries = array();
Installer::maybe_install();
performance_check(performance_queries('/^(?:SHOW |CREATE TABLE)/') === array(), 'Another live maintenance lease prevents a periodic inspection stampede');
performance_check(get_option('focus_tracker_schema_maintenance_lock') === $lease, 'A losing worker cannot clear the current lease');
update_option('focus_tracker_schema_maintenance_lock', wp_json_encode(array('token' => 'expired-worker', 'expiresAtMs' => Clock::now_ms() - 1)), false);
$wpdb->queries = array();
Installer::maybe_install();
performance_check(count(performance_queries('/^SHOW /')) === 34 && performance_queries('/^CREATE TABLE/') === array(), 'An expired lease is replaced atomically for one healthy inspection without DDL');
performance_check(get_option('focus_tracker_schema_maintenance_lock', false) === false, 'Maintenance releases only its own lease');
$wpdb->queries = array();
Installer::maybe_install();
performance_check(performance_queries('/^SHOW /') === array(), 'Fresh metadata prevents another immediate inspection');

$health = get_option('focus_tracker_schema_health');
$health['checkedAtMs'] = Clock::now_ms() - 600001;
update_option('focus_tracker_schema_health', $health);
delete_option('focus_tracker_schema_retry');
$wpdb->before_results = static function (string $sql) use ($wpdb, $health): void {
    if (! str_starts_with($sql, 'SELECT option_name, option_value FROM wp_options WHERE option_name IN')) { return; }
    $wpdb->before_results = null;
    update_option('focus_tracker_schema_health', array_merge($health, array('checkedAtMs' => Clock::now_ms())));
};
$wpdb->queries = array();
Installer::maybe_install();
performance_check(performance_queries('/^SHOW /') === array(), 'Durable state is rechecked after lease acquisition when another worker has already inspected');

update_option('focus_tracker_db_version', 'old-version');
$wpdb->fail_pattern = 'INSERT INTO wp_options';
$wpdb->queries = array();
Installer::maybe_install();
performance_check(performance_queries('/^CREATE TABLE/') === array(), 'DDL does not run if its retry boundary cannot be persisted');
performance_check(! Installer::ready(), 'An unrecorded maintenance attempt cannot bypass the version gate');
Installer::maybe_install();
performance_check(Installer::ready(), 'A later request recovers once the option store is writable');

// A hundred freshly authorized contexts use one activity query. No context
// result is cached across calls, so removals take effect on the next request.
$gateway = platform_fixture_connect_runtime(100);
$now = intdiv(Clock::now_ms(), 1000) * 1000;
$create = static fn (string $type, string $context): int => Activities::create(array('name' => '공유 일정', 'owner_user_id' => 1,
    'context_type' => $type, 'context_id' => $context, 'problem_ids' => array(123),
    'starts_at' => Clock::ms_to_mysql($now - 60000), 'ends_at' => Clock::ms_to_mysql($now + 60000),
    'weight_focus' => 8, 'weight_selfreg' => 6, 'weight_activity' => 6));
$activity = $create('class', 'class:1');
$create('class', 'class:100');
$create('class', 'class:101');
$create('group', 'class:1');
$wpdb->queries = array();
$state = Gate::state(1, 123, 'general', null, $now);
performance_check(is_array($state) && count($state['windows']) === 2, 'Only authorized type/id pairs contribute schedules');
performance_check(count(performance_queries('/^SELECT \* FROM wp_focus_tracker_activities/')) === 1, 'One hundred contexts use one indexed activity query');
$GLOBALS['focus_test_statistics_contexts'] = array();
$wpdb->queries = array();
performance_check(! Gate::state(1, 123, 'general', null, $now)['active'], 'Removing membership immediately revokes schedule authorization');
performance_check(performance_queries('/^SELECT \* FROM wp_focus_tracker_activities/') === array(), 'An empty authorized context list reads no schedules');
unset($GLOBALS['focus_test_statistics_contexts']);

// The policy read uses a shared lock; owned privacy/session/event locks stay
// exclusive. SQLite checks the executed SQL and race outcome, not InnoDB lock
// scheduling, which needs the separate native database acceptance environment.
$wpdb->query('START TRANSACTION');
$wpdb->queries = array();
Activities::find_overlapping_for_context('class', 'class:1', Clock::ms_to_mysql($now - 60000), Clock::ms_to_mysql($now + 60000), true);
performance_check(count(performance_queries('/LOCK IN SHARE MODE$/')) === 1 && performance_queries('/FOR UPDATE$/') === array(), 'Learner schedule validation uses MySQL/MariaDB shared locks');
$wpdb->query('ROLLBACK');

$event = static fn (int $seq): array => array('seq' => $seq, 'type' => 'activity', 'occurred_at' => $now - 1000, 'meta' => array('visible' => true, 'focused' => true));
$payload = static fn (string $uid, int $seq): array => array('user_id' => 1, 'session_uid' => $uid, 'session_started_at' => $now - 30000,
    'problem_id' => 123, 'context_type' => 'class', 'context_id' => '1', 'events' => array($event($seq)));
foreach (array('ends_at', 'status') as $race) {
    Activities::update($activity, array('status' => 'scheduled', 'ends_at' => Clock::ms_to_mysql($now + 60000)));
    $wpdb->before_results = static function (string $sql) use ($race, $activity, $now, $wpdb): void {
        if (! str_ends_with($sql, 'LOCK IN SHARE MODE')) { return; }
        $wpdb->before_results = null;
        Activities::update($activity, $race === 'status' ? array('status' => 'deleted') : array('ends_at' => Clock::ms_to_mysql($now - 5000)));
    };
    $result = Ingest::handle(new WP_REST_Request($payload('schedule-race-' . $race, 1)));
    performance_check($result instanceof WP_Error && $result->get_error_data()['status'] === 503, 'A teacher ' . $race . ' change between optimistic and locked reads aborts ingestion');
    performance_check((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::events_table()) === 0, 'Schedule races leave no unauthorized raw observations');
}
$wpdb->before_results = null;
Activities::update($activity, array('status' => 'scheduled', 'ends_at' => Clock::ms_to_mysql($now + 60000)));
$wpdb->queries = array();
$accepted = Ingest::handle(new WP_REST_Request($payload('valid-shared-schedule', 1)));
performance_check($accepted instanceof WP_REST_Response && $accepted->data['stored'] > 0, 'Unchanged schedules still accept genuine in-window observations');
performance_check(count(performance_queries('/LOCK IN SHARE MODE$/')) === 1 && count(performance_queries('/FOR UPDATE$/')) >= 2, 'Ingestion shares schedule locks while retaining exclusive privacy/source/event locks');

Diagnostics::reset();
$wpdb->queries = array();
$sample_now = Clock::now_ms();
for ($index = 0; $index < 100; ++$index) { Diagnostics::record('gate', 'inactive', $sample_now); }
$snapshot = Diagnostics::snapshot();
performance_check($snapshot['samplePeriodSeconds'] === 60 && $snapshot['operations']['gate']['counts']['inactive'] === 1, 'A hundred inactive probes produce one explicitly sampled observation');
performance_check(count(performance_queries('/^INSERT INTO wp_options/')) === 2, 'A hundred probes perform only two diagnostic counter writes');
Diagnostics::record('gate', 'inactive', $sample_now + 60000);
performance_check(Diagnostics::snapshot()['operations']['gate']['counts']['inactive'] === 2, 'The next minute can record a fresh observation');

// Exercise option reads across request boundaries, as a persistent Redis
// options cache would. A raw DELETE would strand the cached minute claim.
$GLOBALS['focus_test_option_cache'] = array();
$claim_key = 'focus_tracker_diagnostic_sampled_gate_inactive_minute';
performance_check(get_option($claim_key) === (string) intdiv($sample_now + 60000, 60000), 'The existing minute claim is present in the persistent option cache');
Diagnostics::reset();
performance_check(Diagnostics::snapshot()['operations']['gate']['counts']['inactive'] === 0, 'Reset clears the diagnostic counters');
performance_check(get_option($claim_key, false) === false, 'Reset invalidates the cached claim as well as its database row');
Diagnostics::record('gate', 'inactive', $sample_now + 120000);
performance_check(Diagnostics::snapshot()['operations']['gate']['counts']['inactive'] === 1, 'The first minute after reset resumes sampling with persistent options');
Diagnostics::record('gate', 'inactive', $sample_now + 180000);
performance_check(Diagnostics::snapshot()['operations']['gate']['counts']['inactive'] === 2, 'Later minutes continue after reset rather than returning forever on a stale CAS');
unset($GLOBALS['focus_test_option_cache']);

echo "Database performance: {$checks} SQL-backed checks passed\n";
