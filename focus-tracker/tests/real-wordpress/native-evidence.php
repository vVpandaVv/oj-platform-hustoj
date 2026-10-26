<?php
declare(strict_types=1);

/** Read-only native-DB evidence; never boots WordPress or reads student source. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

function focus_evidence_require(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
function focus_evidence_path(string $path): string { return strtolower(str_replace('\\', '/', rtrim($path, '/\\'))); }
function focus_evidence_json(string $path): array {
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
function focus_evidence_version(string $file): string {
    preg_match('/^\s*\*\s*Version:\s*([^\r\n]+)/mi', (string) file_get_contents($file), $match);
    return trim($match[1] ?? '');
}
function focus_evidence_files(string $root): array {
    $files = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        focus_evidence_require(! $entry->isLink(), 'Installed plugin contains a symbolic link.');
        if (! $entry->isFile()) { continue; }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        $files[$relative] = hash_file('sha256', $entry->getPathname());
    }
    ksort($files, SORT_STRING);
    return $files;
}
function focus_evidence_aggregate(array $files): string {
    ksort($files, SORT_STRING);
    $hash = hash_init('sha256');
    foreach ($files as $path => $sha) { hash_update($hash, $path . "\0" . $sha . "\n"); }
    return hash_final($hash);
}
function focus_evidence_ms(string $time): int { return (new DateTimeImmutable($time, new DateTimeZone('UTC')))->getTimestamp() * 1000; }

try {
    $configPath = $argv[1] ?? dirname(__DIR__, 3) . '/_notes/tmp/focus-tracker-real-wp/config.json';
    $config = focus_evidence_json($configPath);
    $runRoot = realpath(dirname(__DIR__, 3) . '/_notes/tmp/focus-tracker-real-wp');
    $wpPath = realpath((string) ($config['wpPath'] ?? ''));
    focus_evidence_require($runRoot !== false && $wpPath !== false && focus_evidence_path($wpPath) === focus_evidence_path($runRoot . '/wordpress'), 'Refusing a non-fixture WordPress root.');
    focus_evidence_require(($config['dbHost'] ?? '') === '127.0.0.1:33917' && preg_match('/^focus_acceptance_[a-z0-9_]+$/D', (string) ($config['dbName'] ?? '')) === 1, 'Refusing a non-fixture database.');
    $outputDir = realpath((string) ($config['outputDir'] ?? ''));
    focus_evidence_require($outputDir !== false && str_starts_with(focus_evidence_path($outputDir) . '/', focus_evidence_path($runRoot) . '/'), 'Artifact path must stay inside the fixture workspace.');
    $configuration = (string) file_get_contents($wpPath . '/wp-config.php');
    preg_match('~\$table_prefix\s*=\s*[\x27\x22]([a-zA-Z0-9_]+)[\x27\x22]~', $configuration, $prefixMatch);
    $prefix = $prefixMatch[1] ?? '';
    unset($configuration);
    focus_evidence_require($prefix !== '', 'Could not identify the native WordPress table prefix.');

    // A consistent read-only snapshot prevents this observer from changing the
    // activity, capability, topic bootstrap or cleanup state while inspecting.
    $db = new mysqli('127.0.0.1', (string) $config['dbUser'], (string) $config['dbPassword'], (string) $config['dbName'], 33917);
    $db->set_charset('utf8mb4');
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $databaseVersion = (string) $db->query('SELECT VERSION() AS v')->fetch_assoc()['v'];
    $tableNames = array('activities', 'participations', 'activity_links', 'events', 'scores', 'checks', 'check_states', 'daily_statistics');
    $engines = array(); $counts = array();
    $statement = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($tableNames as $name) {
        $table = $prefix . 'focus_tracker_' . $name;
        $statement->bind_param('s', $table);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $engines[$name] = $row['ENGINE'] ?? null;
        $counts[$name] = $row === null ? null : (int) $db->query('SELECT COUNT(*) AS n FROM `' . $table . '`')->fetch_assoc()['n'];
    }
    $statement->close();
    $p = '`' . $prefix . 'focus_tracker_participations`';
    $l = '`' . $prefix . 'focus_tracker_activity_links`';
    $e = '`' . $prefix . 'focus_tracker_events`';
    $a = '`' . $prefix . 'focus_tracker_activities`';
    $q = '`' . $prefix . 'focus_tracker_checks`';
    $checkStatusCounts = array();
    foreach ($db->query("SELECT status, COUNT(*) AS n FROM {$q} GROUP BY status")->fetch_all(MYSQLI_ASSOC) as $row) { $checkStatusCounts[$row['status']] = (int) $row['n']; }
    $checkBounds = $db->query("SELECT COUNT(*) AS total, SUM(window_start_ms IS NULL OR window_end_ms IS NULL) AS missing,
        SUM(issued_at_ms < window_start_ms OR issued_at_ms >= window_end_ms) AS outside_window,
        SUM(expires_at_ms > window_end_ms) AS late_expiry FROM {$q}")->fetch_assoc();
    foreach ($checkBounds as &$value) { $value = (int) $value; } unset($value);

    $browserPath = $outputDir . '/browser-result.json';
    $browser = is_file($browserPath) ? focus_evidence_json($browserPath) : array();
    $verifyPath = $outputDir . '/participation-verify-report.json';
    $verify = is_file($verifyPath) ? focus_evidence_json($verifyPath) : array();
    $activityId = (int) ($browser['activity']['id'] ?? 0);
    $activityEvidence = null;
    if ($activityId > 0) {
        $activity = $db->query("SELECT id, starts_at, ends_at, status FROM {$a} WHERE id = {$activityId}")->fetch_assoc();
        focus_evidence_require(is_array($activity), 'The reported browser activity does not exist.');
        $start = focus_evidence_ms($activity['starts_at']);
        $end = focus_evidence_ms($activity['ends_at']);
        $rows = $db->query("SELECT p.user_id, COUNT(e.id) AS event_count, MIN(e.occurred_at_ms) AS first_at_ms, MAX(e.occurred_at_ms) AS last_at_ms,
            SUM(CASE WHEN e.occurred_at_ms < {$start} THEN 1 ELSE 0 END) AS before_start,
            SUM(CASE WHEN e.event_type <> 'session_end' AND e.occurred_at_ms >= {$end} THEN 1 ELSE 0 END) AS learning_at_or_after_end,
            SUM(CASE WHEN e.event_type = 'session_end' AND e.occurred_at_ms > {$end} THEN 1 ELSE 0 END) AS control_after_end
            FROM {$p} p JOIN {$l} l ON l.participation_id = p.id AND l.activity_id = {$activityId}
            JOIN {$e} e ON e.participation_id = p.id GROUP BY p.user_id ORDER BY p.user_id")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$row) { foreach ($row as &$value) { $value = (int) $value; } unset($value); } unset($row);
        $activityEvidence = array('id' => $activityId, 'startsAtMs' => $start, 'endsAtMs' => $end, 'status' => $activity['status'], 'learners' => $rows,
            'endRule' => 'Learning events use [start,end); session_end control may equal end.');
    }
    $db->commit();
    $db->close();

    $focusRoot = $wpPath . '/wp-content/plugins/focus-tracker';
    $installed = focus_evidence_files($focusRoot);
    $comparison = null;
    $zipPath = isset($argv[2]) ? realpath($argv[2]) : false;
    if ($zipPath !== false) {
        $zip = new ZipArchive();
        focus_evidence_require($zip->open($zipPath) === true, 'Could not open the release ZIP.');
        $expected = array();
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = str_replace('\\', '/', $zip->getNameIndex($i));
            if (str_ends_with($name, '/')) { continue; }
            focus_evidence_require(str_starts_with($name, 'focus-tracker/') && ! str_contains($name, '../'), 'Unexpected release ZIP path.');
            $relative = substr($name, strlen('focus-tracker/'));
            focus_evidence_require(! isset($expected[$relative]), 'Duplicate release ZIP member.');
            $expected[$relative] = hash('sha256', $zip->getFromIndex($i));
        }
        $zip->close();
        ksort($expected, SORT_STRING);
        $different = array();
        foreach (array_intersect(array_keys($expected), array_keys($installed)) as $path) { if ($expected[$path] !== $installed[$path]) { $different[] = $path; } }
        $comparison = array('package' => basename($zipPath), 'packageSha256' => hash_file('sha256', $zipPath), 'expectedFileCount' => count($expected),
            'expectedAggregateSha256' => focus_evidence_aggregate($expected), 'matches' => $expected === $installed,
            'missing' => array_values(array_diff(array_keys($expected), array_keys($installed))),
            'extra' => array_values(array_diff(array_keys($installed), array_keys($expected))), 'different' => $different);
    }
    preg_match('~\$wp_version\s*=\s*[\x27\x22]([^\x27\x22]+)~', (string) file_get_contents($wpPath . '/wp-includes/version.php'), $version);
    $learners = $activityEvidence['learners'] ?? array();
    $expectedLearners = array_values(array_unique(array_map('intval', array_column($browser['students'] ?? array(), 'userId'))));
    $observedLearners = array_map('intval', array_column(array_filter($learners, static fn (array $row): bool => $row['event_count'] > 0), 'user_id'));
    $checks = array('allEightTablesInnoDB' => count(array_filter($engines, static fn ($engine): bool => $engine === 'InnoDB')) === 8,
        'nativePhpPassed' => ($verify['passed'] ?? false) === true, 'nativeBrowserPassed' => ($browser['passed'] ?? false) === true,
        'bothBrowserLearnersPersisted' => count($expectedLearners) === 2 && array_diff($expectedLearners, $observedLearners) === array(),
        'noBrowserLearningOutsideWindow' => count($learners) >= 2 && array_sum(array_column($learners, 'before_start')) + array_sum(array_column($learners, 'learning_at_or_after_end')) + array_sum(array_column($learners, 'control_after_end')) === 0,
        'allQuestionTimesInsideWindows' => $checkBounds['total'] > 0 && $checkBounds['missing'] === 0 && $checkBounds['outside_window'] === 0 && $checkBounds['late_expiry'] === 0,
        'releaseZipMatchesInstalledFiles' => ($comparison['matches'] ?? false) === true);
    $report = array('capturedAt' => gmdate('c'), 'passed' => ! in_array(false, $checks, true), 'checks' => $checks,
        'scope' => 'Read-only MySQL consistent snapshot and installed-file hashes; no WordPress bootstrap, profile/source/answer read, or capability mutation.',
        'environment' => array('wordpress' => $version[1] ?? '', 'php' => PHP_VERSION, 'database' => $databaseVersion, 'databaseName' => $config['dbName'],
            'platform' => focus_evidence_version($wpPath . '/wp-content/plugins/oj-platform/oj-platform.php'), 'focusTracker' => focus_evidence_version($focusRoot . '/focus-tracker.php')),
        'engines' => $engines, 'counts' => $counts, 'checkStatusCounts' => $checkStatusCounts, 'checkTimeBounds' => $checkBounds,
        'browserRun' => array('startedAt' => $browser['startedAt'] ?? null, 'finishedAt' => $browser['finishedAt'] ?? null),
        'browserActivity' => $activityEvidence, 'installedFiles' => array('count' => count($installed), 'aggregateSha256' => focus_evidence_aggregate($installed),
            'aggregateAlgorithm' => 'SHA256(sorted(relativePath + NUL + fileSha256 + LF))', 'sha256' => $installed), 'releaseZip' => $comparison);
    $path = $outputDir . '/native-evidence.json';
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    focus_evidence_require(file_put_contents($path, $json, LOCK_EX) === strlen($json), 'Could not save native evidence.');
    echo json_encode(array('passed' => $report['passed'], 'artifact' => $path, 'checks' => $checks), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($report['passed'] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Native evidence failed: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    exit(1);
}
