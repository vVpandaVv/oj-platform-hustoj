<?php
declare(strict_types=1);
define('FOCUS_MYSQL_OBSERVATION_LIBRARY_ONLY', true);
require __DIR__ . '/real-wordpress/mysql-observation.php';
$checks = 0;
function check_observer(bool $condition, string $message): void { global $checks; ++$checks; if (! $condition) { throw new RuntimeException($message); } }
function observer_row(string $fingerprint, int $calls, float $timer, int $errors = 0, string $family = 'events'): array {
    return array('fingerprint' => $fingerprint, 'statementType' => 'SELECT', 'calls' => (string) $calls,
        'timerPs' => (string) $timer, 'lockPs' => (string) ($timer / 10), 'rowsExamined' => (string) ($calls * 2),
        'rowsSent' => (string) $calls, 'rowsAffected' => '0', 'errors' => (string) $errors, 'has_' . $family => '1');
}
$key = str_repeat('a', 64); $other = str_repeat('b', 64);
// Reproduces the native nonzero slot followed by a zero slot. No SQL text,
// account identity, database extension or native WordPress bootstrap is needed.
$rows = array(observer_row($key, 17, 9000000, 2), observer_row($key, 0, 0), observer_row($other, 3, 2000000));
$a = observation_digest_rows($rows);
check_observer($a['statements'][$key]['calls'] === 17, 'A later zero slot cannot erase native call counts');
check_observer($a['statements'][$key]['errors'] === 2, 'A later zero slot cannot hide native errors');
check_observer($a['statements'][$key]['timerPs'] === 9000000.0, 'A later zero slot cannot erase native elapsed time');
check_observer($a === observation_digest_rows(array_reverse($rows)), 'Aggregation is independent of PFS row order');
check_observer(count($a['statements']) === 2 && $a['duplicateFingerprintRows'] === 1, 'Distinct fingerprints remain distinct and duplicates are disclosed');
$combined = observation_digest_rows(array(observer_row($key, 2, 3000000, 1), observer_row($key, 5, 7000000, 2, 'options')));
check_observer($combined['statements'][$key]['calls'] === 7 && $combined['statements'][$key]['errors'] === 3, 'Every nonzero duplicate slot contributes calls and errors');
check_observer($combined['statements'][$key]['tables'] === array('events', 'options'), 'Table families are safely combined without SQL text');
check_observer(observation_digest_rows(array(), true)['truncated'] === true, 'Truncation remains explicit');
$before = array('schemaVersion' => 1, 'capturedAtMs' => 10, 'environment' => array(), 'status' => array('InnoDB_lock_deadlocks' => 4), 'digests' => $a, 'scopeNote' => 'test');
$after = $before; $after['capturedAtMs'] = 20; $after['digests'] = observation_digest_rows(array(observer_row($key, 19, 12000000, 3), observer_row($key, 0, 0), observer_row($other, 3, 2000000)));
$delta = observation_diff($before, $after);
check_observer($delta['digestAggregationReliable'] && ! $delta['digestCoverageIncomplete'], 'Both corrected snapshots permit reliable fingerprint deltas');
check_observer(count($delta['statements']) === 1 && $delta['statements'][0]['calls'] === 2 && $delta['statements'][0]['errors'] === 1 && $delta['statements'][0]['elapsedMs'] === 0.003, 'Call, elapsed and error increments derive from aggregated snapshots');
unset($before['digests']['fingerprintAggregation']); $legacy = observation_diff($before, $after);
check_observer(! $legacy['digestAggregationReliable'] && $legacy['digestCoverageIncomplete'], 'Legacy last-slot snapshots cannot claim complete aggregation');
check_observer($legacy['statusDelta']['InnoDB_lock_deadlocks'] === 0, 'Independent global counters survive legacy digest uncertainty');
echo 'MySQL observer: ' . $checks . " checks passed\n";
