<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/SqliteWpdb.php';

use OjMicrobit\Storage\WpdbRepository;

function planUses(SqliteWpdb $db, string $sql, string $index): void
{
    $plan = implode('\n', array_column($db->pdo->query('EXPLAIN QUERY PLAN ' . $sql)->fetchAll(PDO::FETCH_ASSOC), 'detail'));
    verify(str_contains($plan, $index) && !str_contains($plan, 'TEMP B-TREE'), 'Indexed bounded query without sort buffer: ' . $index . ' (' . $plan . ')');
}
function scalarCount(SqliteWpdb $db, string $table, string $where = '1=1'): int
{
    return (int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_' . $table . ' WHERE ' . $where)->fetchColumn();
}

$db = new SqliteWpdb(); $repo = new WpdbRepository($db);
$db->pdo->beginTransaction();
$insert = $db->pdo->prepare('INSERT INTO wp_oj_microbit_exercises (id,owner_id,title,status,revision,archived,data_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
for ($id = 1; $id <= 12000; $id++) {
    $insert->execute([$id, $id % 11 + 1, '과제 ' . $id, $id % 3 === 0 ? 'draft' : 'published', 1, $id % 17 === 0 ? 1 : 0, '{}', '2020-01-01', '2026-10-28']);
}
$insert = $db->pdo->prepare('INSERT INTO wp_oj_microbit_attempts (id,exercise_id,exercise_revision,user_id,project_json,result_json,created_at,result_status,result_passed,result_score) VALUES (?,?,?,?,?,?,?,?,?,?)');
for ($id = 1; $id <= 30000; $id++) { $insert->execute([$id, $id % 9 + 1, 1, $id % 23 + 20, '{}', '{}', '2026-10-28', 'graded', 1, 100]); }
$db->pdo->commit();
// Several MiB of deliberately invalid JSON would fail if a summary path hydrated it.
$large = str_repeat('X', 1024 * 1024);
$statement = $db->pdo->prepare('UPDATE wp_oj_microbit_exercises SET data_json=? WHERE id=12000'); $statement->execute([$large]);
$statement = $db->pdo->prepare('UPDATE wp_oj_microbit_attempts SET result_json=?,project_json=? WHERE id=30000'); $statement->execute([$large, $large]);
$db->queries = [];
$page = $repo->exercises(null, false, 0, 21);
verify(count($page) === 21 && $page[0]['id'] === 12000 && !isset($page[0]['starterProject']), 'Large exercise payload is absent from the bounded metadata page');
verify(count($db->queries) === 1 && !str_contains($db->queries[0], 'data_json') && !str_contains($db->queries[0], 'OFFSET'), 'Exercise list executes one keyset metadata query');
planUses($db, $db->queries[0], 'active_recent');
$repo->exercises(2, false, 10000, 21); planUses($db, end($db->queries), 'owner_recent');
$repo->exercises(null, true, 10000, 21); planUses($db, end($db->queries), 'published_recent');
$db->queries = []; $summary = $repo->exerciseSummary(12000);
verify($summary['id'] === 12000 && count($db->queries) === 1 && !str_contains($db->queries[0], 'data_json'), 'History authorization reads only exercise metadata, including with large invalid source JSON');
$db->queries = []; $page = $repo->attempts(4, null, 0, 21);
verify(count($page) === 21 && $page[0]['id'] === 30000 && $page[0]['result']['score'] === 100.0, 'Teacher summary reads scalar scores despite multi-MiB invalid raw records');
verify(count($db->queries) === 1 && !preg_match('/result_json|project_json|OFFSET/', $db->queries[0]), 'History list performs one bounded query without either JSON column');
planUses($db, $db->queries[0], 'scope_recent');
$repo->attempts(4, 28, 29000, 21); planUses($db, end($db->queries), 'exercise_records');
$seen = []; $before = 0;
for ($pageNo = 0; $pageNo < 7; $pageNo++) {
    $items = $repo->attempts(4, null, $before, 50);
    foreach ($items as $item) { $seen[] = $item['id']; }
    $before = end($items)['id'];
    if ($pageNo === 0) { $db->pdo->exec("INSERT INTO wp_oj_microbit_attempts (id,exercise_id,exercise_revision,user_id,project_json,result_json,created_at,result_status,result_passed,result_score) VALUES (30001,4,1,20,'{}','{}','2026-10-28','graded',1,100)"); }
}
verify(count($seen) === 350 && count(array_unique($seen)) === 350 && !in_array(30001, $seen, true), 'Keyset visits records beyond the old 100 cap without duplicates after a concurrent newer insert');
$db->queries = [];
throws(fn () => $repo->attempts(4, null, -1), InvalidArgumentException::class, 'Negative keyset cursor rejected');
throws(fn () => $repo->exercises(null, false, 0, 52), InvalidArgumentException::class, 'Repository query limit capped including lookahead');
verify($db->queries === [], 'Invalid pagination never reaches the database');
planUses($db, 'SELECT exercise_id FROM wp_oj_microbit_projects WHERE user_id=20 ORDER BY exercise_id LIMIT 1 OFFSET 1000', 'personal_projects');
planUses($db, 'SELECT id FROM wp_oj_microbit_exercises WHERE owner_id=2 ORDER BY id LIMIT 1 OFFSET 1000', 'author_records');
planUses($db, 'SELECT id FROM wp_oj_microbit_attempts WHERE user_id=20 ORDER BY id LIMIT 1 OFFSET 1000', 'personal_records');

// Legacy migration is lazy and bounded; the list and the detail are read-only.
$legacy = new SqliteWpdb(); $old = new WpdbRepository($legacy);
$raw = json_encode(['status' => 'graded', 'passed' => true, 'score' => 87.5, 'trace' => [$large]], JSON_THROW_ON_ERROR);
$insert = $legacy->pdo->prepare('INSERT INTO wp_oj_microbit_attempts (exercise_id,exercise_revision,user_id,project_json,result_json,created_at) VALUES (1,1,20,?,?,?)');
for ($id = 0; $id < 11; $id++) { $insert->execute(['{}', $id === 10 ? '{invalid' : $raw, '2026-10-28']); }
$legacy->queries = []; $items = $old->attempts(1, 20);
verify(count($items) === 11 && $items[0]['summaryPending'] && $items[0]['result'] === ['status' => 'legacy', 'passed' => null, 'score' => null], 'Unconverted legacy summaries remain explicit without inventing scores');
$detail = $old->attempt(1, 10, 20);
verify($detail['result']['score'] === 87.5 && count($legacy->queries) === 2 && !preg_grep('/^(UPDATE|INSERT|DELETE)/', $legacy->queries), 'One full legacy detail read preserves the original result without a side-effect write');
verify($old->attempt(2, 10, 20) === null && $old->attempt(1, 10, 99) === null, 'Lazy full detail remains scoped to exercise and learner');
$legacy->queries = []; verify($old->backfillAttemptSummaries(2), 'Two-record migration batch reports remaining work');
verify(scalarCount($legacy, 'attempts', "result_status<>''") === 2, 'Migration touches no more than its explicit batch');
verify(count(preg_grep('/^SELECT result_json/', $legacy->queries)) === 2 && !preg_grep('/SELECT .*project_json/', $legacy->queries), 'Migration hydrates each result separately and never student source');
planUses($legacy, $legacy->queries[0], 'summary_queue');
verify($old->attempts(1, 20)[0]['result']['status'] === 'unavailable', 'Corrupt legacy JSON leaves an unavailable summary and cannot trap the queue forever');
while ($old->backfillAttemptSummaries(2)) {}
verify(scalarCount($legacy, 'attempts', "result_status=''") === 0 && $old->attempts(1, 20)[1]['result']['score'] === 87.5, 'Subsequent small batches finish legacy summaries with exact numeric values');
verify($legacy->pdo->query('SELECT result_json FROM wp_oj_microbit_attempts WHERE id=10')->fetchColumn() === $raw, 'Backfill preserves the original result JSON byte for byte');

// Expiry is a covering key read followed by a capped primary-key deletion.
$expiry = new SqliteWpdb(); $cleanup = new WpdbRepository($expiry);
$expiry->pdo->beginTransaction();
for ($id = 1; $id <= 137; $id++) { $expiry->pdo->exec("INSERT INTO wp_oj_microbit_attempts (id,exercise_id,exercise_revision,user_id,project_json,result_json,created_at,result_status,result_passed,result_score) VALUES ({$id},1,1,20,'{}','{}','2000-01-01','graded',1,100)"); }
for ($id = 1; $id <= 93; $id++) { $expiry->pdo->exec("INSERT INTO wp_oj_microbit_projects VALUES ({$id},20,1,'{}','2000-01-01')"); }
for ($id = 1; $id <= 81; $id++) { $expiry->pdo->exec("INSERT INTO wp_oj_microbit_limits VALUES ('run:{$id}',0,1)"); }
$expiry->pdo->exec("INSERT INTO wp_oj_microbit_limits VALUES ('erase:99'," . PHP_INT_MAX . ',0)');
$expiry->pdo->exec("INSERT INTO wp_oj_microbit_projects VALUES (999,20,1,'{}','2099-01-01')");
$expiry->pdo->commit();
$expiry->queries = []; verify($cleanup->purge(365, 17), 'Full expiry batch requests a continuation');
verify(scalarCount($expiry, 'attempts') === 120 && scalarCount($expiry, 'projects') === 77 && scalarCount($expiry, 'limits') === 65, 'One callback removes at most seventeen records from each table');
$keyQueries = array_values(array_filter($expiry->queries, static fn ($q) => str_starts_with($q, 'SELECT')));
planUses($expiry, $keyQueries[0], 'attempt_expiry'); planUses($expiry, $keyQueries[1], 'project_expiry'); planUses($expiry, $keyQueries[2], 'lesson_project_expiry'); planUses($expiry, $keyQueries[3], 'lesson_result_expiry'); planUses($expiry, $keyQueries[4], 'limit_expiry');
verify(!preg_grep('/SELECT .*json|OFFSET/i', $keyQueries), 'Expiry reads covering keys without payloads or OFFSET');
$counts = [scalarCount($expiry, 'attempts'), scalarCount($expiry, 'projects')]; $cleanup->purge(0, 17);
verify($counts === [scalarCount($expiry, 'attempts'), scalarCount($expiry, 'projects')] && scalarCount($expiry, 'limits') === 48, 'Retention zero keeps student records while still expiring quotas');
$reactivatedScope = null;
$expiry->beforeQuery = static function (string $sql, SqliteWpdb $db) use (&$reactivatedScope): void {
    if (str_starts_with($sql, 'DELETE FROM `wp_oj_microbit_projects`')) { $db->pdo->exec("UPDATE wp_oj_microbit_projects SET updated_at='2099-01-01' WHERE exercise_id=18 AND user_id=20"); }
    if (str_starts_with($sql, 'DELETE FROM `wp_oj_microbit_limits`') && preg_match("/scope_key='([^']+)'/", $sql, $match)) {
        $reactivatedScope = $match[1];
        $db->pdo->exec('UPDATE wp_oj_microbit_limits SET window_start=' . time() . ' WHERE scope_key=' . $db->pdo->quote($reactivatedScope));
    }
};
$cleanup->purge(365, 17); $expiry->beforeQuery = null;
verify(scalarCount($expiry, 'projects', 'exercise_id=18') === 1 && $reactivatedScope !== null && scalarCount($expiry, 'limits', 'scope_key=' . $expiry->pdo->quote($reactivatedScope)) === 1, 'Cutoff is rechecked during deletion when a project or quota becomes active after key selection');
$iterations = 0; while ($cleanup->purge(365, 17)) { if (++$iterations > 20) { throw new RuntimeException('Expiry did not finish'); } }
verify(scalarCount($expiry, 'attempts') === 0 && scalarCount($expiry, 'projects') === 2 && scalarCount($expiry, 'limits') === 2, 'Repeated capped batches finish expired records and preserve active rows and durable erasure markers');
throws(fn () => $cleanup->purge(365, 101), InvalidArgumentException::class, 'Expiry batch cannot be enlarged beyond one hundred');
throws(fn () => $old->backfillAttemptSummaries(21), InvalidArgumentException::class, 'Legacy hydration batch cannot be enlarged beyond twenty');

echo '[storage-performance] ' . $testChecks . " checks passed (12,000 exercises, 30,000 attempts, MiB payloads; real SQLite plans, no live MySQL optimizer or concurrent locks)\n";
