<?php
declare(strict_types=1);

/** Native final-package preservation. No source rows, credentials or option values are exported. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$finished = false;
register_shutdown_function(static function () use (&$finished): void { if (! $finished) { fwrite(STDERR, "Native package verification did not complete.\n"); exit(1); } });
$checks = array();
function category_package_check(bool $condition, string $label): void {
    global $checks;
    $checks[$label] = $condition;
    if (! $condition) { throw new RuntimeException($label); }
}
function category_package_path(string $path): string { return strtolower(str_replace('\\', '/', rtrim($path, '/\\'))); }
function category_package_json(string $path): array { return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
function category_package_save(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) { throw new RuntimeException('Could not save the private verification artifact.'); }
}
function category_package_excluded_option(string $name): bool {
    return in_array($name, array('cron', 'doing_cron', 'auto_updater.lock', 'core_updater.lock',
        'focus_tracker_schema_health', 'focus_tracker_schema_retry', 'focus_tracker_schema_maintenance_lock',
        'focus_tracker_cleanup_status', 'focus_tracker_cleanup_progress', 'focus_tracker_cleanup_lock'), true)
        || str_starts_with($name, '_transient_') || str_starts_with($name, '_site_transient_');
}
function category_package_snapshot(mysqli $db, string $prefix, array $fixture): array {
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try {
        $tables = array(); $engines = array(); $excluded = array();
        foreach ($db->query('SHOW FULL TABLES')->fetch_all(MYSQLI_NUM) as $table) {
            if ($table[1] !== 'BASE TABLE') { continue; }
            $name = $table[0];
            category_package_check(preg_match('/^[A-Za-z0-9_]+$/D', $name) === 1, 'Every table in the dedicated database has a safe SQL identifier');
            $schema = $db->query('SHOW COLUMNS FROM `' . $name . '`')->fetch_all(MYSQLI_ASSOC);
            $rowHashes = array();
            $rows = $db->query('SELECT * FROM `' . $name . '`', MYSQLI_USE_RESULT);
            while ($row = $rows->fetch_assoc()) {
                if ($name === $prefix . 'options' && category_package_excluded_option($row['option_name'])) {
                    $kind = str_starts_with($row['option_name'], '_site_transient_') ? '_site_transient_*' : (str_starts_with($row['option_name'], '_transient_') ? '_transient_*' : $row['option_name']);
                    $excluded[$kind] = ($excluded[$kind] ?? 0) + 1;
                    continue;
                }
                // Preserve nulls and every SQL byte while allowing identical
                // duplicate rows; neither plaintext nor per-row hashes leave memory.
                ksort($row, SORT_STRING);
                $rowHashes[] = hash('sha256', serialize($row));
            }
            $rows->free(); sort($rowHashes, SORT_STRING);
            $tables[$name] = array('rows' => count($rowHashes), 'sha256' => hash('sha256', implode("\n", $rowHashes)), 'columnsSha256' => hash('sha256', serialize($schema)));
            $status = $db->query("SHOW TABLE STATUS WHERE Name = '" . $db->real_escape_string($name) . "'")->fetch_assoc();
            $engines[$name] = $status['Engine'] ?? null;
        }
        ksort($tables, SORT_STRING); ksort($engines, SORT_STRING); ksort($excluded, SORT_STRING);
        $legacy = array();
        $names = array('activities' => 'activities', 'participations' => 'participations', 'events' => 'events', 'scores' => 'scores', 'links' => 'activity_links');
        foreach ($names as $kind => $suffix) {
            $fields = $fixture['baseline']['columns'][$kind];
            foreach ($fields as $field) { category_package_check(preg_match('/^[a-z_]+$/D', $field) === 1, 'The original upgrade baseline contains safe column names'); }
            $where = match ($kind) { 'activities' => 'id=' . (int) $fixture['legacy']['activityId'], 'participations' => 'id=' . (int) $fixture['legacy']['participationId'], 'scores' => 'activity_id=' . (int) $fixture['legacy']['activityId'], default => 'participation_id=' . (int) $fixture['legacy']['participationId'] };
            $rows = $db->query('SELECT `' . implode('`,`', $fields) . '` FROM `' . $prefix . 'focus_tracker_' . $suffix . '` WHERE ' . $where . ' ORDER BY ' . ($kind === 'links' ? 'participation_id,activity_id' : 'id'))->fetch_all(MYSQLI_ASSOC);
            $legacy[$kind] = hash('sha256', (string) json_encode($rows, JSON_THROW_ON_ERROR));
        }
        $settingsRows = $db->query("SELECT option_name,option_value FROM `" . $prefix . "options` WHERE option_name IN ('focus_tracker_settings','focus_tracker_student_participation_defaults','focus_tracker_statistics_coverage_since','" . $prefix . "user_roles') ORDER BY option_name")->fetch_all(MYSQLI_ASSOC);
        $settings = array(); foreach ($settingsRows as $row) { $settings[$row['option_name']] = hash('sha256', $row['option_value']); }
        $db->commit();
        return array('tables' => $tables, 'engines' => $engines, 'settingsSha256' => $settings, 'legacyCommonColumnSha256' => $legacy, 'excludedRuntimeOptionCounts' => $excluded);
    } catch (Throwable $error) { $db->rollback(); throw $error; }
}
function category_package_files(string $root): array {
    $hashes = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
        category_package_check(! $entry->isLink(), 'The installed package contains no symbolic link');
        if (! $entry->isFile()) { continue; }
        $key = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        $hashes[$key] = hash_file('sha256', $entry->getPathname());
    }
    ksort($hashes, SORT_STRING); return $hashes;
}
function category_package_aggregate(array $hashes): string {
    ksort($hashes, SORT_STRING); $state = hash_init('sha256');
    foreach ($hashes as $name => $hash) { hash_update($state, $name . "\0" . $hash . "\n"); }
    return hash_final($state);
}

try {
    $phase = $argv[1] ?? '';
    category_package_check(in_array($phase, array('inspect', 'prezip', 'postzip'), true), 'The requested package verification phase is valid');
    $config = category_package_json($argv[2] ?? '');
    $workspace = realpath(dirname(__DIR__, 3));
    $runRoot = realpath($workspace . '/_notes/tmp/focus-tracker-real-wp');
    $wpPath = realpath((string) ($config['wpPath'] ?? ''));
    $output = realpath((string) ($config['outputDir'] ?? ''));
    category_package_check($runRoot !== false && $wpPath !== false && $output !== false
        && category_package_path($wpPath) === category_package_path($runRoot . '/wordpress')
        && str_starts_with(category_package_path($output) . '/', category_package_path($runRoot) . '/'), 'Only the dedicated category fixture paths may be used');
    category_package_check(($config['baseUrl'] ?? '') === 'http://127.0.0.1:33918' && ($config['dbHost'] ?? '') === '127.0.0.1:33917'
        && ($config['dbName'] ?? '') === 'focus_acceptance_categories_20261028', 'Only the dedicated loopback category database may be inspected');
    $configuration = (string) file_get_contents($wpPath . '/wp-config.php');
    preg_match('~\$table_prefix\s*=\s*[\x27\x22]([a-zA-Z0-9_]+)[\x27\x22]~', $configuration, $match); unset($configuration);
    $prefix = $match[1] ?? ''; category_package_check($prefix !== '', 'The native WordPress table prefix is known');
    $fixture = category_package_json($output . '/page-categories-fixture.json');
    $db = new mysqli('127.0.0.1', (string) $config['dbUser'], (string) $config['dbPassword'], (string) $config['dbName'], 33917); $db->set_charset('utf8mb4');
    $databaseVersion = $db->query('SELECT VERSION() AS v')->fetch_assoc()['v'];
    $snapshot = category_package_snapshot($db, $prefix, $fixture);
    category_package_check($snapshot['legacyCommonColumnSha256'] === $fixture['baseline']['hashes'], 'All original 1.0.1 common-column hashes still match the upgrade baseline');
    $focusTables = array_filter(array_keys($snapshot['tables']), static fn (string $name): bool => str_starts_with($name, $prefix . 'focus_tracker_'));
    category_package_check(count($focusTables) === 8 && count(array_filter($focusTables, static fn (string $name): bool => ($snapshot['engines'][$name] ?? '') === 'InnoDB')) === 8, 'All eight actual Focus Tracker tables use InnoDB');
    $beforePath = $output . '/page-categories-prezip.json';
    if ($phase === 'prezip') {
        category_package_check(! is_file($beforePath), 'A final package baseline cannot replace an earlier snapshot');
        category_package_save($beforePath, array('passed' => true, 'assertionCount' => count($checks), 'checks' => $checks, 'capturedAt' => gmdate('c'), 'database' => $config['dbName'], 'databaseVersion' => $databaseVersion, 'snapshot' => $snapshot,
            'scope' => 'All native database base-table rows and columns; only the listed runtime options are excluded. No source rows, settings, credentials or individual row hashes are exported.'));
    } elseif ($phase === 'postzip') {
        $before = category_package_json($beforePath);
        category_package_check($snapshot['tables'] === $before['snapshot']['tables'], 'Every current database data row and table column survives the final ZIP replacement unchanged');
        category_package_check($snapshot['settingsSha256'] === $before['snapshot']['settingsSha256'], 'Stored settings, default-role ownership, coverage and all WordPress role permissions survive unchanged');
        category_package_check($snapshot['legacyCommonColumnSha256'] === $before['snapshot']['legacyCommonColumnSha256'], 'The original upgrade baseline also survives final ZIP replacement');
        $zipPath = realpath($argv[3] ?? '');
        $releaseRoot = realpath($workspace . '/_releases/focus-tracker/1.1.0');
        category_package_check($zipPath !== false && $releaseRoot !== false && str_starts_with(category_package_path($zipPath), category_package_path($releaseRoot) . '/'), 'The compared ZIP is the immutable 1.1.0 release artifact');
        $zip = new ZipArchive(); category_package_check($zip->open($zipPath) === true, 'The final release ZIP opens');
        $expected = array();
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            $relative = is_string($name) ? substr($name, strlen('focus-tracker/')) : '';
            category_package_check(is_string($name) && ! str_contains($name, '\\') && ! str_contains($name, '//')
                && ($name === 'focus-tracker/' || preg_match('~^focus-tracker/[A-Za-z0-9_.\-/]+$~D', $name) === 1)
                && ! str_contains('/' . $relative . '/', '/../') && ! str_contains('/' . $relative . '/', '/./'), 'Every final ZIP entry has a safe package-relative path');
            if (str_ends_with($name, '/')) { continue; }
            $key = substr($name, strlen('focus-tracker/'));
            category_package_check(! array_key_exists($key, $expected), 'The final ZIP has no duplicate file path');
            $bytes = $zip->getFromIndex($i); category_package_check(is_string($bytes), 'Every final ZIP entry can be read');
            $expected[$key] = hash('sha256', $bytes);
        }
        $zip->close(); ksort($expected, SORT_STRING);
        $installed = category_package_files($wpPath . '/wp-content/plugins/focus-tracker');
        category_package_check($expected === $installed && $expected !== array(), 'Every installed byte matches the final ZIP and there are no missing or extra installed files');
        $_SERVER['HTTP_HOST'] = '127.0.0.1:33918'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['SERVER_PORT'] = '33918';
        $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        require $wpPath . '/wp-load.php';
        category_package_check(wp_get_environment_type() === 'local' && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, 'The real WordPress boot stays local and does not spawn cleanup cron during preservation verification');
        category_package_check(defined('FOCUS_TRACKER_VERSION') && FOCUS_TRACKER_VERSION === '1.1.0' && get_option('focus_tracker_db_version') === '1.1.0', 'The final installed plugin and durable schema version are 1.1.0');
        $health = \FocusTracker\Database\Installer::inspect_schema();
        category_package_check(($health['ready'] ?? false) === true && ($health['issues'] ?? null) === array(), 'Native schema health verifies columns, InnoDB engines and critical unique indexes');
        $key = \CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress('focus_tracker_participate');
        category_package_check((get_role('subscriber')->capabilities[$key] ?? null) === true, 'The native subscriber participation default survives the final ZIP');
        foreach ($fixture['students'] as $index => $student) {
            $user = get_userdata((int) $student['userId']);
            category_package_check($user instanceof WP_User && user_can($user, $key) && ! array_key_exists($key, $user->caps), 'Native browser learner ' . ($index + 1) . ' still inherits participation without a per-user grant');
        }
        $afterBoot = category_package_snapshot($db, $prefix, $fixture);
        category_package_check($afterBoot['tables'] === $snapshot['tables'] && $afterBoot['settingsSha256'] === $snapshot['settingsSha256'], 'A fresh native WordPress boot preserves all current data and nonruntime settings');
        $report = array('phase' => $phase, 'passed' => true, 'assertionCount' => count($checks), 'checks' => $checks, 'capturedAt' => gmdate('c'),
            'environment' => array('wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'database' => $databaseVersion, 'databaseName' => $config['dbName'], 'platform' => OJ_PLATFORM_VERSION, 'focusTracker' => FOCUS_TRACKER_VERSION),
            'tableCount' => count($snapshot['tables']), 'snapshot' => $afterBoot, 'schemaHealth' => array('ready' => $health['ready'], 'issues' => $health['issues']),
            'releaseZip' => array('name' => basename($zipPath), 'sha256' => hash_file('sha256', $zipPath), 'files' => count($expected), 'aggregateSha256' => category_package_aggregate($installed),
                'aggregateAlgorithm' => 'SHA256(sorted(relativePath + NUL + fileSha256 + LF))'),
            'scope' => 'Native MySQL preservation plus actual WordPress boot, schema health, inherited capabilities and final ZIP byte equality. HTTP cookies/timers are tested separately.');
        category_package_save($output . '/page-categories-postzip-report.json', $report);
    }
    $db->close(); $finished = true;
    echo json_encode(array('phase' => $phase, 'passed' => true, 'assertions' => count($checks), 'databaseVersion' => $databaseVersion, 'tableCount' => count($snapshot['tables'])), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
    fwrite(STDERR, 'Native package verification failed: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    if ($failed !== array()) { fwrite(STDERR, 'Assertion: ' . end($failed) . "\n"); }
    exit(1);
}
