<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/AcceptanceWpdb.php';
require_once dirname(__DIR__) . '/scripts/acceptance/AcceptanceSupport.php';
require_once dirname(__DIR__) . '/scripts/acceptance/WordPressDbProbe.php';

use OjMicrobit\Acceptance\ReadOnlyDatabase;
use OjMicrobit\Acceptance\WordPressDbProbe;

$testOptions['oj_microbit_schema_version'] = '5'; $testOptions['active_plugins'] = ['oj-microbit/oj-microbit.php'];
$testOptions['oj_microbit_settings'] = ['runnerToken' => 'PRIVATE_RUNNER_TOKEN', 'retentionDays' => 365];
$testOptions['cron'] = [time() + 60 => ['oj_microbit_cleanup' => ['SECRET_USER_ID_ARGS' => ['args' => [20]]]]];
$wp_version = '6.6.2'; $db = new AcceptanceWpdb();
$snapshot = $db->inner->pdo->query('SELECT id,owner_id,data_json FROM wp_oj_microbit_exercises ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$report = (new WordPressDbProbe($db))->run(['ownerId' => 10, 'exerciseId' => 6, 'assignmentId' => 101, 'learnerId' => 20, 'beforeId' => 1000, 'classIds' => [7], 'groupIds' => [7], 'iterations' => 2], '1.0.0-rc.3');
verify($report['ok'] && $report['verdict'] === 'manual_approval_pending' && $report['readOnly'], 'Successful read-only probes never claim whole-server acceptance');
verify(count($report['queryCases']) === 15 && !in_array('fail', array_column($report['queryCases'], 'status'), true), 'Actual repository site, group, legacy lesson, mixed-context, summary and history SQL pass bounded policy');
foreach ($report['queryCases'] as $case) {
    verify($case['queryCountMax'] <= 2 && !$case['largeJsonSelected'] && !$case['countQuery'] && !$case['offsetQuery'], 'Summary policy is checked for every actual SQL case');
    verify($case['timing']['iterations'] === 2 && $case['timing']['p95Ms'] >= $case['timing']['p50Ms'] && $case['plans'] !== [], 'Each case reports measured timings and real SQLite-backed EXPLAIN evidence');
}
$lessonRepo = new \OjMicrobit\Storage\WpdbLessonRepository($db);
$generalRepo = new \OjMicrobit\Storage\WpdbRepository($db);
verify(array_column($generalRepo->attempts(6, 20), 'id') === [12], 'General practice history excludes the same student and source problem from both lessons');
verify(array_column($lessonRepo->lessonAttempts(101, 20), 'id') === [10] && array_column($lessonRepo->lessonAttempts(102, 20), 'id') === [11], 'Two assignments of one public problem have separate indexed submission histories');
$first = $lessonRepo->lessonResults(101, [20, 21]); $second = $lessonRepo->lessonResults(102, [20, 21]);
verify($first[20]['result']['score'] === 50.0 && $second[20]['result']['score'] === 100.0 && !isset($first[21]) && !isset($second[21]), 'Latest summary projection keeps lesson scores distinct and does not invent a result for an unsubmitted student');
verify($report['probeScope']['assignmentRepresentativeAvailable'] && in_array('lesson-results', array_column($report['queryCases'], 'id'), true), 'The actual schema5 assignment representative and latest result SQL are probed');
$json = json_encode($report, JSON_THROW_ON_ERROR);
verify(!preg_match('/PRIVATE_|SECRET_USER_ID_ARGS|sqlite-test-adapter/', $json), 'Output contains no source, title, private conditions, token, cron arguments or raw server suffix');
verify(!preg_grep('/^(?:UPDATE|INSERT|DELETE|ALTER|CREATE|DROP|ANALYZE|START|COMMIT|ROLLBACK)/i', $db->queries), 'The complete probe issues only read-only SQL and metadata queries');
verify($snapshot === $db->inner->pdo->query('SELECT id,owner_id,data_json FROM wp_oj_microbit_exercises ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'Existing teaching rows remain byte-for-byte unchanged');
verify(!$report['cron']['executionVerified'] && count($report['manualPending']) >= 6, 'Cron execution, migrations, recovery, permissions and locking remain explicitly pending');
$pending = (new WordPressDbProbe(new AcceptanceWpdb()))->run(['iterations' => 1], '1.0.0-rc.3');
verify($pending['queryCases'][0]['status'] === 'pending' && $pending['queryCases'][0]['reason'] === 'representative_class_ids_not_provided', 'Lesson query acceptance is not invented without supplied representative class IDs');
verify($pending['queryCases'][1]['status'] === 'pending' && $pending['queryCases'][1]['reason'] === 'representative_group_ids_not_provided', 'Group query acceptance is not invented or substituted from legacy lesson IDs');
verify($report['probeScope']['groupIdCount'] === 1 && $report['probeScope']['classIdCount'] === 1, 'Legacy group and current lesson representative IDs are reported separately');
throws(fn () => (new WordPressDbProbe($db))->run(['iterations' => 11], '1.0.0-rc.3'), RuntimeException::class, 'Benchmark iterations have a small hard upper bound');
throws(fn () => (new WordPressDbProbe($db))->run(['classIds' => range(1, 101)], '1.0.0-rc.3'), RuntimeException::class, 'Membership branches cannot exceed the supported lesson projection');
throws(fn () => (new WordPressDbProbe($db))->run(['groupIds' => range(1, 101)], '1.0.0-rc.3'), RuntimeException::class, 'Group membership branches cannot exceed the supported projection');
throws(fn () => (new WordPressDbProbe($db))->run(['groupIds' => [7, 7]], '1.0.0-rc.3'), RuntimeException::class, 'Duplicate group IDs cannot inflate SQL branches');
throws(fn () => (new WordPressDbProbe($db))->run(['groupIds' => ['7', 7]], '1.0.0-rc.3'), RuntimeException::class, 'Normalized numeric duplicate group IDs are refused');
$db->inner->pdo->exec('DROP INDEX class_recent');
verify(!(new WordPressDbProbe($db))->run(['iterations' => 1], '1.0.0-rc.3')['ok'], 'Missing required indexes fail the probe rather than producing a false pass');
$db = new AcceptanceWpdb(); $db->inner->pdo->exec('DROP INDEX group_recent');
verify(!(new WordPressDbProbe($db))->run(['iterations' => 1], '1.0.0-rc.3')['ok'], 'Missing group audience index fails readiness instead of silently degrading');
$db = new AcceptanceWpdb(); $db->inner->pdo->exec('DROP INDEX assignment_class_exercise');
$db->inner->pdo->exec('CREATE INDEX assignment_class_exercise ON wp_oj_microbit_assignments(class_id,exercise_id)');
$uniqueReport = (new WordPressDbProbe($db))->run(['iterations' => 1], '1.0.0-rc.3');
verify(!$uniqueReport['ok'] && array_values(array_filter($uniqueReport['checks'], static fn ($row) => $row['id'] === 'unique-assignment-class-exercise'))[0]['status'] === 'fail', 'A same-named nonunique assignment index is not falsely accepted as duplicate protection');
$db = new AcceptanceWpdb(); $db->inner->pdo->exec('DROP INDEX scope_records');
verify(!(new WordPressDbProbe($db))->run(['iterations' => 1], '1.0.0-rc.3')['ok'], 'Missing lesson attempt scope index fails readiness without silently scanning history');
$db = new AcceptanceWpdb(); $db->inner->engine = 'MyISAM';
verify(!(new WordPressDbProbe($db))->run(['iterations' => 1], '1.0.0-rc.3')['ok'], 'Nontransactional audience engines fail readiness without any DDL repair');
$proxy = new ReadOnlyDatabase(new AcceptanceWpdb()); $proxy->summaryPolicy = true;
foreach (['SELECT result_json FROM wp_oj_microbit_attempts LIMIT 1', 'SELECT * FROM wp_oj_microbit_attempts LIMIT 1', 'SELECT COUNT(*) FROM wp_oj_microbit_attempts LIMIT 1', 'SELECT id FROM wp_oj_microbit_attempts LIMIT 1 OFFSET 0', 'SELECT id FROM wp_oj_microbit_attempts'] as $sql) {
    throws(fn () => $proxy->get_results($sql, ARRAY_A), RuntimeException::class, 'Unbounded or payload/count/offset queries are refused before DB execution');
}
$proxy->get_results('SELECT id FROM wp_oj_microbit_attempts LIMIT 1', ARRAY_A); $proxy->get_results('SELECT id FROM wp_oj_microbit_attempts LIMIT 1', ARRAY_A);
throws(fn () => $proxy->get_results('SELECT id FROM wp_oj_microbit_attempts LIMIT 1', ARRAY_A), RuntimeException::class, 'N+1 growth is stopped before a third summary statement executes');
foreach (['DELETE FROM t', 'EXPLAIN ANALYZE SELECT id FROM t', 'SELECT GET_LOCK(1,0)', 'SELECT id INTO OUTFILE \'private\' FROM t'] as $sql) { throws(fn () => ReadOnlyDatabase::assertReadOnly($sql), RuntimeException::class, 'Mutations, lock acquisition, analyze and file outputs are forbidden'); }
echo '[acceptance-db] ' . $testChecks . " checks passed (real SQLite query execution and plans; not live WordPress/MySQL acceptance)\n";
