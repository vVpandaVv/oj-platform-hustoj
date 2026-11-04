<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/SqliteWpdb.php';

use OjMicrobit\Storage\WpdbRepository;
use OjMicrobit\Support\OperationError;

$db = new SqliteWpdb(); $repo = new WpdbRepository($db);
$db->pdo->beginTransaction();
$parent = $db->pdo->prepare("INSERT INTO wp_oj_microbit_exercises (id,owner_id,title,status,revision,archived,data_json,created_at,updated_at,audience_mode) VALUES (?,10,?,'published',1,0,?,'2026-11-03','2026-11-03',?)");
$group = $db->pdo->prepare("INSERT INTO wp_oj_microbit_exercise_groups VALUES (?,?,'published',0)");
$lesson = $db->pdo->prepare("INSERT INTO wp_oj_microbit_exercise_classes VALUES (?,?,'published',0)");
for ($id = 1; $id <= 20000; $id++) {
    $mode = $id % 10 === 0 ? 'site' : ($id % 2 === 0 ? 'groups' : 'classes');
    $parent->execute([$id, '배포 문제 ' . $id, '{invalid teaching JSON', $mode]);
    if ($mode === 'groups') { $group->execute([$id, 7]); if ($id % 3 === 0) { $group->execute([$id, 9]); } }
    if ($mode === 'classes') { $lesson->execute([$id, 7]); }
}
$db->pdo->commit();
$db->queries = []; $page = $repo->learnerExercises([], 0, 21, [7, 9]);
$expected = [];
for ($id = 20000; $id > 0 && count($expected) < 21; $id--) { if ($id % 2 === 0) { $expected[] = $id; } }
verify(array_column($page, 'id') === $expected, 'Group assignment includes site and group problems, excluding identical lesson IDs');
verify(count($db->queries) === 2 && !preg_match('/data_json|COUNT\(|OFFSET/i', implode("\n", $db->queries)), 'Group page uses two bounded metadata statements without source hydration or N+1');
$plan = implode("\n", array_column($db->pdo->query('EXPLAIN QUERY PLAN ' . $db->queries[0])->fetchAll(PDO::FETCH_ASSOC), 'detail'));
verify(substr_count($plan, 'group_recent') === 2 && str_contains($plan, 'site_recent'), 'Group candidate branches use covering group/status/archive/exercise indexes');
verify(count(array_unique(array_column($page, 'id'))) === 21 && substr_count($db->queries[0], 'LIMIT 21') === 4, 'Multiple group matches deduplicate before page boundary with page-sized branch work');
$db->queries = []; $mixed = $repo->learnerExercises([7], 0, 21, [7]);
verify(count($mixed) === 21 && $mixed[0]['id'] === 20000 && $mixed[1]['id'] === 19999 && count($db->queries) === 2, 'Mixed legacy lesson and group page shares one audience projection statement');
verify(str_contains($db->queries[1], 'UNION ALL') && str_contains($db->queries[1], 'exercise_groups') && str_contains($db->queries[1], 'exercise_classes'), 'Both namespaces hydrate in a single bounded UNION rather than per-row queries');
$db->queries = []; $repo->learnerExercises(range(1, 100), 0, 51, range(1, 100));
verify(count($db->queries) === 2 && substr_count($db->queries[0], 'group_candidates_') === 100 && substr_count($db->queries[0], 'class_candidates_') === 100 && substr_count($db->queries[0], 'LIMIT 51') === 202, 'Maximum current membership bounds candidates to 201 index pages plus one final page');
foreach ([range(1, 101), [7, 7], ['7'], [0]] as $invalid) {
    throws(fn () => $repo->learnerExercises([], 0, 21, $invalid), InvalidArgumentException::class, 'Invalid group scope is rejected before SQL construction');
}
$seen = []; $before = 0;
for ($number = 0; $number < 7; $number++) {
    $rows = $repo->learnerExercises([], $before, 50, [7]);
    foreach ($rows as $row) { $seen[] = $row['id']; }
    $before = end($rows)['id'];
    if ($number === 0) { $parent->execute([30000, 'new site problem', '{}', 'site']); }
}
verify(count($seen) === 350 && count(array_unique($seen)) === 350 && !in_array(30000, $seen, true), 'Group keyset pages stay stable across newer insertions');
$db->queries = []; $summary = $repo->exerciseSummary(19998);
verify($summary['audience'] === ['mode' => 'groups', 'groupIds' => [7, 9]] && count($db->queries) === 1 && !str_contains($db->queries[0], 'data_json'), 'Direct group authorization reads one consistent metadata statement');
$group->execute([19999, 55]); $lesson->execute([19998, 55]);
verify($repo->learnerExercises([], 0, 21, [55]) === [$repo->exerciseSummary(30000), $repo->exerciseSummary(20000), ...array_map(fn ($id) => $repo->exerciseSummary($id), range(19990, 19810, -10))], 'Stale group link on a lesson never leaks its title to a group-only learner');
$deniedIds = array_column($repo->learnerExercises([55]), 'id');
verify(!in_array(19998, $deniedIds, true), 'Stale lesson link on a group does not authorize the reverse namespace');

$db = new SqliteWpdb(); $repo = new WpdbRepository($db); $input = fixtureExercise();
$input['audience'] = ['mode' => 'groups', 'groupIds' => [7, 8]];
$saved = $repo->saveExercise($input, 10, null, 0);
verify($saved['audience'] === $input['audience'] && $repo->exerciseSummary(1)['audience'] === $input['audience'], 'Group parent and projection commit together on initial save');
$changed = $input; $changed['audience']['groupIds'] = [9];
foreach (['INSERT INTO \x60wp_oj_microbit_exercise_groups\x60', 'COMMIT'] as $failure) {
    $db->fail = str_replace('\x60', chr(96), $failure);
    throws(fn () => $repo->saveExercise($changed, 10, 1, 1), RuntimeException::class, 'Group insertion or commit failure aborts the complete CAS change');
    $db->fail = null;
    verify($repo->exercise(1)['revision'] === 1 && $repo->exerciseSummary(1)['audience'] === $input['audience'] && $repo->learnerExercises([], 0, 21, [9]) === [], 'Failed group replacement preserves previous version and cannot open a new group');
}
$lessonInput = $input; $lessonInput['audience'] = ['mode' => 'classes', 'classIds' => [7]];
$db->fail = 'DELETE FROM ' . chr(96) . 'wp_oj_microbit_exercise_groups';
throws(fn () => $repo->saveExercise($lessonInput, 10, 1, 1), RuntimeException::class, 'A failure clearing the old group table rolls back the newly inserted lesson links');
$db->fail = null;
verify($repo->exerciseSummary(1)['audience'] === $input['audience'] && (int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 0, 'Namespace transition failure leaves no mixed assignment');
$lessonSaved = $repo->saveExercise($lessonInput, 10, 1, 1);
verify($lessonSaved['revision'] === 2 && (int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_groups')->fetchColumn() === 0 && $repo->learnerExercises([], 0, 21, [7]) === [], 'Explicit group-to-lesson revision removes group access atomically');
$groupSaved = $repo->saveExercise($input, 10, 1, 2);
verify($groupSaved['revision'] === 3 && (int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_classes')->fetchColumn() === 0 && $repo->learnerExercises([7]) === [], 'Explicit lesson-to-group revision removes legacy access without interpreting IDs');
$db->fail = 'DELETE FROM ' . chr(96) . 'wp_oj_microbit_exercise_groups';
throws(fn () => $repo->archiveExercise(1, 3), RuntimeException::class, 'Group cleanup failure rolls back archive and version');
$db->fail = null;
verify($repo->exerciseSummary(1)['revision'] === 3 && count($repo->learnerExercises([], 0, 21, [7])) === 1, 'Archive rollback retains the original live group distribution');
$repo->archiveExercise(1, 3);
verify($repo->learnerExercises([], 0, 21, [7]) === [] && (int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_exercise_groups')->fetchColumn() === 0, 'Archive removes group links and public lookup together');
echo '[groups-storage] ' . $testChecks . " checks passed (20,000 mixed rows, indexed group/lesson union, real SQLite transactions; MySQL acceptance remains separate)\n";
