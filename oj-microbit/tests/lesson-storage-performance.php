<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/SqliteWpdb.php';
use OjMicrobit\Storage\WpdbLessonRepository;
use OjMicrobit\Storage\WpdbRepository;
use OjMicrobit\Support\Privacy;

function lessonPlan(SqliteWpdb $db, string $sql): string { return implode(' | ', array_column($db->pdo->query('EXPLAIN QUERY PLAN ' . $sql)->fetchAll(PDO::FETCH_ASSOC), 'detail')); }
$db = new SqliteWpdb(); $repo = new WpdbLessonRepository($db); $global = new WpdbRepository($db); $db->pdo->beginTransaction();
$exercise = $db->pdo->prepare('INSERT INTO wp_oj_microbit_exercises(id,owner_id,title,status,revision,archived,audience_mode,data_json,created_at,updated_at) VALUES (?,10,?,?,1,0,?,?,?,?)');
$assignment = $db->pdo->prepare('INSERT INTO wp_oj_microbit_assignments(id,class_id,exercise_id,exercise_revision,title,owner_id,revision,archived,created_at,updated_at) VALUES (?,?,?,1,?,11,1,?,?,?)');
for ($id = 1; $id <= 20000; $id++) {
    $exercise->execute([$id, '문제 ' . $id, $id % 2 ? 'draft' : 'published', $id % 5 ? 'site' : 'groups', '{}', '2020-01-01', '2026-11-03']);
    $assignment->execute([$id, $id % 100 + 1, $id, '수업 문제 ' . $id, $id % 3 === 0 ? 1 : 0, '2020-01-01', '2026-11-03']);
}
$db->pdo->commit(); $large = str_repeat('invalid JSON payload ', 60000);
$statement = $db->pdo->prepare('UPDATE wp_oj_microbit_exercises SET data_json=? WHERE id=20000'); $statement->execute([$large]);
$db->pdo->exec('DELETE FROM wp_oj_microbit_exercises WHERE id=19999');
$db->queries = []; $rows = $repo->publicExercises(0, 21);
verify(count($rows) === 21 && !isset($rows[0]['starterProject']), 'Public problem page reads metadata with a large invalid JSON source');
verify(count($db->queries) === 1 && !preg_match('/json|COUNT\(|OFFSET/i', $db->queries[0]) && str_contains(lessonPlan($db, $db->queries[0]), 'site_recent'), 'Public library uses one bounded site index query without membership or payload scans');
$db->queries = []; $rows = $repo->assignments(range(1, 100), 0, 21);
verify(count($rows) === 21 && $rows[0]['id'] === 20000 && $rows[1]['id'] === 19999, 'Mixed active/withdrawn/missing-source assignment snapshots do not create pagination holes');
verify(!$rows[1]['sourceAvailable'] && !$rows[1]['workAvailable'], 'A missing source is retained as a read-only course-history snapshot');
$sql = $db->queries[0]; $joinAt = strpos($sql, ' LEFT JOIN ');
verify(count($db->queries) === 1 && substr_count($sql, 'ORDER BY id DESC LIMIT 21') === 100 && $joinAt !== false && strpos($sql, 'eligible ON eligible.id=a.id') < $joinAt, 'Each of 100 courses bounds 21 candidate keys before the one source-metadata join');
verify(!preg_match('/data_json|project_json|result_json|COUNT\(|OFFSET/i', $sql) && str_contains(lessonPlan($db, $sql), 'assignment_class_records'), 'Assignment listing uses covering class/id candidates without payload hydration or row-by-row database calls');
$seen = []; $before = 0;
for ($page = 0; $page < 7; $page++) { $batch = $repo->assignments(range(1, 100), $before, 50); foreach ($batch as $row) { $seen[] = $row['id']; } $before = end($batch)['id']; }
verify(count($seen) === 350 && count(array_unique($seen)) === 350 && $seen === range(20000, 19651), 'Assignment cursors traverse 350 mixed lifecycle rows exactly once');
$db->queries = []; throws(fn () => $repo->assignments(range(1, 101)), InvalidArgumentException::class, 'Course membership projection cannot exceed 100 branches');
throws(fn () => $repo->assignments([1, 1]), InvalidArgumentException::class, 'Duplicate course scope is rejected'); verify($db->queries === [], 'Invalid course scopes do not query storage');
$exercise->execute([50001, '동일 공개 문제', 'published', 'site', '{}', '2020-01-01', '2026-11-03']);
$assignment->execute([30001, 7, 50001, '동일 문제 수업 A', 0, '2020-01-01', '2026-11-03']);
$assignment->execute([30002, 8, 50001, '동일 문제 수업 B', 0, '2020-01-01', '2026-11-03']);
$db->pdo->beginTransaction(); $attempt = $db->pdo->prepare('INSERT INTO wp_oj_microbit_attempts(id,exercise_id,exercise_revision,assignment_id,user_id,project_json,result_json,result_status,result_passed,result_score,created_at) VALUES (?,50001,1,?,20,?,?,\'graded\',1,100,\'2026-11-03\')');
for ($id = 1; $id <= 50000; $id++) { $attempt->execute([$id, $id <= 30 ? 0 : ($id % 2 ? 30001 : 30002), '{}', '{}']); }
$summary = $db->pdo->prepare('INSERT INTO wp_oj_microbit_lesson_results(assignment_id,user_id,attempt_id,exercise_revision,result_status,result_passed,result_score,updated_at) VALUES (30001,?,?,1,\'graded\',1,100,\'2026-11-03\')');
for ($user = 1; $user <= 20000; $user++) { $summary->execute([$user, 49999]); } $db->pdo->commit();
$statement = $db->pdo->prepare('UPDATE wp_oj_microbit_attempts SET project_json=?,result_json=? WHERE id=49999'); $statement->execute([$large, $large]); $db->pdo->exec('ANALYZE');
$db->queries = []; $general = $global->attempts(50001, 20, 0, 21); $sql = $db->queries[0];
verify(count($general) === 21 && $general[0]['id'] === 30, 'A small general history remains correct beside 49,970 course attempts for the same problem and learner');
verify(str_contains(lessonPlan($db, $sql), 'scope_records') && !preg_match('/json|COUNT\(|OFFSET/i', $sql), 'General history uses the assignment-leading user index rather than scanning course submissions');
$db->queries = []; $course = $repo->lessonAttempts(30001, 20, 0, 21); $sql = $db->queries[0];
verify(count($course) === 21 && $course[0]['id'] === 49999 && !isset($course[0]['project']), 'Course history excludes both general and another lesson and never hydrates a large invalid payload');
verify(str_contains(lessonPlan($db, $sql), 'scope_records') && str_contains($sql, 'exercise_id=(SELECT exercise_id') && substr_count($sql, 'LIMIT 21') === 1, 'Course and general history share the same aligned composite index and a single bounded query');
$db->queries = []; $repo->lessonAttempts(30001, null, 40000, 21);
verify(str_contains(lessonPlan($db, $db->queries[0]), 'scope_recent'), 'Teacher course history uses the aligned assignment/problem/id index');
$db->queries = []; $resultRows = $repo->lessonResults(30001, range(1, 20)); $sql = $db->queries[0];
verify(count($resultRows) === 20 && count($db->queries) === 1 && str_ends_with($sql, 'LIMIT 20') && !preg_match('/json|COUNT\(|GROUP BY|OFFSET/i', $sql), 'Roster reports read just one latest scalar row for each of 20 authorized current students');
verify(str_contains(lessonPlan($db, $sql), 'assignment_id=?') && str_contains(lessonPlan($db, $sql), 'user_id=?'), 'Latest result projection uses the composite primary key instead of aggregating 50,000 attempts');
$db->queries = []; throws(fn () => $repo->lessonResults(30001, range(1, 51)), InvalidArgumentException::class, 'Roster result batch is capped at the 50-student UI bound'); verify($db->queries === [], 'Invalid roster result bounds do not reach the database');
// New personal stores obey the existing bounded retention and erasure policies.
$expiry = new SqliteWpdb(); $maintenance = new WpdbRepository($expiry); $GLOBALS['wpdb'] = $expiry;
for ($id = 1; $id <= 137; $id++) {
    $expiry->pdo->exec("INSERT INTO wp_oj_microbit_lesson_projects VALUES ({$id},20,1,'{}','2000-01-01')");
    $expiry->pdo->exec("INSERT INTO wp_oj_microbit_lesson_results VALUES ({$id},20,1,1,'graded',1,100,'2000-01-01')");
}
$expiry->pdo->exec("INSERT INTO wp_oj_microbit_lesson_projects VALUES (1,21,1,'{}','2030-01-01')"); $expiry->pdo->exec("INSERT INTO wp_oj_microbit_lesson_results VALUES (1,21,1,1,'graded',1,100,'2030-01-01')");
$expiry->queries = []; verify($maintenance->purge(1), 'Retention schedules another batch when more old course records remain');
verify((int) $expiry->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_lesson_projects WHERE user_id=20')->fetchColumn() === 87 && (int) $expiry->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_lesson_results WHERE user_id=20')->fetchColumn() === 87, 'One cleanup removes at most 50 old rows from each new table');
verify(!preg_match('/SELECT .*json/i', implode('\n', $expiry->queries)), 'Retention touches bounded keys and never course source/result JSON');
$batch = Privacy::eraseBatch(20);
verify($batch['removed'] === 174 && $maintenance->purge(1) === false, 'Privacy erasure independently removes remaining lesson drafts and result projections under the actor lease');
verify((int) $expiry->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_lesson_projects WHERE user_id=21')->fetchColumn() === 1 && (int) $expiry->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_lesson_results WHERE user_id=21')->fetchColumn() === 1, 'Expiry and erasure preserve another learner with the same composite assignment key');
for ($id = 1; $id <= 137; $id++) { $expiry->pdo->exec("INSERT INTO wp_oj_microbit_lesson_projects VALUES ({$id},20,1,'{}','2030-01-01')"); $expiry->pdo->exec("INSERT INTO wp_oj_microbit_lesson_results VALUES ({$id},20,1,1,'graded',1,100,'2030-01-01')"); }
$batch = Privacy::eraseBatch(20);
verify($batch['more'] && $batch['removed'] === 200 && (int) $expiry->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_lesson_projects WHERE user_id=20')->fetchColumn() === 37, 'Large new personal stores are erased in 100-key batches and never falsely marked complete');
Privacy::eraseBatch(20); verify((int) $expiry->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_lesson_results WHERE user_id=20')->fetchColumn() === 0, 'A second privacy batch completes the remaining course projection keys');
echo '[lesson-storage-performance] ' . $testChecks . " checks passed (20,000 assignments/summaries, 50,000 attempts, real SQLite plans; actual MySQL optimizer and locks require acceptance)\n";
