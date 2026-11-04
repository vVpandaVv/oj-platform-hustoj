<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/SqliteWpdb.php';

use OjMicrobit\Storage\Installer;
use OjMicrobit\Storage\WpdbRepository;

define('ABSPATH', __DIR__ . '/fixtures/wordpress/');
$ddl = []; $afterDelta = null;
// This adapter tests the real install postconditions over SQLite ALTER/INDEX.
// It does not claim to execute WordPress dbDelta or the MySQL DDL parser.
function dbDelta(string $sql): void
{
    $GLOBALS['ddl'][] = $sql; $GLOBALS['wpdb']->upgrade();
    if (is_callable($GLOBALS['afterDelta'])) { ($GLOBALS['afterDelta'])($GLOBALS['wpdb']); }
}

$wpdb = new SqliteWpdb(false); $records = new WpdbRepository($wpdb);
$wpdb->pdo->exec('CREATE INDEX custom_local_index ON wp_oj_microbit_exercises(title)');
$wpdb->pdo->exec("INSERT INTO wp_oj_microbit_exercises VALUES (7,10,'오래된 과제','published',4,0,'{}','2020-01-01','2026-10-28')");
$oldResult = json_encode(['passed' => true, 'score' => 72, 'trace' => [str_repeat('X', 1024 * 1024)]], JSON_THROW_ON_ERROR);
$insert = $wpdb->pdo->prepare("INSERT INTO wp_oj_microbit_attempts VALUES (1,7,4,20,'{}',?,'2020-01-01')"); $insert->execute([$oldResult]);
$testOptions['oj_microbit_schema_version'] = '1'; $wpdb->queries = [];
verify(Installer::install() && get_option('oj_microbit_schema_version') === Installer::SCHEMA_VERSION, 'Version one upgrades only after required columns and indexes exist');
verify(count($ddl) === 9 && !preg_grep('/UPDATE|SELECT .*json/i', $wpdb->queries), 'Schema installation performs no result JSON hydration or entire-table summary backfill');
verify($wpdb->pdo->query('SELECT result_json FROM wp_oj_microbit_attempts WHERE id=1')->fetchColumn() === $oldResult && $records->attempts(7, 20)[0]['summaryPending'], 'Migration preserves the original MiB result and defers its summary to maintenance');
verify($records->exercise(7)['revision'] === 4, 'Existing ownership and exercise revisions survive upgrade');
verify((int) $wpdb->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='custom_local_index'")->fetchColumn() === 1, 'Migration preserves unrelated local indexes');
foreach (Installer::indexes() as $table => $indexes) {
    foreach ($indexes as $key => $columns) {
        $rows = $wpdb->get_results('SHOW INDEX FROM `wp_oj_microbit_' . $table . '`', ARRAY_A);
        $actual = [];
        foreach ($rows as $row) { if ($row['Key_name'] === $key) { $actual[(int) $row['Seq_in_index']] = $row['Column_name']; } }
        ksort($actual);
        verify(array_values($actual) === $columns, 'Verified installed index column order: ' . $table . '.' . $key);
        if ($key !== 'PRIMARY') { verify(str_contains(implode('\n', $ddl), 'KEY ' . $key . ' (' . implode(',', $columns) . ')'), 'MySQL DDL declares the verified index: ' . $key); }
    }
}
$testOptions['oj_microbit_schema_version'] = '1';
$afterDelta = static fn (SqliteWpdb $db) => $db->pdo->exec('DROP INDEX IF EXISTS exercise_recent');
verify(!Installer::install() && get_option('oj_microbit_schema_version') === '1', 'Missing critical teacher history index prevents marking the schema ready');
$afterDelta = null; verify(Installer::install(), 'Failed index creation can be retried without replacing records');
$testOptions['oj_microbit_schema_version'] = '1';
$afterDelta = static function (SqliteWpdb $db): void { $db->pdo->exec('DROP INDEX IF EXISTS exercise_recent'); $db->pdo->exec('CREATE INDEX exercise_recent ON wp_oj_microbit_attempts(user_id,id)'); };
verify(!Installer::install() && get_option('oj_microbit_schema_version') === '1', 'An index with the right name but wrong column order cannot pass readiness');
$afterDelta = null; $wpdb->pdo->exec('DROP INDEX exercise_recent');
verify(Installer::install(), 'Correct index order restores schema readiness');
$testOptions['oj_microbit_schema_version'] = '1'; $wpdb->fail = 'SHOW COLUMNS';
verify(!Installer::install() && get_option('oj_microbit_schema_version') === '1', 'Database introspection failure never reports a completed upgrade');
$wpdb->fail = 'SHOW INDEX';
verify(!Installer::install() && get_option('oj_microbit_schema_version') === '1', 'Index introspection failure never reports a completed upgrade');
$wpdb->fail = null;
$testOptions['oj_microbit_schema_version'] = '2'; $wpdb->engine = 'MyISAM';
verify(!Installer::install() && get_option('oj_microbit_schema_version') === '2', 'A legacy nontransactional audience table cannot be marked ready without an explicit engine migration');
$wpdb->engine = 'InnoDB'; verify(Installer::install(), 'InnoDB audience storage upgrades from schema two with old site records unchanged');
verify($records->exerciseSummary(7)['audience'] === ['mode' => 'site', 'classIds' => []], 'Rows without a previous audience migrate to indexed site scope without decoding teaching JSON');
$legacy = fixtureExercise(); $legacy['audience'] = ['mode' => 'classes', 'classIds' => [7]];
$legacySaved = $records->saveExercise($legacy, 10, null, 0);
$wpdb->pdo->exec('DROP TABLE wp_oj_microbit_exercise_groups'); $testOptions['oj_microbit_schema_version'] = '3';
$wpdb->queries = []; verify(Installer::install() && get_option('oj_microbit_schema_version') === '5', 'Schema three gains a separate group link table before marking schema five ready');
verify($records->exercise($legacySaved['id'])['revision'] === 1 && $records->exerciseSummary($legacySaved['id'])['audience'] === ['mode' => 'classes', 'classIds' => [7]], 'Schema-four upgrade preserves the previous lesson audience, source and CAS version');
verify((int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_groups')->fetchColumn() === 0, 'Lesson IDs are never automatically copied or reinterpreted as group IDs');
$wpdb->pdo->exec('DROP INDEX assignment_class_exercise'); $wpdb->pdo->exec('CREATE INDEX assignment_class_exercise ON wp_oj_microbit_assignments(class_id,exercise_id)'); $testOptions['oj_microbit_schema_version'] = '4';
verify(!Installer::install() && get_option('oj_microbit_schema_version') === '4', 'A nonunique same-named class/problem index cannot pass assignment integrity readiness');
$wpdb->pdo->exec('DROP INDEX assignment_class_exercise'); verify(Installer::install(), 'Creating the required unique assignment index restores schema readiness');
echo '[installer] ' . $testChecks . " checks passed (v1 data preserved, bounded deferred summaries, DDL shape and real SQLite indexes; dbDelta/MySQL execution requires deployment acceptance)\n";
