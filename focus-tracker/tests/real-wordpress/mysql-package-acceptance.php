<?php
declare(strict_types=1);

/** Oracle MySQL8 final 1.1.1 ZIP preservation; no source rows or credentials are exported. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$finished = false;
register_shutdown_function(static function () use (&$finished): void { if (! $finished) { fwrite(STDERR, "Native package verification did not complete.\n"); exit(1); } });
$checks = array();
function mysql_package_check(bool $condition, string $label): void {
    global $checks;
    $checks[$label] = $condition;
    if (! $condition) { throw new RuntimeException($label); }
}
function mysql_package_path(string $path): string { return strtolower(str_replace('\\', '/', rtrim($path, '/\\'))); }
function mysql_package_json(string $path): array { return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
function mysql_package_save(string $path, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) { throw new RuntimeException('Could not save the private verification artifact.'); }
}
function mysql_package_excluded_option(string $name): bool {
    return in_array($name, array('cron', 'doing_cron', 'auto_updater.lock', 'core_updater.lock',
        'focus_tracker_schema_health', 'focus_tracker_schema_retry', 'focus_tracker_schema_maintenance_lock',
        'focus_tracker_cleanup_status', 'focus_tracker_cleanup_progress', 'focus_tracker_cleanup_lock'), true)
        || str_starts_with($name, '_transient_') || str_starts_with($name, '_site_transient_');
}
function mysql_package_snapshot(mysqli $db, string $prefix): array {
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try {
        $tables = array(); $engines = array(); $excluded = array();
        foreach ($db->query('SHOW FULL TABLES')->fetch_all(MYSQLI_NUM) as $table) {
            if ($table[1] !== 'BASE TABLE') { continue; }
            $name = $table[0];
            mysql_package_check(preg_match('/^[A-Za-z0-9_]+$/D', $name) === 1, 'Every table in the dedicated database has a safe SQL identifier');
            $schema = $db->query('SHOW COLUMNS FROM `' . $name . '`')->fetch_all(MYSQLI_ASSOC);
            $rowHashes = array();
            $rows = $db->query('SELECT * FROM `' . $name . '`', MYSQLI_USE_RESULT);
            while ($row = $rows->fetch_assoc()) {
                if ($name === $prefix . 'options' && mysql_package_excluded_option($row['option_name'])) {
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
            $indexes=array();
            foreach($db->query('SHOW INDEX FROM `'.$name.'`')->fetch_all(MYSQLI_ASSOC) as $index){$entry=array_intersect_key($index,array_flip(array('Key_name','Non_unique','Seq_in_index','Column_name','Sub_part','Index_type','Collation','Visible','Expression')));$indexes[$index['Key_name'].'|'.str_pad((string)$index['Seq_in_index'],4,'0',STR_PAD_LEFT)]=$entry;}
            ksort($indexes,SORT_STRING);
            $tables[$name] = array('rows' => count($rowHashes), 'sha256' => hash('sha256', implode("\n", $rowHashes)), 'columnsSha256' => hash('sha256', serialize($schema)), 'indexesSha256'=>hash('sha256',serialize($indexes)));
            $status = $db->query("SHOW TABLE STATUS WHERE Name = '" . $db->real_escape_string($name) . "'")->fetch_assoc();
            $engines[$name] = $status['Engine'] ?? null;
        }
        ksort($tables, SORT_STRING); ksort($engines, SORT_STRING); ksort($excluded, SORT_STRING);
        $settingsRows = $db->query("SELECT option_name,option_value FROM `" . $prefix . "options` WHERE option_name IN ('focus_tracker_settings','focus_tracker_student_participation_defaults','focus_tracker_statistics_coverage_since','" . $prefix . "user_roles') ORDER BY option_name")->fetch_all(MYSQLI_ASSOC);
        $settings = array(); foreach ($settingsRows as $row) { $settings[$row['option_name']] = hash('sha256', $row['option_value']); }
        $db->commit();
        return array('tables' => $tables, 'engines' => $engines, 'settingsSha256' => $settings, 'excludedRuntimeOptionCounts' => $excluded);
    } catch (Throwable $error) { $db->rollback(); throw $error; }
}
function mysql_package_files(string $root): array {
    $hashes = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
        mysql_package_check(! $entry->isLink(), 'The installed package contains no symbolic link');
        if (! $entry->isFile()) { continue; }
        $key = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        $hashes[$key] = hash_file('sha256', $entry->getPathname());
    }
    ksort($hashes, SORT_STRING); return $hashes;
}
function mysql_package_aggregate(array $hashes): string {
    ksort($hashes, SORT_STRING); $state = hash_init('sha256');
    foreach ($hashes as $name => $hash) { hash_update($state, $name . "\0" . $hash . "\n"); }
    return hash_final($state);
}

try {
    $phase = $argv[1] ?? '';
    mysql_package_check(in_array($phase, array('inspect', 'prezip', 'postzip', 'platform-source-before', 'platform-source-after'), true), 'The requested package verification phase is valid');
    $config = mysql_package_json($argv[2] ?? '');
    $workspace = realpath(dirname(__DIR__, 3));
    $runRoot = realpath($workspace . '/_notes/tmp/focus-tracker-mysql8');
    $wpPath = realpath((string) ($config['wpPath'] ?? ''));
    $output = realpath((string) ($config['outputDir'] ?? ''));
    mysql_package_check($runRoot !== false && $wpPath !== false && $output !== false
        && mysql_package_path($wpPath) === mysql_package_path($runRoot . '/wordpress')
        && str_starts_with(mysql_package_path($output) . '/', mysql_package_path($runRoot) . '/'), 'Only the dedicated Oracle MySQL8 fixture paths may be used');
    mysql_package_check(($config['baseUrl'] ?? '') === 'http://127.0.0.1:33918' && ($config['dbHost'] ?? '') === '127.0.0.1:33917'
        && ($config['dbName'] ?? '') === 'focus_acceptance_mysql8_20261028', 'Only the exact new Oracle MySQL8 loopback database may be inspected');
    $configuration = (string) file_get_contents($wpPath . '/wp-config.php');
    preg_match('~\$table_prefix\s*=\s*[\x27\x22]([a-zA-Z0-9_]+)[\x27\x22]~', $configuration, $match); unset($configuration);
    $prefix = $match[1] ?? ''; mysql_package_check($prefix !== '', 'The native WordPress table prefix is known');
    $fixture = mysql_package_json($output . '/mysql-concurrency-fixture.json');
    $db = new mysqli('127.0.0.1', (string) $config['dbUser'], (string) $config['dbPassword'], (string) $config['dbName'], 33917); $db->set_charset('utf8mb4');
    $databaseIdentity = $db->query('SELECT VERSION() AS v,@@version_comment AS comment')->fetch_assoc();$databaseVersion=$databaseIdentity['v'];
    mysql_package_check(preg_match('/^8\.0\.[0-9]+/',$databaseVersion)===1&&str_contains($databaseIdentity['comment'],'MySQL Community Server')&&!str_contains(strtolower($databaseVersion.$databaseIdentity['comment']),'mariadb'),'The real database is Oracle MySQL8 Community Server');
    $snapshot = mysql_package_snapshot($db, $prefix);
    $focusTables = array_filter(array_keys($snapshot['tables']), static fn (string $name): bool => str_starts_with($name, $prefix . 'focus_tracker_'));
    mysql_package_check(count($focusTables) === 8 && count(array_filter($focusTables, static fn (string $name): bool => ($snapshot['engines'][$name] ?? '') === 'InnoDB')) === 8, 'All eight actual Focus Tracker tables use InnoDB');
    $beforePath = $output . '/mysql8-prezip.json';
    if ($phase === 'platform-source-before' || $phase === 'platform-source-after') {
        $sourceBaseline = $output . '/mysql8-platform-source-before.json';
        $platformHeader = (string) file_get_contents($wpPath . '/wp-content/plugins/oj-platform/oj-platform.php');
        $expectedVersion = $phase === 'platform-source-before' ? '2.5.4' : '2.5.5';
        mysql_package_check(preg_match('/^\s*\*\s*Version:\s*' . preg_quote($expectedVersion, '/') . '\s*$/m', $platformHeader) === 1, 'The platform source comparison uses its exact old or new installed runtime version');
        $sourceReport = array('phase' => $phase, 'passed' => true, 'capturedAt' => gmdate('c'), 'platformVersion' => $expectedVersion, 'databaseVersion' => $databaseVersion,
            'tableCount' => count($snapshot['tables']), 'snapshot' => $snapshot,
            'scope' => 'Separate source-runtime upgrade preservation. All current WordPress/application base-table row, column, index and settings/role digests are compared. Only the explicitly listed runtime options are excluded; no raw rows or secrets are exported. The final Focus ZIP prezip baseline is untouched.');
        if ($phase === 'platform-source-before') {
            mysql_package_check(! is_file($sourceBaseline), 'The platform source-upgrade baseline is immutable');
            $sourceReport['assertionCount'] = count($checks); $sourceReport['checks'] = $checks;
            mysql_package_save($sourceBaseline, $sourceReport);
        } else {
            $before = mysql_package_json($sourceBaseline);
            mysql_package_check($snapshot['tables'] === $before['snapshot']['tables'] && $snapshot['engines'] === $before['snapshot']['engines'], 'The platform source replacement and fresh WordPress boot preserve every current data row, column, index and engine');
            mysql_package_check($snapshot['settingsSha256'] === $before['snapshot']['settingsSha256'], 'The platform source upgrade preserves all protected settings and WordPress roles');
            $sourceReport['assertionCount'] = count($checks); $sourceReport['checks'] = $checks;
            mysql_package_save($output . '/mysql8-platform-source-after.json', $sourceReport);
        }
    } elseif ($phase === 'prezip') {
        $header=(string)file_get_contents($wpPath.'/wp-content/plugins/focus-tracker/focus-tracker.php');
        $durable=$db->query("SELECT option_value FROM `".$prefix."options` WHERE option_name='focus_tracker_db_version'")->fetch_assoc();
        mysql_package_check(preg_match('/^\s*\*\s*Version:\s*1\.1\.1\s*$/m',$header)===1&&($durable['option_value']??null)==='1.1.1','The final-package baseline is taken only after the tested native 1.1.1 source and schema are installed');
        mysql_package_check(! is_file($beforePath), 'A final package baseline cannot replace an earlier snapshot');
        mysql_package_save($beforePath, array('passed' => true, 'assertionCount' => count($checks), 'checks' => $checks, 'capturedAt' => gmdate('c'), 'database' => $config['dbName'], 'databaseVersion' => $databaseVersion, 'snapshot' => $snapshot,
            'scope' => 'All native database base-table rows and columns; only the listed runtime options are excluded. No source rows, settings, credentials or individual row hashes are exported.'));
    } elseif ($phase === 'postzip') {
        $before = mysql_package_json($beforePath);
        mysql_package_check($snapshot['tables'] === $before['snapshot']['tables'], 'Every current database data row and table column survives the final ZIP replacement unchanged');
        mysql_package_check($snapshot['engines'] === $before['snapshot']['engines'], 'Every current database storage engine survives both final ZIP replacements unchanged');
        mysql_package_check($snapshot['settingsSha256'] === $before['snapshot']['settingsSha256'], 'Stored settings, default-role ownership, coverage and all WordPress role permissions survive unchanged');
        $zipPath = realpath($argv[3] ?? '');
        $releaseRoot = realpath($workspace . '/_releases/focus-tracker/1.1.1');
        mysql_package_check($zipPath !== false && $releaseRoot !== false && str_starts_with(mysql_package_path($zipPath), mysql_package_path($releaseRoot) . '/'), 'The compared ZIP is the immutable 1.1.1 release artifact');
        $zip = new ZipArchive(); mysql_package_check($zip->open($zipPath) === true, 'The final release ZIP opens');
        $expected = array();
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            $relative = is_string($name) ? substr($name, strlen('focus-tracker/')) : '';
            mysql_package_check(is_string($name) && ! str_contains($name, '\\') && ! str_contains($name, '//')
                && ($name === 'focus-tracker/' || preg_match('~^focus-tracker/[A-Za-z0-9_.\-/]+$~D', $name) === 1)
                && ! str_contains('/' . $relative . '/', '/../') && ! str_contains('/' . $relative . '/', '/./'), 'Every final ZIP entry has a safe package-relative path');
            if (str_ends_with($name, '/')) { continue; }
            $key = substr($name, strlen('focus-tracker/'));
            mysql_package_check(! array_key_exists($key, $expected), 'The final ZIP has no duplicate file path');
            $bytes = $zip->getFromIndex($i); mysql_package_check(is_string($bytes), 'Every final ZIP entry can be read');
            $expected[$key] = hash('sha256', $bytes);
        }
        $zip->close(); ksort($expected, SORT_STRING);
        $installed = mysql_package_files($wpPath . '/wp-content/plugins/focus-tracker');
        mysql_package_check($expected === $installed && $expected !== array(), 'Every installed byte matches the final ZIP and there are no missing or extra installed files');
        $_SERVER['HTTP_HOST'] = '127.0.0.1:33918'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['SERVER_PORT'] = '33918';
        $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        require $wpPath . '/wp-load.php';
        mysql_package_check(wp_get_environment_type() === 'local' && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, 'The real WordPress boot stays local and does not spawn cleanup cron during preservation verification');
        mysql_package_check(defined('OJ_PLATFORM_VERSION') && OJ_PLATFORM_VERSION === '2.5.5', 'Both final package replacements boot the tested OJ Platform 2.5.5');
        mysql_package_check(defined('FOCUS_TRACKER_VERSION') && FOCUS_TRACKER_VERSION === '1.1.1' && get_option('focus_tracker_db_version') === '1.1.1', 'The final installed plugin and durable schema version are 1.1.1');
        $health = \FocusTracker\Database\Installer::inspect_schema();
        mysql_package_check(($health['ready'] ?? false) === true && ($health['issues'] ?? null) === array(), 'Native schema health verifies columns, InnoDB engines and critical unique indexes');
        $userIndex=false;foreach($wpdb->get_results('SHOW INDEX FROM '.\FocusTracker\Database\Installer::scores_table(),ARRAY_A) as $index){if((int)$index['Seq_in_index']===1&&$index['Column_name']==='user_id'){$userIndex=true;}}
        mysql_package_check($userIndex,'The final native score table keeps a user_id-leading index for account-scoped erasure');
        $key = \CodingPanda\OjPlatform\AddonApi\V1\AddonCapabilityKeyV1::wordpress('focus_tracker_participate');
        mysql_package_check((get_role('subscriber')->capabilities[$key] ?? null) === true, 'The native subscriber participation default survives the final ZIP');
        foreach ($fixture['students'] as $index => $student) {
            $user = get_userdata((int) $student['userId']);
            mysql_package_check($user instanceof WP_User && user_can($user, $key) && ! array_key_exists($key, $user->caps), 'Native browser learner ' . ($index + 1) . ' still inherits participation without a per-user grant');
        }
        $afterBoot = mysql_package_snapshot($db, $prefix);
        mysql_package_check($afterBoot['tables'] === $snapshot['tables'] && $afterBoot['settingsSha256'] === $snapshot['settingsSha256'], 'A fresh native WordPress boot preserves all current data and nonruntime settings');
        $report = array('phase' => $phase, 'passed' => true, 'assertionCount' => count($checks), 'checks' => $checks, 'capturedAt' => gmdate('c'),
            'environment' => array('wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'database' => $databaseVersion, 'databaseName' => $config['dbName'], 'platform' => OJ_PLATFORM_VERSION, 'focusTracker' => FOCUS_TRACKER_VERSION),
            'tableCount' => count($snapshot['tables']), 'snapshot' => $afterBoot, 'schemaHealth' => array('ready' => $health['ready'], 'issues' => $health['issues']),
            'releaseZip' => array('name' => basename($zipPath), 'sha256' => hash_file('sha256', $zipPath), 'files' => count($expected), 'aggregateSha256' => mysql_package_aggregate($installed),
                'aggregateAlgorithm' => 'SHA256(sorted(relativePath + NUL + fileSha256 + LF))'),
            'scope' => 'Native MySQL preservation plus actual WordPress boot, schema health, inherited capabilities and final ZIP byte equality. HTTP cookies/timers are tested separately.');
        mysql_package_save($output . '/mysql8-postzip-report.json', $report);
    }
    $db->close(); $finished = true;
    echo json_encode(array('phase' => $phase, 'passed' => true, 'assertions' => count($checks), 'databaseVersion' => $databaseVersion, 'tableCount' => count($snapshot['tables'])), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
    fwrite(STDERR, 'Native package verification failed: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    if ($failed !== array()) { fwrite(STDERR, 'Assertion: ' . end($failed) . "\n"); }
    exit(1);
}
