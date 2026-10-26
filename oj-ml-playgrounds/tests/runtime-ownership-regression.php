<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use OJML\Playgrounds\Database\Installer;
use OJML\Playgrounds\Database\SchemaUpgrade;
use OJML\Playgrounds\Domain\Problem;

$GLOBALS['ownership_options'] = [Installer::DB_VERSION_OPTION => Installer::DB_VERSION, 'siteurl' => 'https://school.test'];
$GLOBALS['ownership_multisite'] = false;
function is_multisite(): bool { return $GLOBALS['ownership_multisite']; }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['ownership_options'][$name] ?? $default; }
function update_option(string $name, mixed $value, mixed $autoload = null): bool { $GLOBALS['ownership_options'][$name] = $value; return true; }
function delete_option(string $name): bool { unset($GLOBALS['ownership_options'][$name]); return true; }
$wpdb = new class {
    public string $dbname = 'school'; public string $dbhost = 'localhost'; public string $prefix = 'wp_';
    public string $last_error = ''; public int $reads = 0; public ?string $owner = 'wp_'; public bool $exists = true;
    public function prepare(string $sql, mixed ...$arguments): string { return $sql; }
    public function get_var(string $sql): ?string {
        ++$this->reads;
        return str_contains($sql, 'information_schema') ? ($this->exists ? 'InnoDB' : null)
            : ($this->owner === null ? null : json_encode(['prefix' => $this->owner], JSON_THROW_ON_ERROR));
    }
};
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { ++$checks; if (! $ok) { throw new RuntimeException($message); } };
$reject = static function (callable $work) use ($check): void {
    try { $work(); } catch (Problem) { $check(true, 'Rejected'); return; }
    $check(false, 'Unknown or foreign storage must fail closed.');
};
Installer::maybeUpgrade(); $check($wpdb->reads === 2, 'A cold check must verify both table existence and installation binding.');
Installer::maybeUpgrade(); $check($wpdb->reads === 2, 'A warm unrelated request must not issue either ML metadata query.');
$wpdb->prefix = 'other_';
$reject(Installer::maybeUpgrade(...)); $failedReads = $wpdb->reads;
$reject(Installer::maybeUpgrade(...)); $check($wpdb->reads === $failedReads, 'Foreign binding failures are throttled without permitting runtime.');
$wpdb->owner = 'other_'; $GLOBALS['ownership_options'][SchemaUpgrade::RUNTIME_OWNERSHIP_OPTION]['checked_at'] = time() - 31;
Installer::maybeUpgrade(); $check($wpdb->reads === $failedReads + 2, 'Expired failure backoff must recheck and accept only the current binding.');
$before = $wpdb->reads; $GLOBALS['ownership_options']['siteurl'] = 'https://new-install.test';
Installer::maybeUpgrade(); $check($wpdb->reads === $before + 2, 'A changed installation identity must invalidate a copied success cache.');
$before = $wpdb->reads; $GLOBALS['ownership_options'][SchemaUpgrade::RUNTIME_OWNERSHIP_OPTION]['checked_at'] = time() - 301;
Installer::maybeUpgrade(); $check($wpdb->reads === $before + 2, 'Successful binding checks must be bounded in time.');
$wpdb->owner = 'foreign_'; $reject(SchemaUpgrade::assertOwnership(...));
$check($wpdb->reads === $before + 4, 'Migration and destructive-operation checks must bypass runtime success caching.');
delete_option(SchemaUpgrade::RUNTIME_OWNERSHIP_OPTION); $wpdb->owner = null;
$reject(Installer::maybeUpgrade(...)); $before = $wpdb->reads; $reject(Installer::maybeUpgrade(...));
$check($wpdb->reads === $before, 'Missing installation binding must fail closed and use failure backoff.');
$GLOBALS['ownership_multisite'] = true; $reject(Installer::maybeUpgrade(...));
echo 'Runtime ownership regression: ' . $checks . " checks passed (metadata fixture; no database timing claimed)\n";
