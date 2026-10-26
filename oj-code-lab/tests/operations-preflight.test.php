<?php
/** Mock DB/environment contract tests; this is not a production-site check. */
declare(strict_types=1);
define('OJ_CODE_LAB_PREFLIGHT_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/scripts/operations-preflight.php';

final class Preflight_Fake_DB {
    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $last_error = '';
    public bool $suppressed = false;
    public array $queries = array();
    public array $options;
    public array $network = array();
    public array $missingTables = array();
    public array $missingColumns = array();
    public array $invalidIndexes = array();
    public int $packet = 67108864;
    public mixed $lockResult = '1';
    public mixed $releaseResult = '1';
    public bool $jsonFailure = false;
    public bool $throwLock = false;
    public bool $optionsFailure = false;
    public int $pendingSummaries = 0;
    public function __construct() {
        $this->options = array(
            'active_plugins' => array('oj-code-lab/oj-code-lab.php', 'oj-platform/oj-platform.php'),
            'home' => 'https://private-school.example/?token=private-source-token',
            'siteurl' => 'https://private-school.example/',
            'oj_code_lab_db_version' => '2',
            'cron' => array(1700001000 => array('oj_code_lab_cache_cleanup' => array('hash' => array('schedule' => 'hourly', 'interval' => 3600, 'args' => array('private-cron-argument'))))),
            'oj_code_lab_kaggle_credentials' => array('username' => 'private-kaggle-username', 'key' => 'v2:private-encrypted-key'),
        );
    }
    public function suppress_errors(bool $value): bool { $previous = $this->suppressed; $this->suppressed = $value; return $previous; }
    public function prepare(string $query, mixed ...$arguments): string {
        foreach ($arguments as $argument) {
            $replacement = is_int($argument) ? (string) $argument : "'" . str_replace("'", "''", (string) $argument) . "'";
            $query = preg_replace_callback('/%[sd]/', static fn(): string => $replacement, $query, 1);
        }
        return $query;
    }
    public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
    private function record(string $sql): void {
        if (! preg_match('/^(SELECT|SHOW)\b/', $sql)) { throw new RuntimeException('Unexpected mutating SQL'); }
        $this->queries[] = $sql; $this->last_error = '';
    }
    public function get_results(string $sql, string $format): ?array {
        $this->record($sql);
        if (str_starts_with($sql, 'SELECT option_name')) {
            if ($this->optionsFailure) { $this->last_error = 'private-db-password'; return null; }
            $rows = array();
            foreach ($this->options as $name => $value) { $rows[] = array('option_name' => $name, 'option_value' => is_array($value) ? serialize($value) : $value); }
            return $rows;
        }
        preg_match('/`wp_oj_code_lab_([a-z_]+)`/', $sql, $match);
        $table = $match[1] ?? '';
        $columns = array(
            'notebooks' => array('id', 'user_id', 'cells', 'updated_at', 'revision', 'cell_count', 'has_error'),
            'datasets' => array('id', 'label', 'icon', 'source', 'kaggle_owner', 'kaggle_slug', 'source_url', 'upload_key', 'file_name', 'sample_key', 'created_by', 'created_at', 'sort_order'),
            'dataset_cache' => array('cache_key', 'content', 'created_at'),
            'uploads' => array('upload_key', 'content', 'created_at'),
        );
        if (str_starts_with($sql, 'SHOW COLUMNS')) {
            return array_map(static fn(string $name): array => array('Field' => $name), array_values(array_diff($columns[$table], $this->missingColumns[$table] ?? array())));
        }
        $indexes = array(
            'notebooks' => array('PRIMARY' => array('id', 0), 'user_id' => array('user_id', 0)),
            'datasets' => array('PRIMARY' => array('id', 0), 'sort_order' => array('sort_order', 1), 'sample_key' => array('sample_key', 1)),
            'dataset_cache' => array('PRIMARY' => array('cache_key', 0), 'created_at' => array('created_at', 1)),
            'uploads' => array('PRIMARY' => array('upload_key', 0)),
        );
        if (str_starts_with($sql, 'SHOW INDEX')) {
            $rows = array();
            foreach ($indexes[$table] as $name => [$column, $nonUnique]) {
                $override = $this->invalidIndexes[$table][$name] ?? array();
                if (false === $override) { continue; }
                $rows[] = array_merge(array('Key_name' => $name, 'Column_name' => $column, 'Seq_in_index' => 1, 'Non_unique' => $nonUnique, 'Sub_part' => null), $override);
            }
            return $rows;
        }
        throw new RuntimeException('Unexpected SQL in get_results');
    }
    public function get_var(string $sql): mixed {
        $this->record($sql);
        if (str_starts_with($sql, 'SHOW TABLES')) {
            preg_match("/LIKE '([^']+)'/", $sql, $match);
            $table = stripslashes($match[1]);
            return in_array($table, $this->missingTables, true) ? null : $table;
        }
        if (str_starts_with($sql, 'SELECT COUNT')) { return $this->pendingSummaries; }
        if (str_starts_with($sql, 'SELECT GET_LOCK')) {
            if ($this->throwLock) { throw new RuntimeException('private-db-password'); }
            return $this->lockResult;
        }
        if (str_starts_with($sql, 'SELECT RELEASE_LOCK')) { return $this->releaseResult; }
        if (str_starts_with($sql, 'SELECT meta_value')) { return serialize($this->network); }
        throw new RuntimeException('Unexpected SQL in get_var');
    }
    public function get_row(string $sql, string $format): ?array {
        $this->record($sql);
        if ($this->jsonFailure) { $this->last_error = 'private-source-token SQL failed'; return null; }
        return array('database_version' => '11.4.10-MariaDB', 'packet_bytes' => $this->packet, 'json_valid' => 1, 'json_length' => 2, 'json_search' => '"$[0].type"');
    }
}
function environment(): array {
    return array('wordpress' => '6.6.2', 'php' => '8.3.33', 'sapi' => 'cli', 'now' => 1700000000,
        'pluginDirectory' => __DIR__ . '/fixtures/preflight', 'codeLabBootstrapped' => false, 'platformBootstrapped' => false,
        'multisite' => false, 'networkId' => 1, 'disableWpCron' => false, 'alternateWpCron' => false,
        'openssl' => true, 'aes256gcm' => true, 'mbstring' => true, 'iconv' => true,
        'memoryLimit' => '256M', 'postMaxSize' => '32M', 'uploadMaxFilesize' => '16M', 'fileUploads' => true);
}
function check(array $report, string $id): array {
    foreach ($report['checks'] as $item) { if ($id === $item['id']) { return $item; } }
    throw new RuntimeException('Missing check: ' . $id);
}
set_error_handler(static function (int $severity, string $message): bool {
    if (! (error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity);
});
$assertions = 0;
function expect(bool $condition, string $message): void {
    global $assertions; $assertions++;
    if (! $condition) { throw new RuntimeException($message); }
}
function run_case(?callable $change = null, array $overrides = array()): array {
    $db = new Preflight_Fake_DB(); if ($change) { $change($db); }
    return array(Oj_Code_Lab_Operations_Preflight::run($db, array_replace(environment(), $overrides)), $db);
}
[$healthy, $db] = run_case();
expect($healthy['preflightPassed'] && 'unknown' === $healthy['productionReady'], 'Healthy CLI snapshot must not authorize production');
expect(array() === $healthy['requiredFailures'], 'Healthy fixture should have no required failures');
expect(in_array('web.configuration', $healthy['requiredUnknowns'], true), 'Web SAPI limits remain unknown');
expect(in_array('operations.backup_restore', $healthy['requiredUnknowns'], true), 'Restore evidence remains unknown');
expect(! $db->suppressed, 'Original error suppression must be restored');
expect(count(array_filter($db->queries, static fn(string $sql): bool => ! preg_match('/^(SELECT|SHOW)\b/', $sql))) === 0, 'Database writes are forbidden');
$acquire = array_values(array_filter($db->queries, static fn(string $sql): bool => str_starts_with($sql, 'SELECT GET_LOCK')));
$release = array_values(array_filter($db->queries, static fn(string $sql): bool => str_starts_with($sql, 'SELECT RELEASE_LOCK')));
preg_match("/'([^']+)'/", $acquire[0], $lockA); preg_match("/'([^']+)'/", $release[0], $lockB);
expect(1 === count($acquire) && 1 === count($release) && $lockA[1] === $lockB[1], 'Every acquired lock must be released with the same name');
$encoded = json_encode($healthy, JSON_THROW_ON_ERROR);
foreach (array('private-school.example', 'private-source-token', 'private-kaggle-username', 'private-encrypted-key', 'private-cron-argument', $lockA[1], __DIR__) as $secret) {
    expect(! str_contains($encoded, $secret), 'Report must not expose private state: ' . $secret);
}
[$report] = run_case(static function ($db): void { $db->missingTables[] = 'wp_oj_code_lab_uploads'; });
expect(false === $report['productionReady'] && 'fail' === check($report, 'table.uploads')['status'], 'Missing upload table blocks preflight');
[$report] = run_case(static function ($db): void { $db->missingColumns['notebooks'] = array('revision'); });
expect('fail' === check($report, 'columns.notebooks')['status'], 'Missing revision blocks preflight');
[$report] = run_case(static function ($db): void { $db->invalidIndexes['notebooks']['user_id'] = array('Non_unique' => 1); });
expect('fail' === check($report, 'indexes.notebooks')['status'], 'Non-unique user index breaks notebook isolation');
[$report] = run_case(static function ($db): void { $db->invalidIndexes['uploads']['PRIMARY'] = array('Sub_part' => 8); });
expect('fail' === check($report, 'indexes.uploads')['status'], 'A prefix primary index is insufficient');
[$report] = run_case(static function ($db): void { $db->invalidIndexes['dataset_cache']['created_at'] = false; });
expect('fail' === check($report, 'indexes.dataset_cache')['status'], 'Missing cleanup index blocks preflight');
[$report] = run_case(static function ($db): void { $db->jsonFailure = true; });
expect('fail' === check($report, 'database.json')['status'] && ! str_contains(json_encode($report), 'private-source-token'), 'JSON error must be safe and fail');
[$report] = run_case(static function ($db): void { $db->options['oj_code_lab_db_version'] = '1'; });
expect('fail' === check($report, 'schema.version')['status'], 'Old schema blocks preflight');
[$report] = run_case(static function ($db): void { $db->options['oj_code_lab_install_error'] = 'private-db-password'; });
expect('fail' === check($report, 'schema.install_error')['status'] && ! str_contains(json_encode($report), 'private-db-password'), 'Installation error contents stay private');
[$report, $db] = run_case(static function ($db): void { $db->lockResult = null; });
expect('fail' === check($report, 'database.advisory_lock')['status'] && 0 === count(array_filter($db->queries, static fn(string $sql): bool => str_starts_with($sql, 'SELECT RELEASE_LOCK'))), 'Denied lock is a failure and no unowned lock is released');
[$report] = run_case(static function ($db): void { $db->releaseResult = '0'; });
expect('fail' === check($report, 'database.advisory_lock')['status'], 'Release failure cannot pass');
[$report, $db] = run_case(static function ($db): void { $db->throwLock = true; });
expect('fail' === check($report, 'execution.exception')['status'] && ! str_contains(json_encode($report), 'private-db-password') && ! $db->suppressed, 'Exceptions restore suppression and hide secrets');
[$report, $db] = run_case(static function ($db): void { $db->prefix = 'wp_`; DROP TABLE users; --'; });
expect(false === $report['productionReady'] && array() === $db->queries, 'Unsafe identifiers must run no query');
[$report] = run_case(static function ($db): void { $db->options['home'] = 'http://private-school.example'; });
expect('fail' === check($report, 'https.home')['status'], 'HTTP fails production URL requirements');
[$report] = run_case(static function ($db): void { $db->options['siteurl'] = 'https://secret:password@private-school.example'; });
expect('fail' === check($report, 'https.siteurl')['status'], 'Credential-bearing site URL must fail without output');
[$report] = run_case(null, array('php' => '8.0.30'));
expect('fail' === check($report, 'php.version')['status'], 'PHP below declared minimum fails');
[$report] = run_case(null, array('wordpress' => '6.5.9'));
expect('fail' === check($report, 'wordpress.version')['status'], 'WordPress below declared minimum fails');
[$report] = run_case(static function ($db): void { $db->options['active_plugins'][1] = 'oj-platform-unsupported/oj-platform.php'; });
expect('fail' === check($report, 'oj-platform.version')['status'] && 'fail' === check($report, 'oj-platform.addon_api')['status'], 'Unsupported platform/API fail');
[$report] = run_case(static function ($db): void { $db->options['active_plugins'][1] = 'oj-platform-api-unknown/oj-platform.php'; });
expect('unknown' === check($report, 'oj-platform.addon_api')['status'] && 'unknown' === $report['productionReady'], 'Non-literal API version must remain unknown without running PHP');
[$report] = run_case(static function ($db): void { $db->network = array('oj-code-lab/oj-code-lab.php' => 1); }, array('multisite' => true));
expect('fail' === check($report, 'oj-code-lab.activation_scope')['status'], 'Network activation of Code Lab is unsupported');
[$report] = run_case(static function ($db): void { $db->network = array('oj-platform/oj-platform.php' => 1); unset($db->options['active_plugins'][1]); }, array('multisite' => true));
expect('pass' === check($report, 'oj-platform.active')['status'], 'Network platform with site-local Code Lab is recognized');
[$report] = run_case(static function ($db): void { unset($db->options['cron']); });
expect('fail' === check($report, 'cron.cache_cleanup')['status'], 'Missing cleanup schedule fails');
[$report] = run_case(static function ($db): void { $db->options['cron'][1700001000]['oj_code_lab_cache_cleanup']['hash']['interval'] = 86400; });
expect('fail' === check($report, 'cron.cache_cleanup')['status'], 'Non-hourly cleanup does not satisfy schedule');
[$report] = run_case(null, array('now' => 1700010000));
expect('warning' === check($report, 'cron.cache_cleanup')['status'], 'Overdue cleanup produces an operational warning');
[$report] = run_case(null, array('disableWpCron' => true));
expect('unknown' === check($report, 'cron.dispatch')['status'], 'External cron must not be assumed configured');
[$report] = run_case(null, array('mbstring' => false, 'iconv' => false));
expect('fail' === check($report, 'php.encoding_conversion')['status'], 'At least one encoding converter is required');
[$report] = run_case(null, array('mbstring' => false));
expect('pass' === check($report, 'php.encoding_conversion')['status'] && 'warning' === check($report, 'php.mbstring')['status'], 'iconv alone is valid with optional mbstring warning');
[$report] = run_case(null, array('aes256gcm' => false));
expect('fail' === check($report, 'php.openssl')['status'], 'OpenSSL without GCM is insufficient');
[$report] = run_case(static function ($db): void { $db->packet = 16777216; });
expect('fail' === check($report, 'database.max_allowed_packet')['status'], 'Packet needs SQL escaping overhead beyond raw payload');
[$report] = run_case(static function ($db): void { $db->packet = 16777216; $db->options['active_plugins'][0] = 'oj-code-lab-packet-aware/oj-code-lab.php'; });
expect('warning' === check($report, 'database.max_allowed_packet')['status'] && $report['preflightPassed'], 'Packet-aware releases can safely operate at a reduced limit');
expect(8355840 === check($report, 'database.max_allowed_packet')['details']->effectiveDefaultNotebookBytes && 8355840 === check($report, 'database.max_allowed_packet')['details']->effectiveDefaultDatasetBytes, '16 MiB packet applies 2x escaping and 64 KiB margin');
[$report] = run_case(static function ($db): void { $db->packet = 65536; $db->options['active_plugins'][0] = 'oj-code-lab-packet-aware/oj-code-lab.php'; });
expect('fail' === check($report, 'database.max_allowed_packet')['status'], 'Packet with no remaining payload capacity still fails');

[$report] = run_case(null, array('postMaxSize' => '8M', 'uploadMaxFilesize' => '2M', 'memoryLimit' => '64M'));
expect($report['preflightPassed'] && 'warning' === check($report, 'php.cli_request_limits')['status'] && 'unknown' === check($report, 'web.configuration')['status'], 'CLI limits cannot be falsely applied to web requests');
expect(134217728 === Oj_Code_Lab_Operations_Preflight::ini_bytes('128M'), 'INI MiB parsing');
expect(0 === Oj_Code_Lab_Operations_Preflight::ini_bytes('0') && -1 === Oj_Code_Lab_Operations_Preflight::ini_bytes('-1'), 'INI unlimited sentinel handling');
expect(null === Oj_Code_Lab_Operations_Preflight::ini_bytes('garbage') && null === Oj_Code_Lab_Operations_Preflight::ini_bytes('99999999999999999999G'), 'Invalid and overflowing limits stay unknown');
[$report] = run_case(static function ($db): void { unset($db->options['oj_code_lab_kaggle_credentials']); });
expect($report['preflightPassed'] && 'warning' === check($report, 'kaggle.credentials')['status'], 'Optional Kaggle absence is not a core operation failure');
[$report] = run_case(static function ($db): void { $db->options['oj_code_lab_kaggle_credentials']['username'] = ''; });
expect(true === check($report, 'kaggle.credentials')['details']->partialRecord, 'Partial credentials must be visible without values');
[$report] = run_case(static function ($db): void { unset($db->options['oj_code_lab_kaggle_credentials']); $db->options['oj_code_lab_kaggle_username'] = 'private-kaggle-username'; $db->options['oj_code_lab_kaggle_key'] = 'private-legacy-key'; });
expect('warning' === check($report, 'kaggle.credentials')['status'] && true === check($report, 'kaggle.credentials')['details']->legacyFormat && ! str_contains(json_encode($report), 'private-legacy-key'), 'Legacy credential format warns without revealing key');
[$report] = run_case(static function ($db): void { $db->optionsFailure = true; });
expect('fail' === check($report, 'database.options')['status'] && ! str_contains(json_encode($report), 'private-db-password'), 'Option errors must be masked');
[$report, $db] = run_case(static function ($db): void { $db->suppressed = true; });
expect($db->suppressed, 'Preexisting suppressed state remains suppressed');
[$report] = run_case(static function ($db): void { $db->pendingSummaries = 2; });
expect('warning' === check($report, 'schema.summary_backfill')['status'], 'Pending summary migration stays visible');
[$report] = run_case(static function ($db): void { $db->options['cron'][1700001000]['oj_code_lab_cache_cleanup'] = 'corrupt'; });
expect('fail' === check($report, 'cron.cache_cleanup')['status'], 'Malformed cron entries must fail without warnings or raw diagnostics');
[$report] = run_case(null, array('codeLabBootstrapped' => true));
expect('warning' === check($report, 'execution.isolated')['status'], 'Already loaded plugin triggers bootstrap side effect warning');
echo json_encode(array('suite' => 'operations-preflight', 'scope' => 'mock-db-and-environment-only', 'assertions' => $assertions, 'passed' => true), JSON_THROW_ON_ERROR), "\n";
