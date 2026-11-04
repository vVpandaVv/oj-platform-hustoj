<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/runner/install-tools/WordPressConfigurator.php';
use OjMicrobit\Installer\WordPressConfigurator;

$checks = 0; $cacheDeletes = []; $interfere = false;
function checkCas(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function maybe_serialize(mixed $value): string { return serialize($value); }
function wp_cache_delete(string $key, string $group): bool { $GLOBALS['cacheDeletes'][] = [$key, $group]; return true; }
$wpdb = new class {
    public string $options = 'wp_options'; public PDO $pdo; public array $queries = [];
    public function __construct() { $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $this->pdo->exec('CREATE TABLE wp_options(option_name TEXT PRIMARY KEY,option_value TEXT NOT NULL,autoload TEXT)'); }
    public function prepare(string $query, mixed ...$args): string {
        $index = 0; return preg_replace_callback('/%s/', function () use (&$index, $args): string { return $this->pdo->quote((string) $args[$index++]); }, $query);
    }
    public function query(string $sql): int|false {
        $this->queries[] = $sql;
        if ($GLOBALS['interfere']) { $GLOBALS['interfere'] = false; putOption(['runnerUrl' => 'http://127.0.0.1:9999', 'retentionDays' => 22]); }
        // SQLite compares TEXT byte-for-byte by default. Only the MySQL BINARY
        // operator spelling is adapted; the guarded SQL actually executes.
        return $this->pdo->exec(str_replace('BINARY option_value=BINARY ', 'option_value=', $sql));
    }
};
function putOption(array $value): void { $s = $GLOBALS['wpdb']->pdo->prepare("INSERT INTO wp_options VALUES ('oj_microbit_settings',?,'yes') ON CONFLICT(option_name) DO UPDATE SET option_value=excluded.option_value"); $s->execute([serialize($value)]); }
function get_option(string $key, mixed $default = false): mixed {
    if ($key === 'active_plugins') { return ['oj-microbit/oj-microbit.php']; }
    $s = $GLOBALS['wpdb']->pdo->prepare('SELECT option_value FROM wp_options WHERE option_name=?'); $s->execute([$key]); $value = $s->fetchColumn(); return $value === false ? $default : unserialize($value);
}
function add_option(string $key, mixed $value, string $deprecated = '', mixed $autoload = null): bool {
    $s = $GLOBALS['wpdb']->pdo->prepare('INSERT OR IGNORE INTO wp_options VALUES (?,?,?)'); $s->execute([$key, serialize($value), $autoload ? 'yes' : 'no']); return $s->rowCount() === 1;
}
$private = [];
$tool = new WordPressConfigurator([], static function (string $path, string $value) use (&$private): void { $private[$path] = $value; });
$prepare = ['mode' => 'prepare', 'url' => 'http://127.0.0.1:3037', 'secretFile' => '/key', 'backupFile' => '/backup'];
$token = str_repeat('t', 40); $old = ['retentionDays' => 321, 'runnerToken' => $token]; putOption($old);
$prepared = $tool->execute($prepare); $request = ['mode' => 'configure', 'url' => $prepare['url'], 'token' => $token, 'expectedHash' => $prepared['expectedHash']];
$result = $tool->execute($request);
checkCas($result['configured'] && get_option('oj_microbit_settings')['retentionDays'] === 321, 'A PK-scoped serialized-value CAS updates the connection and preserves preferences');
checkCas(str_contains($wpdb->queries[0], 'WHERE option_name=') && str_contains($wpdb->queries[0], 'BINARY option_value=BINARY '), 'SQL guards the original option in the same statement');
checkCas($wpdb->pdo->query("SELECT autoload FROM wp_options WHERE option_name='oj_microbit_settings'")->fetchColumn() === 'no' && count($cacheDeletes) === 3, 'CAS preserves non-autoloaded connection settings and invalidates WordPress caches');
putOption($old); $prepared = $tool->execute($prepare); $request['expectedHash'] = $prepared['expectedHash']; $interfere = true;
try { $tool->execute($request); throw new RuntimeException('Concurrent change was accepted'); }
catch (RuntimeException $error) { checkCas($error->getMessage() === 'settings_changed', 'An update that races after the hash read is rejected by SQL CAS'); }
checkCas(get_option('oj_microbit_settings')['retentionDays'] === 22, 'The racing administrator update survives');
$wpdb->pdo->exec('DELETE FROM wp_options'); $prepared = $tool->execute($prepare); $key = trim($private['/key']); $request['token'] = $key; $request['expectedHash'] = $prepared['expectedHash'];
checkCas($tool->execute($request)['configured'] && get_option('oj_microbit_settings')['runnerToken'] === $key, 'An absent option is inserted atomically using the WordPress API');
echo '[install-wordpress-cas] ' . $checks . " checks passed (real SQLite guarded writes; MySQL BINARY operator spelling adapted)\n";
