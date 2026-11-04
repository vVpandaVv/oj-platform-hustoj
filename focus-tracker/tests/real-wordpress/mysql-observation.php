<?php
declare(strict_types=1);

/**
 * Read-only observer for the disposable Oracle MySQL acceptance instance.
 * No WordPress bootstrap, SQL text, bind values, lock data, or account IDs.
 *
 * php mysql-observation.php snapshot before
 * php mysql-observation.php snapshot after
 * php mysql-observation.php diff before after
 * php mysql-observation.php watch concurrent 90 500
 * php mysql-observation.php deadlock latest-cycle
 *
 * watch arguments are duration seconds and interval milliseconds. Run this
 * alongside the native workers; it never creates fixtures or changes counters.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

function observation_path(string $path): string { return strtolower(str_replace('\\', '/', rtrim($path, '/\\'))); }
function observation_name(string $value): string {
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $value) !== 1 || str_contains($value, '..')) { throw new RuntimeException('invalid_artifact_name'); }
    return $value;
}
function observation_save(string $path, array $value): void {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) { throw new RuntimeException('artifact_write'); }
}
function observation_rows(mysqli $db, string $sql): array {
    $result = $db->query($sql);
    if (! $result instanceof mysqli_result) { throw new RuntimeException('observer_query'); }
    return $result->fetch_all(MYSQLI_ASSOC);
}
function observation_deadlock(mysqli $db, string $database, string $prefix): array {
    // InnoDB status contains SQL and record values. Parse only anchored engine
    // metadata in memory; never persist or print the original status text.
    $status = observation_rows($db, 'SHOW ENGINE INNODB STATUS')[0]['Status'] ?? '';
    $position = strpos($status, 'LATEST DETECTED DEADLOCK');
    if ($position === false) { return array('available' => false, 'locks' => array(), 'rollbackTransaction' => null); }
    $section = substr($status, $position);
    unset($status);
    $boundary = strpos($section, "\nTRANSACTIONS\n");
    if ($boundary !== false) { $section = substr($section, 0, $boundary); }
    $allowed = array_map(static fn (string $name): string => $prefix . 'focus_tracker_' . $name, array('activities', 'participations', 'activity_links', 'events', 'scores', 'checks', 'check_states', 'daily_statistics'));
    $allowed[] = $prefix . 'options';
    $transaction = null; $phase = null; $locks = array(); $victim = null;
    foreach (explode("\n", $section) as $line) {
        if (preg_match('/^\*\*\* \(([0-9]+)\) (TRANSACTION|HOLDS THE LOCK|WAITING FOR THIS LOCK)/D', trim($line), $match)) {
            $transaction = (int) $match[1]; $phase = str_starts_with($match[2], 'WAITING') ? 'waiting' : (str_starts_with($match[2], 'HOLDS') ? 'held' : null);
        }
        if (preg_match('/^\*\*\* WE ROLL BACK TRANSACTION \(([0-9]+)\)/D', trim($line), $match)) { $victim = (int) $match[1]; }
        if ($phase === null || ! str_starts_with($line, 'RECORD LOCKS ') || ! preg_match('/ index `?([A-Za-z0-9_]+)`? of table `([A-Za-z0-9_]+)`\.`([A-Za-z0-9_]+)` .* lock_mode ([A-Z]+)/D', $line, $match)
            || $match[2] !== $database || ! in_array($match[3], $allowed, true) || ! in_array($match[4], array('S', 'X', 'IS', 'IX', 'AUTO_INC'), true)) { continue; }
        $locks[] = array('transactionOrdinal' => $transaction, 'phase' => $phase, 'tableName' => $match[3], 'indexName' => $match[1], 'mode' => $match[4],
            'gapBeforeRecord' => str_contains($line, 'locks gap before rec'), 'recordOnly' => str_contains($line, 'locks rec but not gap'), 'insertIntention' => str_contains($line, 'insert intention'));
    }
    unset($section);
    return array('available' => true, 'locks' => $locks, 'rollbackTransaction' => $victim,
        'scopeNote' => 'Latest engine deadlock only; metadata is limited to the disposable database addon/options tables. Transaction ordinals describe this cycle and are not account identifiers.');
}
function observation_status(mysqli $db): array {
    $out = array();
    foreach (observation_rows($db, "SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_deadlocks','Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_row_lock_time_max','Threads_connected','Threads_running','Com_select','Com_insert','Com_update','Com_delete')") as $row) {
        $out[$row['Variable_name']] = (int) $row['Value'];
    }
    foreach (observation_rows($db, "SELECT NAME,COUNT FROM information_schema.INNODB_METRICS WHERE NAME='lock_deadlocks'") as $row) { $out['InnoDB_lock_deadlocks'] = (int) $row['COUNT']; }
    return $out;
}
function observation_locks(mysqli $db, string $options): array {
    // LOCK_DATA and statement/process-list text are deliberately never selected.
    $waits = observation_rows($db, "SELECT r.THREAD_ID AS requestingThread, b.THREAD_ID AS blockingThread,
        rt.PROCESSLIST_ID AS requestingConnection, bt.PROCESSLIST_ID AS blockingConnection,
        r.OBJECT_NAME AS tableName, r.INDEX_NAME AS indexName, r.LOCK_TYPE AS lockType,
        r.LOCK_MODE AS requestingMode, b.LOCK_MODE AS blockingMode
        FROM performance_schema.data_lock_waits w
        JOIN performance_schema.data_locks r ON r.ENGINE=w.ENGINE AND r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID
        JOIN performance_schema.data_locks b ON b.ENGINE=w.ENGINE AND b.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID
        LEFT JOIN performance_schema.threads rt ON rt.THREAD_ID=r.THREAD_ID
        LEFT JOIN performance_schema.threads bt ON bt.THREAD_ID=b.THREAD_ID
        WHERE r.OBJECT_SCHEMA=DATABASE() AND (r.OBJECT_NAME LIKE '%!_focus!_tracker!_%' ESCAPE '!' OR r.OBJECT_NAME='{$options}')
        ORDER BY r.THREAD_ID,b.THREAD_ID LIMIT 200");
    $held = observation_rows($db, "SELECT OBJECT_NAME AS tableName, INDEX_NAME AS indexName, LOCK_TYPE AS lockType,
        LOCK_MODE AS lockMode, LOCK_STATUS AS lockStatus, COUNT(*) AS lockCount
        FROM performance_schema.data_locks
        WHERE OBJECT_SCHEMA=DATABASE() AND (OBJECT_NAME LIKE '%!_focus!_tracker!_%' ESCAPE '!' OR OBJECT_NAME='{$options}')
        GROUP BY OBJECT_NAME,INDEX_NAME,LOCK_TYPE,LOCK_MODE,LOCK_STATUS ORDER BY OBJECT_NAME,INDEX_NAME,LOCK_MODE LIMIT 200");
    return array('waits' => $waits, 'heldLockGroups' => $held, 'waitsTruncated' => count($waits) === 200, 'lockGroupsTruncated' => count($held) === 200);
}
function observation_digests(mysqli $db, string $options): array {
    $tables = array('participations', 'events', 'activities', 'activity_links', 'checks', 'check_states', 'scores', 'daily_statistics');
    $flags = array();
    foreach ($tables as $table) { $flags[] = "(DIGEST_TEXT LIKE '%focus_tracker_{$table}%') AS has_{$table}"; }
    $flags[] = "(DIGEST_TEXT LIKE '%{$options}%') AS has_options";
    $rows = observation_rows($db, 'SELECT DIGEST AS fingerprint, SUBSTRING_INDEX(DIGEST_TEXT, \' \', 1) AS statementType, '
        . implode(', ', $flags) . ", COUNT_STAR AS calls, SUM_TIMER_WAIT AS timerPs, SUM_LOCK_TIME AS lockPs,
        SUM_ROWS_EXAMINED AS rowsExamined, SUM_ROWS_SENT AS rowsSent, SUM_ROWS_AFFECTED AS rowsAffected,
        SUM_ERRORS AS errors FROM performance_schema.events_statements_summary_by_digest
        WHERE SCHEMA_NAME=DATABASE() AND DIGEST IS NOT NULL AND (DIGEST_TEXT LIKE '%focus_tracker_%' OR DIGEST_TEXT LIKE '%{$options}%')
        ORDER BY SUM_TIMER_WAIT DESC LIMIT 1000");
    return observation_digest_rows($rows, count($rows) === 1000);
}
function observation_digest_rows(array $rows, bool $truncated = false): array {
    $tables = array('participations', 'events', 'activities', 'activity_links', 'checks', 'check_states', 'scores', 'daily_statistics');
    $out = array();
    foreach ($rows as $row) {
        $families = array();
        foreach (array_merge($tables, array('options')) as $table) { if (! empty($row['has_' . $table])) { $families[] = $table; } unset($row['has_' . $table]); }
        $row['statementType'] = in_array($row['statementType'], array('SELECT', 'INSERT', 'UPDATE', 'DELETE', 'SHOW', 'ALTER', 'CREATE'), true) ? $row['statementType'] : 'OTHER';
        $row['tables'] = $families;
        foreach (array('calls', 'rowsExamined', 'rowsSent', 'rowsAffected', 'errors') as $field) { $row[$field] = (int) $row[$field]; }
        foreach (array('timerPs', 'lockPs') as $field) { $row[$field] = (float) $row[$field]; }
        $fingerprint = $row['fingerprint'];
        if (isset($out[$fingerprint])) {
            // Native PFS can expose several slots with the same fingerprint.
            // A later zero-count slot must not erase an earlier nonzero one.
            foreach (array('calls', 'rowsExamined', 'rowsSent', 'rowsAffected', 'errors', 'timerPs', 'lockPs') as $field) { $row[$field] += $out[$fingerprint][$field]; }
            $row['tables'] = array_values(array_unique(array_merge($out[$fingerprint]['tables'], $row['tables'])));
            if ($row['statementType'] !== $out[$fingerprint]['statementType']) { $row['statementType'] = 'OTHER'; }
        }
        sort($row['tables'], SORT_STRING);
        $out[$fingerprint] = $row;
    }
    ksort($out, SORT_STRING);
    return array('statements' => $out, 'truncated' => $truncated, 'fingerprintAggregation' => 'sum_same_fingerprint_v1',
        'sourceRows' => count($rows), 'duplicateFingerprintRows' => count($rows) - count($out));
}
function observation_snapshot(mysqli $db, array $environment): array {
    $started = microtime(true);
    $options = $environment['tablePrefix'] . 'options';
    $consumers = observation_rows($db, "SELECT NAME,ENABLED FROM performance_schema.setup_consumers WHERE NAME IN ('global_instrumentation','thread_instrumentation','statements_digest','events_statements_current','events_statements_history','events_statements_history_long') ORDER BY NAME");
    $instrumentation = observation_rows($db, "SELECT COUNT(*) AS statementInstrumentCount, SUM(ENABLED='YES') AS enabledStatementInstruments FROM performance_schema.setup_instruments WHERE NAME LIKE 'statement/%'")[0];
    $digestCounts = observation_rows($db, 'SELECT COUNT(*) AS allDigestCount, COALESCE(SUM(SCHEMA_NAME=DATABASE()),0) AS databaseDigestCount FROM performance_schema.events_statements_summary_by_digest')[0];
    return array('schemaVersion' => 1, 'capturedAtMs' => (int) floor($started * 1000), 'environment' => $environment,
        'status' => observation_status($db), 'locks' => observation_locks($db, $options), 'digests' => observation_digests($db, $options),
        'coverage' => array('consumers' => $consumers, 'instrumentation' => $instrumentation, 'digestCounts' => $digestCounts),
        'scopeNote' => 'Status counters are instance-wide and include this observer; statement digests and lock rows are limited to the disposable database. Statement timers include elapsed lock waits and must not be treated as CPU time. Zero samples do not establish instrumentation coverage.',
        'observerElapsedMs' => round((microtime(true) - $started) * 1000, 3));
}
function observation_diff(array $before, array $after): array {
    if (($before['schemaVersion'] ?? 0) !== 1 || ($after['schemaVersion'] ?? 0) !== 1 || ($before['environment'] ?? null) !== ($after['environment'] ?? null) || ($after['capturedAtMs'] ?? 0) < ($before['capturedAtMs'] ?? 0)) { throw new RuntimeException('incompatible_snapshots'); }
    $status = array();
    foreach ($after['status'] as $key => $value) {
        if (! in_array($key, array('Threads_connected', 'Threads_running', 'Innodb_row_lock_time_max'), true)) { $status[$key] = $value >= ($before['status'][$key] ?? 0) ? $value - ($before['status'][$key] ?? 0) : null; }
    }
    $statements = array();
    foreach ($after['digests']['statements'] as $key => $row) {
        $prior = $before['digests']['statements'][$key] ?? array();
        $delta = array('fingerprint' => $key, 'statementType' => $row['statementType'], 'tables' => $row['tables'], 'counterReset' => false);
        foreach (array('calls', 'timerPs', 'lockPs', 'rowsExamined', 'rowsSent', 'rowsAffected', 'errors') as $field) {
            $delta[$field] = $row[$field] >= ($prior[$field] ?? 0) ? $row[$field] - ($prior[$field] ?? 0) : null;
            if ($delta[$field] === null) { $delta['counterReset'] = true; }
        }
        if ($delta['calls'] === 0 && ! $delta['counterReset']) { continue; }
        $delta['elapsedMs'] = $delta['timerPs'] === null ? null : round($delta['timerPs'] / 1e9, 3);
        $delta['lockMs'] = $delta['lockPs'] === null ? null : round($delta['lockPs'] / 1e9, 3);
        unset($delta['timerPs'], $delta['lockPs']);
        $statements[] = $delta;
    }
    usort($statements, static fn (array $a, array $b): int => ($b['elapsedMs'] ?? -1) <=> ($a['elapsedMs'] ?? -1));
    $aggregationReliable = ($before['digests']['fingerprintAggregation'] ?? null) === 'sum_same_fingerprint_v1'
        && ($after['digests']['fingerprintAggregation'] ?? null) === 'sum_same_fingerprint_v1';
    return array('schemaVersion' => 1, 'durationMs' => $after['capturedAtMs'] - $before['capturedAtMs'], 'environment' => $after['environment'], 'statusDelta' => $status,
        'statements' => $statements, 'digestAggregationReliable' => $aggregationReliable,
        'digestCoverageIncomplete' => ! $aggregationReliable || ! empty($before['digests']['truncated']) || ! empty($after['digests']['truncated']),
        'scopeNote' => $after['scopeNote']);
}

if (defined('FOCUS_MYSQL_OBSERVATION_LIBRARY_ONLY') && FOCUS_MYSQL_OBSERVATION_LIBRARY_ONLY === true) { return; }

$stage = 'configuration';
try {
    $root = dirname(__DIR__, 3);
    $run = $root . '/_notes/tmp/focus-tracker-mysql8';
    $config = json_decode((string) file_get_contents($run . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($config['dbHost'] ?? '') !== '127.0.0.1:33917' || ($config['dbName'] ?? '') !== 'focus_acceptance_mysql8_20261028'
        || ($config['home'] ?? '') !== 'http://127.0.0.1:33918' || ($config['baseUrl'] ?? '') !== $config['home']
        || observation_path((string) realpath((string) ($config['wpPath'] ?? ''))) !== observation_path((string) realpath($run . '/wordpress'))) { throw new RuntimeException('disposable_instance_guard'); }
    $out = $run . '/artifacts/sql-observation';
    if (! is_dir($out) && ! mkdir($out, 0700, true) && ! is_dir($out)) { throw new RuntimeException('artifact_directory'); }
    $mode = $argv[1] ?? '';
    if (! in_array($mode, array('snapshot', 'diff', 'watch', 'deadlock'), true)) { throw new RuntimeException('expected_snapshot_diff_watch_or_deadlock'); }
    $name = observation_name($argv[2] ?? '');
    if ($mode === 'diff') {
        $afterName = observation_name($argv[3] ?? '');
        $before = json_decode((string) file_get_contents($out . '/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode((string) file_get_contents($out . '/' . $afterName . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $delta = observation_diff($before, $after);
        observation_save($out . '/' . $name . '--' . $afterName . '.json', $delta);
        echo json_encode(array('mode' => 'diff', 'durationMs' => $delta['durationMs'], 'statements' => count($delta['statements']), 'digestCoverageIncomplete' => $delta['digestCoverageIncomplete']), JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    $stage = 'connection';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli('127.0.0.1', $config['dbUser'], $config['dbPassword'], $config['dbName'], 33917);
    $stage = 'oracle_guard';
    $metadata = observation_rows($db, 'SELECT VERSION() AS version, @@version_comment AS vendor, @@transaction_isolation AS isolationLevel, @@performance_schema AS performanceSchema, @@datadir AS dataDirectory')[0];
    $datadir = observation_path($metadata['dataDirectory']);
    if (! str_starts_with($metadata['version'], '8.0.') || $metadata['vendor'] !== 'MySQL Community Server - GPL' || (int) $metadata['performanceSchema'] !== 1
        || ! in_array($datadir, array('q:/db-data', observation_path($run . '/db-data')), true)) { throw new RuntimeException('owned_oracle_guard'); }
    unset($metadata['dataDirectory']);
    $optionTables = observation_rows($db, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '%!_options' ESCAPE '!'");
    if (count($optionTables) !== 1 || preg_match('/^[A-Za-z0-9_]+_options$/D', $optionTables[0]['TABLE_NAME']) !== 1) { throw new RuntimeException('wordpress_options_guard'); }
    $metadata['tablePrefix'] = substr($optionTables[0]['TABLE_NAME'], 0, -strlen('options'));
    $stage = 'observation';
    if ($mode === 'deadlock') {
        $cycle = observation_deadlock($db, $config['dbName'], $metadata['tablePrefix']);
        observation_save($out . '/' . $name . '-deadlock.json', $cycle);
        echo json_encode(array('mode' => 'deadlock', 'name' => $name, 'available' => $cycle['available'], 'locks' => count($cycle['locks'])), JSON_THROW_ON_ERROR) . "\n";
        $db->close();
        exit(0);
    }
    $before = observation_snapshot($db, $metadata);
    if ($mode === 'snapshot') {
        observation_save($out . '/' . $name . '.json', $before);
        echo json_encode(array('mode' => 'snapshot', 'name' => $name, 'statements' => count($before['digests']['statements']), 'waitingPairs' => count($before['locks']['waits']), 'observerElapsedMs' => $before['observerElapsedMs']), JSON_THROW_ON_ERROR) . "\n";
    } else {
        $seconds = filter_var($argv[3] ?? 60, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 180)));
        $interval = filter_var($argv[4] ?? 500, FILTER_VALIDATE_INT, array('options' => array('min_range' => 250, 'max_range' => 5000)));
        if ($seconds === false || $interval === false) { throw new RuntimeException('invalid_watch_bounds'); }
        observation_save($out . '/' . $name . '-before.json', $before);
        $stream = fopen($out . '/' . $name . '-waits.jsonl', 'wb');
        if ($stream === false) { throw new RuntimeException('artifact_stream'); }
        $deadline = microtime(true) + $seconds; $samples = 0; $peak = 0;
        try {
            do {
                $started = microtime(true); $locks = observation_locks($db, $metadata['tablePrefix'] . 'options'); ++$samples; $peak = max($peak, count($locks['waits']));
                $line = json_encode(array('capturedAtMs' => (int) floor($started * 1000), 'locks' => $locks), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (fwrite($stream, $line) !== strlen($line)) { throw new RuntimeException('artifact_stream_write'); }
                $remaining = min($deadline - microtime(true), $interval / 1000 - (microtime(true) - $started));
                if ($remaining > 0) { usleep((int) ($remaining * 1000000)); }
            } while (microtime(true) < $deadline);
        } finally { fclose($stream); }
        $after = observation_snapshot($db, $metadata);
        observation_save($out . '/' . $name . '-after.json', $after);
        observation_save($out . '/' . $name . '-delta.json', observation_diff($before, $after));
        echo json_encode(array('mode' => 'watch', 'name' => $name, 'samples' => $samples, 'peakWaitingPairs' => $peak), JSON_THROW_ON_ERROR) . "\n";
    }
    $db->close();
} catch (Throwable $error) {
    // Driver errors can contain connection metadata or SQL. Emit only a stage,
    // exception class and numeric code; keep credentials in the local config.
    fwrite(STDERR, 'Native Oracle observation failed: stage=' . $stage . ' class=' . get_class($error) . ' code=' . (int) $error->getCode() . "\n");
    exit(1);
}
