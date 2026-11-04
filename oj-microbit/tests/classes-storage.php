<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/SqliteWpdb.php';

use OjMicrobit\Storage\WpdbRepository;
use OjMicrobit\Support\OperationError;

$db = new SqliteWpdb(); $repo = new WpdbRepository($db);
$db->pdo->beginTransaction();
$insert = $db->pdo->prepare('INSERT INTO wp_oj_microbit_exercises (id,owner_id,title,status,revision,archived,data_json,created_at,updated_at,audience_mode) VALUES (?,10,?,\'published\',1,0,?,\'2026-10-28\',\'2026-10-28\',?)');
$link = $db->pdo->prepare("INSERT INTO wp_oj_microbit_exercise_classes VALUES (?,?,'published',0)");
for ($id = 1; $id <= 20000; $id++) {
    $site = $id % 10 === 0;
    $insert->execute([$id, '학급 과제 ' . $id, '{invalid large teaching JSON', $site ? 'site' : 'classes']);
    if (!$site) { $link->execute([$id, $id % 2 === 0 ? 7 : 8]); if ($id % 3 === 0) { $link->execute([$id, 9]); } }
}
$db->pdo->commit();
$db->queries = []; $site = $repo->learnerExercises([], 0, 21);
verify(count($site) === 21 && $site[0]['id'] === 20000 && $site[1]['id'] === 19990, 'Unavailable class scope reads only site rows directly from its covering index');
verify(count($db->queries) === 1 && !preg_match('/json|COUNT\(|OFFSET/i', $db->queries[0]), 'Site fallback is one bounded metadata query without payloads, counts or offsets');
$sitePlan = implode('\n', array_column($db->pdo->query('EXPLAIN QUERY PLAN ' . $db->queries[0])->fetchAll(PDO::FETCH_ASSOC), 'detail'));
verify(str_contains($sitePlan, 'site_recent') && !str_contains($sitePlan, 'TEMP B-TREE'), 'Site filter and key order share the site_recent index');
$db->queries = []; $page = $repo->learnerExercises([7, 9], 0, 21);
$expected = [];
for ($id = 20000; $id > 0 && count($expected) < 21; $id--) { if ($id % 10 === 0 || $id % 2 === 0 || $id % 3 === 0) { $expected[] = $id; } }
verify(array_column($page, 'id') === $expected && count(array_unique(array_column($page, 'id'))) === 21, 'Indexed class branches deduplicate assignments before the global page boundary');
verify(count($db->queries) === 2 && !preg_match('/data_json|COUNT\(|OFFSET/i', implode('\n', $db->queries)), 'A class page uses one bounded key UNION and one batch audience projection, with no N+1 or teaching JSON');
$plan = implode('\n', array_column($db->pdo->query('EXPLAIN QUERY PLAN ' . $db->queries[0])->fetchAll(PDO::FETCH_ASSOC), 'detail'));
verify(str_contains($plan, 'site_recent') && substr_count($plan, 'class_recent') === 2, 'Every class key branch uses class/status/archive/id index prefixes');
$projectedClasses = count(array_filter($page, static fn ($item) => $item['audience']['mode'] === 'classes'));
verify(substr_count($db->queries[0], 'LIMIT 21') === 4 && str_contains($db->queries[1], 'LIMIT ' . ($projectedClasses * 50 + 1)), 'Candidate work is bounded to three page-sized branches plus the final page, with capped metadata hydration');
$db->queries = []; $all = $repo->learnerExercises(range(1, 100), 0, 21);
verify(count($all) === 21 && count($db->queries) === 2 && substr_count($db->queries[0], 'class_candidates_') === 100, 'One hundred current class IDs stay in one bounded SQL request rather than per-exercise lookups');
throws(fn () => $repo->learnerExercises(range(1, 101)), InvalidArgumentException::class, 'Oversized membership projection rejected before SQL');
throws(fn () => $repo->learnerExercises([7, 7]), InvalidArgumentException::class, 'Duplicate membership IDs rejected');
throws(fn () => $repo->learnerExercises(['7']), InvalidArgumentException::class, 'String membership IDs cannot forge the SQL class scope');
$seen = []; $before = 0;
for ($pageNo = 0; $pageNo < 7; $pageNo++) {
    $items = $repo->learnerExercises([7, 9], $before, 50);
    foreach ($items as $item) { $seen[] = $item['id']; }
    $before = end($items)['id'];
    if ($pageNo === 0) { $db->pdo->exec("INSERT INTO wp_oj_microbit_exercises (id,owner_id,title,status,revision,archived,data_json,created_at,updated_at,audience_mode) VALUES (30000,10,'new','published',1,0,'{}','2026-10-28','2026-10-28','site')"); }
}
verify(count($seen) === 350 && count(array_unique($seen)) === 350 && !in_array(30000, $seen, true), 'Class keyset pages continue beyond one hundred without duplicate rows after a newer insertion');
$db->queries = []; $summary = $repo->exerciseSummary(19998);
verify($summary['audience'] === ['mode' => 'classes', 'classIds' => [7, 9]] && count($db->queries) === 1 && !str_contains(implode('', $db->queries), 'data_json'), 'One statement authorizes from a consistent audience/revision without reading invalid stored source JSON');

// Real transaction rollback tests, including failures after the parent CAS.
$db = new SqliteWpdb(); $repo = new WpdbRepository($db); $input = fixtureExercise(); $input['audience'] = ['mode' => 'classes', 'classIds' => [7, 8]];
$saved = $repo->saveExercise($input, 10, null, 0);
verify($saved['revision'] === 1 && $saved['audience']['classIds'] === [7, 8], 'Parent and class links commit together on first save');
$changed = $input; $changed['title'] = 'changed'; $changed['audience']['classIds'] = [9];
foreach (['INSERT INTO `wp_oj_microbit_exercise_classes`', 'COMMIT'] as $failure) {
    $db->fail = $failure;
    throws(fn () => $repo->saveExercise($changed, 10, 1, 1), RuntimeException::class, 'A class write or commit failure aborts the entire update');
    $db->fail = null;
    $unchanged = $repo->exercise(1);
    verify($unchanged['revision'] === 1 && $unchanged['title'] === $input['title'] && $unchanged['audience']['classIds'] === [7, 8], 'Rollback preserves source, revision and the exact previous audience');
    verify(count($repo->learnerExercises([7])) === 1 && $repo->learnerExercises([9]) === [], 'Failed updates never open the exercise to a new class');
}
$db->fail = 'INSERT INTO `wp_oj_microbit_exercise_classes`';
throws(fn () => $repo->saveExercise($changed, 10, null, 0), RuntimeException::class, 'First-save link failure rolls back its inserted parent');
$db->fail = null;
verify((int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercises')->fetchColumn() === 1 && (int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 2, 'Failed first save leaves neither a public parent nor orphan links');
throws(fn () => $repo->saveExercise($changed, 10, 1, 0), OperationError::class, 'Stale class CAS is an explicit conflict');
verify($repo->exerciseSummary(1)['audience']['classIds'] === [7, 8], 'A stale writer cannot replace audience links');
$db->fail = 'DELETE FROM `wp_oj_microbit_exercise_classes`';
throws(fn () => $repo->archiveExercise(1, 1), RuntimeException::class, 'Failed archive link deletion rolls back parent archival');
$db->fail = null;
verify($repo->exercise(1)['revision'] === 1 && count($repo->learnerExercises([7])) === 1, 'Archive failure preserves the original live version and audience');
$db->engine = 'MyISAM';
throws(fn () => $repo->saveExercise($changed, 10, 1, 1), OperationError::class, 'Nontransactional engines are rejected before a scope write');
$db->engine = 'InnoDB';
verify($repo->exerciseSummary(1)['revision'] === 1, 'Rejected nontransactional scope writes cannot advance CAS');
$site = $input; $site['audience'] = ['mode' => 'site', 'classIds' => []];
$saved = $repo->saveExercise($site, 10, 1, 1);
verify($saved['revision'] === 2 && $saved['audience']['mode'] === 'site' && (int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 0, 'Changing to explicitly reviewed site scope removes all previous class links atomically');
$saved = $repo->saveExercise($input, 10, 1, 2); $repo->archiveExercise(1, 3);
verify($repo->learnerExercises([7]) === [] && (int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 0, 'Archiving removes audience projections and prevents all later student lookup');
echo '[classes-storage] ' . $testChecks . " checks passed (20,000 rows, indexed bounded class UNION and real SQLite rollback; MySQL plans/row locks require deployment acceptance)\n";
