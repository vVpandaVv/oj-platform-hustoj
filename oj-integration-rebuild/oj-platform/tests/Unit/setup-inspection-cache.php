<?php
declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Setup\SetupMode;
use CodingPanda\OjPlatform\Kernel\Setup\SetupSnapshot;
use CodingPanda\OjPlatform\Kernel\Setup\WordPress\WordPressSetupInspectionCache;

require_once dirname(__DIR__, 2) . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register(dirname(__DIR__, 2));
$records = [];
function get_transient(string $key): mixed { return $GLOBALS['records'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl): bool { $GLOBALS['records'][$key] = $value; return true; }
function delete_transient(string $key): bool { unset($GLOBALS['records'][$key]); return true; }
function get_option(string $key, mixed $default = null): mixed { return $key === 'siteurl' ? 'https://fixture.invalid' : $default; }
$GLOBALS['wpdb'] = (object) ['dbname' => 'wordpress', 'dbhost' => 'local', 'prefix' => 'wp_'];
$checks = 0;
function cacheCheck(bool $value, string $message): void {
    ++$GLOBALS['checks'];
    if (! $value) { throw new RuntimeException($message); }
}
$cache = new WordPressSetupInspectionCache('2.5.0');
$snapshot = new SetupSnapshot(SetupMode::AlreadyInstalled, str_repeat('a', 32), 'complete', ['submission_contexts'], [], [], [], 1, 1, null, [], []);
$cache->write('schema-a', $snapshot);
cacheCheck($cache->read('schema-a')?->existingTables() === ['submission_contexts'], 'A successful snapshot must round-trip.');
cacheCheck($cache->read('schema-b') === null, 'A schema mismatch must require a fresh inspection.');
cacheCheck((new WordPressSetupInspectionCache('2.5.1'))->read('schema-a') === null, 'A platform update must invalidate the cache.');
$GLOBALS['wpdb']->prefix = 'other_';
cacheCheck($cache->read('schema-a') === null, 'An installation prefix change must invalidate the cache.');
$GLOBALS['wpdb']->prefix = 'wp_';
$key = 'oj_platform_setup_inspection';
$good = $records[$key];
foreach ([['tables' => []], ['targets' => 0], ['schemas' => 0], ['status' => 'interrupted'],
    ['tables' => ['invalid-name']], ['installation' => 'bad'], ['expires_at' => time() - 1],
    ['checked_at' => time() + 100], ['expires_at' => time() + 600]] as $change) {
    $records[$key] = array_replace($good, $change);
    cacheCheck($cache->read('schema-a') === null, 'Malformed or stale snapshots must fail closed.');
}
$records[$key] = $good;
$cache->clear();
cacheCheck($cache->read('schema-a') === null, 'Manual inspection must be able to discard the cached snapshot.');
echo 'Setup inspection cache: ' . $checks . " checks passed\n";
