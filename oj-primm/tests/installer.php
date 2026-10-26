<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Core/Autoloader.php';
\OjPrimm\Core\Autoloader::register();
define('ABSPATH', __DIR__ . '/Support/WordPress/');
define('ARRAY_A', 'ARRAY_A');
$GLOBALS['primm_installer_options'] = ['oj_primm_schema_version' => '2'];
function get_option(string $name, mixed $default = null): mixed {
    return array_key_exists($name, $GLOBALS['primm_installer_cached_options'] ?? [])
        ? $GLOBALS['primm_installer_cached_options'][$name] : ($GLOBALS['primm_installer_options'][$name] ?? $default);
}
function update_option(string $name, mixed $value, bool $autoload = false): bool {
    if (in_array($name, $GLOBALS['primm_installer_failed_writes'] ?? [], true)) { return false; }
    $GLOBALS['primm_installer_options'][$name] = $value;
    unset($GLOBALS['primm_installer_cached_options'][$name]);
    return true;
}
function delete_option(string $name): bool { unset($GLOBALS['primm_installer_options'][$name]); return true; }
function dbDelta(string $sql): void { $GLOBALS['primm_installer_sql'][] = $sql; }

final class InstallerDatabase
{
    public string $prefix = 'test_'; public array $columns = []; public array $indexes = []; public int $lockReads = 0; public bool $lockAvailable = true;
    public string $options = 'test_options'; public string $last_error = ''; public bool $optionReadsAvailable = true;
    public function __construct()
    {
        $this->columns = [
            'activities' => ['id','creator_user_id','context_type','context_id','title','family_id','parent_activity_id','content_version','revision','status','request_key_hash','data_json','created_at','updated_at'],
            'attempts' => ['id','activity_id','user_id','version','state_json','last_request_hash','created_at','updated_at'],
            'events' => ['id','attempt_id','user_id','stage','event_type','payload_json','created_at'],
            'generations' => ['id','creator_user_id','fingerprint','status','activity_id','error_message','lease_token','lease_expires_at','next_attempt_at','data_json','created_at','updated_at'],
            'generation_control' => ['scope_key','data_json','updated_at'],
            'practices' => ['id','activity_id','user_id','fingerprint','status','lease_token','lease_expires_at','data_json','created_at','updated_at'],
        ];
        $this->indexes = ['activities' => ['PRIMARY'=>['id'],'creator_request'=>['creator_user_id','request_key_hash']],
            'attempts' => ['PRIMARY'=>['id'],'learner_activity'=>['activity_id','user_id']], 'events' => ['PRIMARY'=>['id']],
            'generations' => ['PRIMARY'=>['id'],'creator_fingerprint'=>['creator_user_id','fingerprint']],
            'generation_control' => ['PRIMARY'=>['scope_key']], 'practices' => ['PRIMARY'=>['id'],'learner_fingerprint'=>['user_id','fingerprint']]];
    }
    public function get_charset_collate(): string { return ''; }
    public function esc_like(string $value): string { return $value; }
    public function prepare(string $sql, mixed ...$args): string { foreach ($args as $arg) { $sql = preg_replace('/%s/', "'" . $arg . "'", $sql, 1); } return $sql; }
    public function get_var(string $sql): string { if (str_contains($sql, 'GET_LOCK')) { ++$this->lockReads; return $this->lockAvailable ? '1' : '0'; } if (str_contains($sql, 'RELEASE_LOCK')) { return '1'; } preg_match("/LIKE '([^']+)'/", $sql, $match); return $match[1] ?? ''; }
    public function get_row(string $sql, string $format): array { return ['Engine' => 'InnoDB']; }
    public function get_results(string $sql, string $format): array
    {
        if (str_starts_with($sql, 'SELECT option_name, option_value')) {
            $this->last_error = $this->optionReadsAvailable ? '' : 'option read failed';
            return $this->optionReadsAvailable ? array_map(static fn (string $name, mixed $value): array => ['option_name' => $name,
                'option_value' => is_array($value) ? serialize($value) : (string) $value],
                array_keys($GLOBALS['primm_installer_options']), array_values($GLOBALS['primm_installer_options'])) : [];
        }
        preg_match('/`test_oj_primm_([^`]+)`/', $sql, $match); $table = $match[1] ?? '';
        if (str_starts_with($sql, 'SHOW COLUMNS')) { return array_map(static fn (string $field): array => ['Field'=>$field], $this->columns[$table] ?? []); }
        $rows = [];
        foreach ($this->indexes[$table] ?? [] as $name => $fields) { foreach ($fields as $index => $field) { $rows[] = ['Key_name'=>$name,'Non_unique'=>0,'Seq_in_index'=>$index+1,'Column_name'=>$field]; } }
        return array_reverse($rows); // Real SHOW INDEX row order is not a contract.
    }
}
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { ++$checks; if (! $condition) { throw new RuntimeException($message); } };
foreach ([['activities','family_id'],['activities','revision'],['activities','status'],['activities','request_key_hash'],['generations','next_attempt_at'],['practices','lease_expires_at']] as [$table,$column]) {
    $wpdb = new InstallerDatabase(); $wpdb->columns[$table] = array_values(array_diff($wpdb->columns[$table], [$column]));
    $GLOBALS['primm_installer_options']['oj_primm_schema_version'] = '2';
    $assert(! \OjPrimm\Storage\Installer::install() && get_option('oj_primm_schema_version') === '2', 'A partial ALTER failure cannot advance the migration marker: '.$table.'.'.$column);
}
foreach ([['activities','creator_request'],['attempts','learner_activity'],['generations','creator_fingerprint'],['practices','learner_fingerprint']] as [$table,$key]) {
    $wpdb = new InstallerDatabase(); unset($wpdb->indexes[$table][$key]); $GLOBALS['primm_installer_options']['oj_primm_schema_version'] = '2';
    $assert(! \OjPrimm\Storage\Installer::install() && get_option('oj_primm_schema_version') === '2', 'Missing unique constraints cannot silently disable admission/idempotency invariants: '.$key);
}
$wpdb = new InstallerDatabase(); $wpdb->indexes['practices']['learner_fingerprint'] = ['fingerprint','user_id'];
$assert(! \OjPrimm\Storage\Installer::install(), 'The expected unique key fields and order are verified, not just its name.');
$wpdb = new InstallerDatabase(); $assert(\OjPrimm\Storage\Installer::install() && get_option('oj_primm_schema_version') === '3', 'A complete compatible schema advances the migration marker only after all tables, columns and keys are verified.');
$wpdb = new InstallerDatabase(); unset($wpdb->indexes['attempts']['learner_activity']);
$GLOBALS['primm_installer_options']['oj_primm_schema_version'] = '2'; unset($GLOBALS['primm_installer_options']['oj_primm_schema_retry']);
$GLOBALS['primm_installer_sql'] = [];
\OjPrimm\Storage\Installer::maybeInstall(); $afterFailure = count($GLOBALS['primm_installer_sql']);
\OjPrimm\Storage\Installer::maybeInstall();
$assert($afterFailure === 6 && count($GLOBALS['primm_installer_sql']) === $afterFailure && $wpdb->lockReads === 1,
    'A failed migration must back off instead of issuing DDL and metadata on every unrelated request.');
unset($GLOBALS['primm_installer_options']['oj_primm_schema_retry']); $wpdb->lockAvailable = false;
\OjPrimm\Storage\Installer::maybeInstall();
$assert(count($GLOBALS['primm_installer_sql']) === $afterFailure, 'A concurrent migration lease must prevent a second DDL execution.');
$wpdb = new InstallerDatabase(); \OjPrimm\Storage\Installer::maybeInstall();
$assert(get_option('oj_primm_schema_version') === '3' && get_option('oj_primm_schema_retry') === null, 'A successful retry clears the backoff after verified migration.');
$revision = '3:development'; $before = count($GLOBALS['primm_installer_sql']);
$GLOBALS['primm_installer_options'] = ['oj_primm_schema_version' => '2', 'oj_primm_schema_retry' => ['revision' => $revision, 'after' => time() + 60]];
$GLOBALS['primm_installer_cached_options'] = ['oj_primm_schema_version' => '2', 'oj_primm_schema_retry' => ['revision' => $revision, 'after' => time() - 60]];
$wpdb = new InstallerDatabase(); \OjPrimm\Storage\Installer::maybeInstall();
$assert(count($GLOBALS['primm_installer_sql']) === $before && $wpdb->lockReads === 1,
    'A worker with an expired cached retry must respect the preceding worker live 60-second failure marker after acquiring its lease.');
$GLOBALS['primm_installer_options']['oj_primm_schema_version'] = '3';
unset($GLOBALS['primm_installer_options']['oj_primm_schema_retry']);
\OjPrimm\Storage\Installer::maybeInstall();
$assert(count($GLOBALS['primm_installer_sql']) === $before, 'A completed migration must be detected even when this request cached the old schema marker.');
$GLOBALS['primm_installer_options'] = ['oj_primm_schema_version' => '2']; $GLOBALS['primm_installer_cached_options'] = [];
$GLOBALS['primm_installer_failed_writes'] = ['oj_primm_schema_retry'];
\OjPrimm\Storage\Installer::maybeInstall();
$assert(count($GLOBALS['primm_installer_sql']) === $before, 'If the backoff marker cannot be persisted no DDL may start.');
$GLOBALS['primm_installer_failed_writes'] = []; $wpdb->optionReadsAvailable = false;
\OjPrimm\Storage\Installer::maybeInstall();
$assert(count($GLOBALS['primm_installer_sql']) === $before, 'A failed live marker read must not bypass the migration throttle.');
$wpdb->optionReadsAvailable = true; $GLOBALS['primm_installer_failed_writes'] = ['oj_primm_schema_version'];
$GLOBALS['primm_installer_cached_options'] = ['oj_primm_schema_version' => '3'];
$assert(! \OjPrimm\Storage\Installer::install() && $GLOBALS['primm_installer_options']['oj_primm_schema_version'] === '2',
    'A cached success must not hide failure to persist the schema version.');
$GLOBALS['primm_installer_failed_writes'] = []; $GLOBALS['primm_installer_cached_options'] = [];
$GLOBALS['primm_installer_options']['oj_primm_schema_retry'] = ['revision' => $revision, 'after' => time() - 61];
\OjPrimm\Storage\Installer::maybeInstall();
$assert($GLOBALS['primm_installer_options']['oj_primm_schema_version'] === '3' && ! isset($GLOBALS['primm_installer_options']['oj_primm_schema_retry']),
    'An actually expired 60-second marker must allow one verified retry and clear after success.');
echo 'PRIMM installer migration checks passed: '.$checks." (schema metadata fixtures; no MariaDB was started)\n";
