<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiterStorageLock;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAttemptLimiterStorageLock;
use CodingPanda\OjPlatform\Shared\Clock\SystemClock;

require_once dirname(__DIR__, 2) . '/src/Kernel/Autoloader.php';
Autoloader::register(dirname(__DIR__, 2));
$checks = 0;
function rateCheck(bool $condition, string $label): void { ++$GLOBALS['checks']; if (! $condition) { throw new RuntimeException($label); } }
function rateThrows(callable $operation, string $label): void { try { $operation(); } catch (RuntimeException) { rateCheck(true, $label); return; } rateCheck(false, $label); }
$GLOBALS['rateValues'] = []; $GLOBALS['rateOptions'] = []; $GLOBALS['externalRateCache'] = false;
function wp_using_ext_object_cache(): bool { return $GLOBALS['externalRateCache']; }
function wp_cache_delete(string $key, string $group): bool { return true; }
function wp_salt(string $scheme): string { return 'attempt-limiter-unit'; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['rateOptions'][$key] ?? $default; }
function add_option(string $key, mixed $value, string $deprecated = '', bool $autoload = false): bool {
    if (isset($GLOBALS['rateOptions'][$key]) || (($GLOBALS['failRateTimeout'] ?? false) && str_starts_with($key, '_transient_timeout_'))) { return false; }
    $GLOBALS['rateOptions'][$key] = $value; return true;
}
function update_option(string $key, mixed $value, bool $autoload = false): bool {
    if (($GLOBALS['failRateIndex'] ?? false) && $key === 'oj_platform_rate_index') { return false; }
    $GLOBALS['rateOptions'][$key] = $value; return true;
}
function delete_option(string $key): bool { $found = isset($GLOBALS['rateOptions'][$key]); unset($GLOBALS['rateOptions'][$key]); return $found; }
function get_transient(string $key): mixed {
    if ($GLOBALS['failRateRead'] ?? false) { $GLOBALS['wpdb']->last_error = 'injected read failure'; return false; }
    $timeout = $GLOBALS['rateOptions']['_transient_timeout_' . $key] ?? null;
    if (! wp_using_ext_object_cache() && $timeout !== null && (int) $timeout < time()) { unset($GLOBALS['rateValues'][$key], $GLOBALS['rateOptions']['_transient_timeout_' . $key]); }
    return $GLOBALS['rateValues'][$key] ?? false;
}
function set_transient(string $key, mixed $value, int $expiration): bool {
    if ($GLOBALS['failRateValue'] ?? false) { return false; }
    // Match the observed core partial-success shape: a value can be saved
    // even when adding its separate timeout row failed.
    $GLOBALS['rateValues'][$key] = $value;
    if (! wp_using_ext_object_cache() && ! ($GLOBALS['failRateTimeout'] ?? false)) { $GLOBALS['rateOptions']['_transient_timeout_' . $key] = time() + $expiration; }
    return ! ($GLOBALS['rateUnchanged'] ?? false);
}
function delete_transient(string $key): bool { $found = isset($GLOBALS['rateValues'][$key]); unset($GLOBALS['rateValues'][$key], $GLOBALS['rateOptions']['_transient_timeout_' . $key]); return $found; }
$lock = new class implements AttemptLimiterStorageLock {
    public int $entered = 0; public int $released = 0;
    public function synchronized(callable $operation): mixed { ++$this->entered; try { return $operation(); } finally { ++$this->released; } }
};
$limiter = new WordPressAttemptLimiter(new SystemClock(), $lock);
rateCheck($limiter->consume('login', 'learner', 2, 60), 'The first attempt is allowed.');
rateCheck($lock->entered === 1 && $lock->released === 1, 'A complete legacy counter operation uses the injected storage lock.');
$key = array_key_first($GLOBALS['rateValues']); $timeoutKey = '_transient_timeout_' . $key;
rateCheck($limiter->consume('login', 'learner', 2, 60), 'The second attempt is allowed.');
$deadline = get_option($timeoutKey);
rateCheck(! $limiter->consume('login', 'learner', 2, 60) && get_option($timeoutKey) === $deadline, 'A denied ordinary attempt does not extend an existing expiry.');
unset($GLOBALS['rateOptions'][$timeoutKey]);
rateCheck(! $limiter->consume('login', 'learner', 2, 60), 'An orphan at the limit remains denied while repairing its expiry.');
rateCheck($GLOBALS['rateValues'][$key]['count'] === 2 && (int) get_option($timeoutKey) > time() && (int) get_option($timeoutKey) <= time() + 60, 'Orphan repair preserves the exact count and creates a bounded timeout.');
unset($GLOBALS['rateOptions'][$timeoutKey]); $GLOBALS['failRateTimeout'] = true;
rateThrows(static fn () => $limiter->consume('login', 'learner', 2, 60), 'A failed timeout repair fails closed.');
rateCheck($GLOBALS['rateValues'][$key]['count'] === 2 && ! array_key_exists($timeoutKey, $GLOBALS['rateOptions']), 'A failed repair never deletes or resets the previous counter.');
$GLOBALS['failRateTimeout'] = false;
rateCheck(! $limiter->consume('login', 'learner', 2, 60), 'The next request can repair an orphan without bypassing its limit.');
$GLOBALS['wpdb'] = new stdClass(); $GLOBALS['failRateRead'] = true;
rateThrows(static fn () => $limiter->consume('login', 'learner', 2, 60), 'A missing-looking counter read with a database error fails closed.');
rateCheck($GLOBALS['rateValues'][$key]['count'] === 2, 'A failed database read does not reset a full existing budget.');
$GLOBALS['failRateRead'] = false; unset($GLOBALS['wpdb']);
rateCheck($limiter->consumeFixedWindow('messaging_send', 'sender', 1, 600), 'A fixed-window request uses the same protected storage.');
$fixedKey = array_key_last($GLOBALS['rateValues']); $reset = $GLOBALS['rateValues'][$fixedKey]['reset_at'];
unset($GLOBALS['rateOptions']['_transient_timeout_' . $fixedKey]);
rateCheck(! $limiter->consumeFixedWindow('messaging_send', 'sender', 1, 600), 'An orphan fixed-window request at the limit is repaired and denied.');
rateCheck($GLOBALS['rateValues'][$fixedKey] === ['count' => 1, 'reset_at' => $reset]
    && (int) get_option('_transient_timeout_' . $fixedKey) <= $reset, 'Fixed-window repair preserves both the count and original reset deadline.');
$GLOBALS['failRateTimeout'] = true;
rateThrows(static fn () => $limiter->consume('login', 'partial-write', 1, 60), 'A value-only transient success cannot authorize an attempt.');
$partialKey = array_key_last($GLOBALS['rateValues']); $GLOBALS['failRateTimeout'] = false;
rateCheck(! $limiter->consume('login', 'partial-write', 1, 60) && $GLOBALS['rateValues'][$partialKey]['count'] === 1, 'A partial write retains its count and later repairs instead of restarting the budget.');
$GLOBALS['failRateIndex'] = true;
rateThrows(static fn () => $limiter->consume('login', 'failed-index', 2, 60), 'An untracked index write fails closed.');
$GLOBALS['failRateIndex'] = false; $GLOBALS['failRateValue'] = true;
rateThrows(static fn () => $limiter->consume('login', 'failed-value', 2, 60), 'A counter write failure fails closed.');
$GLOBALS['failRateValue'] = false; $GLOBALS['rateUnchanged'] = true;
rateCheck($limiter->consume('login', 'unchanged', 2, 60), 'A verified unchanged WordPress result remains valid.');
$GLOBALS['rateUnchanged'] = false;
$beforeReset = $lock->entered; $cleared = $limiter->resetAll();
rateCheck($lock->entered === $beforeReset + 1 && $lock->entered === $lock->released, 'Reset and every exceptional operation release the same storage lock.');
rateCheck($cleared > 0 && get_option('oj_platform_rate_index') === false, 'Reset removes the recorded counters and index under that lock.');
$GLOBALS['externalRateCache'] = true;
rateCheck($limiter->consume('login', 'external-cache', 1, 60), 'External object-cache storage remains supported.');
rateCheck(! $limiter->consume('login', 'external-cache', 1, 60), 'External object-cache counters still enforce the limit.');
$externalKey = array_key_last($GLOBALS['rateValues']);
rateCheck(! array_key_exists('_transient_timeout_' . $externalKey, $GLOBALS['rateOptions']), 'No SQL timeout row is invented for an external cache.');
$GLOBALS['externalRateCache'] = false;

$db = new class {
    public string $last_error = '';
    public string $options = 'wp_options'; public string $databaseName = 'database_one'; public mixed $acquire = '1'; public mixed $release = '1'; public array $lockNames = []; public int $releaseCalls = 0;
    public function prepare(string $query, string $value): string { return str_replace('%s', "'" . $value . "'", $query); }
    public function get_var(string $query): mixed {
        if ($query === 'SELECT DATABASE()') { return $this->databaseName; }
        if (str_contains($query, 'GET_LOCK')) { preg_match("/'([^']+)'/", $query, $match); $this->lockNames[] = $match[1]; return $this->acquire; }
        if (str_contains($query, 'RELEASE_LOCK')) { ++$this->releaseCalls; return $this->release; }
        throw new LogicException('Unexpected lock query.');
    }
};
$GLOBALS['wpdb'] = $db; $nativeLock = new WordPressAttemptLimiterStorageLock();
rateCheck($nativeLock->synchronized(static fn (): string => 'value') === 'value' && $db->releaseCalls === 1, 'The native lock returns the callback result and releases once.');
rateThrows(static fn () => $nativeLock->synchronized(static function (): void { throw new RuntimeException('callback'); }), 'Callback failures leave the native lock through finally.');
rateCheck($db->releaseCalls === 2, 'An exceptional callback still releases its acquired lock.');
$firstName = $db->lockNames[0]; $db->databaseName = 'database_two'; $nativeLock->synchronized(static fn () => null);
rateCheck($db->lockNames[2] !== $firstName, 'Different application databases use different named locks.');
$db->databaseName = 'database_one'; $db->options = 'other_options'; $nativeLock->synchronized(static fn () => null);
rateCheck($db->lockNames[3] !== $firstName && strlen($firstName) <= 64, 'Different WordPress prefixes use distinct bounded lock names.');
$db->acquire = '0'; $callbackCalled = false; $beforeReleases = $db->releaseCalls;
rateThrows(static fn () => $nativeLock->synchronized(static function () use (&$callbackCalled): void { $callbackCalled = true; }), 'A lock acquisition timeout fails closed.');
rateCheck(! $callbackCalled && $db->releaseCalls === $beforeReleases, 'An unacquired lock neither executes the callback nor releases somebody else\'s lock.');
$db->acquire = '1'; $db->release = null;
rateThrows(static fn () => $nativeLock->synchronized(static fn () => true), 'A lost lock or failed release cannot return successful authorization.');
unset($GLOBALS['wpdb']);
rateThrows(static fn () => (new WordPressAttemptLimiter())->consume('login', 'missing-database', 1, 60), 'The real default never silently bypasses locking when WordPress storage is unavailable.');
echo 'Attempt limiter concurrency: ' . $checks . " checks passed\n";
