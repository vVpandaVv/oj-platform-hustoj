<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
define('WP_UNINSTALL_PLUGIN', true);
$wpdb = new class {
    public string $prefix = 'wp_'; public ?string $fail = null; public array $queries = []; public PDO $pdo;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        foreach (['attempts', 'projects', 'exercise_classes', 'exercise_groups', 'exercises', 'limits'] as $name) {
            $this->pdo->exec('CREATE TABLE wp_oj_microbit_' . $name . '(id INTEGER PRIMARY KEY)');
            $this->pdo->exec('INSERT INTO wp_oj_microbit_' . $name . ' VALUES(1)');
        }
    }
    public function query(string $sql): int|false {
        $this->queries[] = $sql;
        if ($this->fail !== null && str_contains($sql, $this->fail)) { return false; }
        return $this->pdo->exec($sql);
    }
};
$source = dirname(__DIR__) . '/uninstall.php';
$testOptions['oj_microbit_settings'] = ['deleteOnUninstall' => false]; require $source;
verify($wpdb->queries === [], 'Default uninstall retains all personal data and class assignments');
$testOptions['oj_microbit_settings'] = ['deleteOnUninstall' => true]; $testOptions['oj_microbit_schema_version'] = '3';
$wpdb->fail = 'exercise_classes';
throws(static function () use ($source): void { require $source; }, RuntimeException::class, 'A DDL failure stops uninstall instead of reporting successful deletion');
verify(isset($testOptions['oj_microbit_settings'], $testOptions['oj_microbit_schema_version'], $testOptions['oj_microbit_uninstall_error']), 'Failed deletion preserves the opt-in and diagnostic state for retry');
verify((int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 1, 'The failed class table remains available for an explicit retry');
$wpdb->fail = 'exercise_groups';
throws(static function () use ($source): void { require $source; }, RuntimeException::class, 'A group-table DDL failure preserves retry diagnostics too');
verify((int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_groups')->fetchColumn() === 1 && isset($testOptions['oj_microbit_uninstall_error']), 'Group assignment deletion failure leaves its records and diagnostic state intact');
$wpdb->fail = null; require $source;
verify((int) $wpdb->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'wp_oj_microbit_%'")->fetchColumn() === 0, 'A retry removes all remaining plugin tables including class assignments');
verify(!isset($testOptions['oj_microbit_settings']) && !isset($testOptions['oj_microbit_schema_version']) && !isset($testOptions['oj_microbit_uninstall_error']), 'Only completed table deletion clears settings and diagnostics');
echo '[uninstall] ' . $testChecks . " checks passed (actual SQLite DDL and injected failure; no live WordPress)\n";
