<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/SqliteWpdb.php';

use OjMicrobit\Application\Access;
use OjMicrobit\Application\LessonAccess;
use OjMicrobit\Application\LessonService;
use OjMicrobit\Application\PracticeService;
use OjMicrobit\Application\Runner;
use OjMicrobit\Storage\WpdbLessonRepository;
use OjMicrobit\Storage\WpdbRepository;
use OjMicrobit\Support\OperationError;
use OjMicrobit\Support\Privacy;

final class LessonActor implements Access, LessonAccess
{
    public ?array $classes = []; public array $managed = []; public array $disabled = []; public array $roster = []; public int $learningCalls = 0; public int $rosterCalls = 0;
    public function __construct(public int $id, public bool $teacher = false) {}
    public function actorId(): int { return $this->id; }
    public function requireTeacher(): void { if (!$this->teacher) { throw new OperationError('access_denied', 403, '교사 권한이 필요합니다.'); } }
    public function requireLearner(): void { if ($this->id < 1) { throw new OperationError('unauthorized', 401, '로그인하세요.'); } }
    public function isAdmin(): bool { return false; }
    public function teachingGroups(string $cursor, int $limit): array { return ['available' => false, 'items' => [], 'nextCursor' => null]; }
    public function learnerGroupIds(): ?array { return null; }
    public function requireGroupManagement(array $groupIds): void { throw new OperationError('access_denied', 403, '그룹 권한이 없습니다.'); }
    public function requireGroupParticipation(array $groupIds): void { throw new OperationError('access_denied', 403, '그룹 권한이 없습니다.'); }
    public function teachingClasses(string $cursor, int $limit): array { return ['available' => true, 'items' => [], 'nextCursor' => null]; }
    public function learnerClassIds(): ?array { return $this->classes; }
    public function requireClassManagement(array $classIds): void { foreach ($classIds as $id) { if (!in_array($id, $this->managed, true) || in_array($id, $this->disabled, true)) { throw new OperationError('access_denied', 403, '활성 수업 관리 권한이 없습니다.'); } } }
    public function requireClassParticipation(array $classIds): void { if (array_intersect($classIds, array_diff($this->classes ?? [], $this->disabled)) === []) { throw new OperationError('access_denied', 403, '수업 참여 권한이 없습니다.'); } }
    public function learningLessons(): ?array { $this->learningCalls++; return $this->classes === null ? null : array_map(static fn ($id) => ['id' => $id, 'title' => '실습 수업 ' . $id], array_values(array_diff($this->classes, $this->disabled))); }
    public function lessonTitle(int $classId, bool $teacher): string
    {
        if ($teacher) { if (!in_array($classId, $this->managed, true)) { throw new OperationError('access_denied', 403, '수업 관리 권한이 없습니다.'); } }
        else { $this->requireClassParticipation([$classId]); }
        return '실습 수업 ' . $classId;
    }
    public function lessonRoster(int $classId, string $cursor, int $limit, ?string $contextRevision = null, ?string $rosterRevision = null): array
    {
        $this->rosterCalls++; $this->requireClassManagement([$classId]);
        if (($contextRevision !== null && $contextRevision !== 'class-r1') || ($rosterRevision !== null && $rosterRevision !== 'roster-r1')) { throw new OperationError('conflict', 409, '수업 명단이 바뀌었습니다.'); }
        $start = $cursor === '' ? 0 : (int) substr($cursor, 2); $all = $this->roster[$classId] ?? [];
        $items = array_map(static fn ($id) => ['userId' => $id, 'displayName' => '학생 ' . $id, 'managementName' => '번호 ' . $id], array_slice($all, $start, $limit));
        return ['items' => $items, 'nextCursor' => $start + $limit < count($all) ? 'r:' . ($start + $limit) : null, 'contextRevision' => 'class-r1', 'rosterRevision' => 'roster-r1', 'classTitle' => '실습 수업 ' . $classId];
    }
}
final class LessonRunner implements Runner
{
    public mixed $after = null; public bool $fail = false; public float $score = 100; public int $calls = 0;
    public function status(): array { return ['ready' => true]; }
    public function run(array $project, array $exercise): array
    {
        $this->calls++; if ($this->fail) { throw new OperationError('unavailable', 503, '실행 서비스가 연결되지 않았습니다.'); }
        $result = fixtureResult($project, $exercise); $result['score'] = $this->score; $result['passed'] = $this->score === 100.0;
        if (is_callable($this->after)) { ($this->after)(); } return $result;
    }
}
$db = new SqliteWpdb(); $records = new WpdbRepository($db); $lessons = new WpdbLessonRepository($db); $runner = new LessonRunner();
$author = new LessonActor(10, true); $teacher = new LessonActor(11, true); $teacher->managed = [7, 8]; $teacher->roster = [7 => [20, 22, 23], 8 => [20, 24]];
$student = new LessonActor(20); $student->classes = [7, 8]; $outside = new LessonActor(21); $generalOnly = new LessonActor(22);
$make = static fn (LessonActor $actor) => new LessonService($lessons, $records, $actor, $actor, $runner);
$teach = $make($teacher); $learn = $make($student); $outsider = $make($outside); $authorLessons = $make($author);
$source = $records->saveExercise(fixtureExercise(), 10, null, 0); $problemId = $source['id'];
$legacy = fixtureExercise(); $legacy['audience'] = ['mode' => 'groups', 'groupIds' => [7]]; $records->saveExercise($legacy, 10, null, 0);
$draft = fixtureExercise(); $draft['status'] = 'draft'; $records->saveExercise($draft, 10, null, 0);
verify(array_column($outsider->publicExercises()['items'], 'id') === [$problemId], 'Public library needs no lesson/group membership and excludes legacy restricted and draft problems');
verify($outside->learningCalls === 0 && count($teach->publicExercises(0, 20, true)['items']) === 1, 'Public picker uses no membership projection or hidden source JSON');
throws(fn () => $teach->saveAssignment(99, ['exerciseId' => $problemId]), OperationError::class, 'Teachers cannot attach a public problem to an unmanaged lesson');
throws(fn () => $teach->saveAssignment(7, ['exerciseId' => 2]), OperationError::class, 'Legacy restricted problems cannot masquerade as public lesson sources');
throws(fn () => $teach->saveAssignment(7, ['exerciseId' => 3]), OperationError::class, 'Unpublished drafts cannot be applied to a lesson');
$a = $teach->saveAssignment(7, ['exerciseId' => $problemId]); $b = $teach->saveAssignment(8, ['exerciseId' => $problemId]);
verify($a['ownerId'] === 11 && $source['ownerId'] === 10 && $a['id'] !== $b['id'], 'A manager can use another author public problem with separate lesson identifiers');
throws(fn () => $teach->saveAssignment(7, ['exerciseId' => $problemId]), OperationError::class, 'Concurrent or repeated active assignment does not create duplicates');
$details = $learn->assignment($a['id']);
verify($details['workAvailable'] && $details['assignment']['classTitle'] === '실습 수업 7' && count($details['exercise']['scenarios']) === 1 && !str_contains(json_encode($details), '비밀 입력'), 'Lesson detail contains current lesson label and public examples only');
throws(fn () => $outsider->assignment($a['id']), OperationError::class, 'Public problem access does not grant access to a lesson assignment');
verify(count($learn->assignments()['items']) === 2 && $student->learningCalls === 1, 'Student lesson list uses one complete bounded context projection for all row labels');
$student->classes = null; $db->queries = []; $unavailable = $learn->assignments();
verify(!$unavailable['classAccessAvailable'] && $unavailable['items'] === [] && $db->queries === [], 'Missing lesson membership fails closed without database list work'); $student->classes = [7, 8];
$global = new PracticeService($records, $student, $runner); $authorGlobal = new PracticeService($records, $author, $runner); $managerGlobal = new PracticeService($records, $teacher, $runner);
$p0 = fixtureProject(); $p0['files']['main.ts'] .= "\n// general";
$p7 = fixtureProject(); $p7['files']['main.ts'] .= "\n// lesson seven";
$p8 = fixtureProject(); $p8['files']['main.ts'] .= "\n// lesson eight";
$global->saveProject($problemId, ['project' => $p0, 'revision' => 0]); $learn->saveProject($a['id'], ['project' => $p7, 'revision' => 0]); $learn->saveProject($b['id'], ['project' => $p8, 'revision' => 0]);
verify($global->project($problemId)['project'] === $p0 && $learn->project($a['id'])['project'] === $p7 && $learn->project($b['id'])['project'] === $p8, 'General, lesson A and lesson B saved code never overwrite one another');
throws(fn () => $learn->saveProject($a['id'], ['project' => $p8, 'revision' => 0]), OperationError::class, 'Lesson project CAS protects code saved in another tab');
verify($learn->project($a['id'])['project'] === $p7, 'A failed lesson project CAS retains the actual source');
throws(fn () => $global->run($problemId, ['project' => $p0, 'assignmentId' => $a['id']]), InvalidArgumentException::class, 'A forged general request cannot attribute itself to a lesson');
throws(fn () => $learn->run($a['id'], ['project' => $p7, 'assignmentId' => $b['id']]), InvalidArgumentException::class, 'Lesson identity comes only from the authorized route');
$g = $global->run($problemId, ['project' => $p0]); $r7 = $learn->run($a['id'], ['project' => $p7]); $runner->score = 40; $r8 = $learn->run($b['id'], ['project' => $p8]);
verify(array_column($global->attempts($problemId)['items'], 'id') === [$g['id']], 'General history contains only assignment zero even for the same problem and learner');
verify(array_column($learn->attempts($a['id'])['items'], 'id') === [$r7['id']] && array_column($learn->attempts($b['id'])['items'], 'id') === [$r8['id']], 'Each lesson history records explicit assignment identity rather than time or group membership inference');
verify($authorGlobal->attempts($problemId, true)['items'][0]['id'] === $g['id'] && count($authorGlobal->attempts($problemId, true)['items']) === 1, 'Source author cannot bypass course management via global teacher history');
throws(fn () => $authorGlobal->attempt($problemId, $r7['id'], true), OperationError::class, 'General teacher detail cannot fetch a lesson attempt by guessed ID');
throws(fn () => $authorLessons->attempts($a['id'], true), OperationError::class, 'Source author without lesson management has no course report authority');
throws(fn () => $managerGlobal->attempts($problemId, true), OperationError::class, 'Lesson manager without source ownership has no general teacher history authority');
throws(fn () => $learn->attempt($a['id'], $r8['id']), OperationError::class, 'Same learner cannot retrieve another lesson attempt through the wrong course route');
$detail = $teach->attempt($a['id'], $r7['id'], true);
verify($detail['project'] === $p7 && !isset($detail['result']['_visibility']) && !str_contains(json_encode($detail['result']), '비밀 입력') && !isset($detail['result']['scenarios'][1]['assertions']), 'Lesson managers can inspect the submitted code but cannot obtain another author hidden grading conditions');
$runner->score = 100; (new PracticeService($records, $generalOnly, $runner))->run($problemId, ['project' => $p0]);
$report = $teach->results($a['id'], '', 2);
verify(count($report['items']) === 2 && $report['items'][0]['latest']['id'] === $r7['id'] && $report['items'][1]['latest'] === null && $report['nextCursor'] === 'r:2', 'Roster report distinguishes a lesson submission from no submission despite general practice activity');
verify($report['items'][0]['managementName'] === '번호 20' && $report['contextRevision'] === 'class-r1', 'Roster names and revision state come from the authorized platform projection');
$next = $teach->results($a['id'], $report['nextCursor'], 2, $report['contextRevision'], $report['rosterRevision']);
verify(count($next['items']) === 1 && $next['items'][0]['userId'] === 23, 'Report cursors traverse the bounded roster without full-history aggregation');
throws(fn () => $teach->results($a['id'], 'r:2', 2, 'old-context', 'roster-r1'), OperationError::class, 'Changed roster/context state cannot silently mix report pages');
$runner->score = 0; $latest7 = $learn->run($a['id'], ['project' => $p7]);
verify($teach->results($a['id'])['items'][0]['latest']['result']['score'] === 0.0 && $teach->results($b['id'])['items'][0]['latest']['result']['score'] === 40.0, 'Latest result projection is course-specific and reports the latest score rather than a historical best');
$oldCount = (int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_attempts')->fetchColumn(); $db->fail = 'INSERT INTO ' . chr(96) . 'wp_oj_microbit_lesson_results';
throws(fn () => $learn->run($a['id'], ['project' => $p7]), RuntimeException::class, 'Projection failure rolls back its attempt in the same transaction'); $db->fail = null;
verify((int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_attempts')->fetchColumn() === $oldCount && $teach->results($a['id'])['items'][0]['latest']['id'] === $latest7['id'], 'Failed latest-row write cannot create an unreported attempt or replace a prior result');
$db->fail = 'COMMIT'; throws(fn () => $learn->run($a['id'], ['project' => $p7]), RuntimeException::class, 'Commit failure is not reported as a scored submission'); $db->fail = null;
verify((int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_attempts')->fetchColumn() === $oldCount, 'Commit rollback restores the previous history and latest projection');
$runner->fail = true; throws(fn () => $learn->run($a['id'], ['project' => $p7]), OperationError::class, 'Infrastructure outage is not an incorrect grade'); $runner->fail = false;
verify((int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_attempts')->fetchColumn() === $oldCount, 'Infrastructure failure creates neither history nor course result');
$runner->after = static function () use ($student): void { $student->classes = [8]; };
throws(fn () => $learn->run($a['id'], ['project' => $p7]), OperationError::class, 'Lesson participation is rechecked after a long grading run'); $runner->after = null; $student->classes = [7, 8];
verify((int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_attempts')->fetchColumn() === $oldCount, 'Revoked lesson work never commits to the report');
$runner->after = static function () use ($lessons, $a): void { $lessons->archiveAssignment($a['id'], $a['revision']); };
throws(fn () => $learn->run($a['id'], ['project' => $p7]), OperationError::class, 'Removing a lesson assignment while grading prevents result persistence'); $runner->after = null;
verify($teach->assignment($a['id'], true)['workAvailable'] === false && $teach->results($a['id'])['items'][0]['latest']['id'] === $latest7['id'], 'Unassigning a problem retains report attribution and latest results');
verify(count($learn->attempts($a['id'])['items']) === 2 && $learn->attempt($a['id'], $r7['id'])['project'] === $p7, 'Current participants can read their prior lesson submissions after unassignment');
throws(fn () => $learn->saveProject($a['id'], ['project' => $p7, 'revision' => 1]), OperationError::class, 'Unassigned lessons no longer accept new saved work');
$again = $teach->saveAssignment(7, ['exerciseId' => $problemId]);
verify($again['id'] === $a['id'] && $again['revision'] > $a['revision'] && $teach->results($a['id'])['items'][0]['latest']['id'] === $latest7['id'], 'Reapplying the same course problem preserves its explicit context and old report while increasing the revision');
$staleAssignment = $a; throws(fn () => $lessons->saveLessonProject($staleAssignment, 20, $p7, 1), OperationError::class, 'A stale assignment cannot save a project after unassign/reapply');
throws(fn () => $lessons->saveLessonAttempt($staleAssignment, 1, 20, $p7, fixtureResult($p7, $source)), OperationError::class, 'Storage transaction rejects an old assignment revision even after unassign/reapply');
$db->pdo->exec("UPDATE wp_oj_microbit_exercises SET status='draft' WHERE id=" . $problemId);
verify(!$teach->assignment($a['id'], true)['sourceAvailable'] && count($teach->attempts($a['id'], true)['items']) === 2, 'Withdrawing the public source preserves course reports without exposing its now-private content');
throws(fn () => $learn->run($a['id'], ['project' => $p7]), OperationError::class, 'Withdrawing source publication stops new lesson submissions');
$teacher->disabled = [7];
verify(count($teach->attempts($a['id'], true)['items']) === 2 && $teach->assignment($a['id'], true)['assignment']['classTitle'] === '실습 수업 7', 'Authorized managers can inspect retained history for an inactive lesson');
throws(fn () => $teach->results($a['id']), OperationError::class, 'Inactive lessons do not fabricate a current live roster');
throws(fn () => $teach->saveAssignment(7, ['exerciseId' => $problemId]), OperationError::class, 'Inactive lessons reject new problem applications'); $teacher->disabled = [];
$teacher->managed = [8]; throws(fn () => $teach->attempts($a['id'], true), OperationError::class, 'Losing lesson management immediately revokes historical course report access'); $teacher->managed = [7, 8];
$db->pdo->exec('DELETE FROM wp_oj_microbit_exercises WHERE id=' . $problemId);
verify(count($teach->attempts($a['id'], true)['items']) === 2 && $teach->assignment($a['id'], true)['assignment']['title'] === $source['title'], 'Deleting source author material retains a small problem title snapshot and course result attribution');
// Creator attribution is erased without deleting other students or a manager authority ledger.
$GLOBALS['wpdb'] = $db; $batch = Privacy::eraseBatch(11);
verify($batch['removed'] === 2 && (int) $db->pdo->query('SELECT owner_id FROM wp_oj_microbit_assignments WHERE id=' . $a['id'])->fetchColumn() === 0, 'Creator erasure clears personal attribution in a bounded update rather than dropping a shared course');
verify(count($teach->attempts($a['id'], true)['items']) === 2, 'Creator account erasure preserves student submissions and class-based management');
$erased = Privacy::eraseBatch(20);
verify($erased['removed'] > 0 && $lessons->lessonProject($a['id'], 20) === null && $lessons->lessonResults($a['id'], [20]) === [] && $lessons->lessonAttempts($a['id'], 20) === [], 'Student erasure removes lesson drafts, latest projection and both general/course attempts under the shared run lease');
verify((int) $db->pdo->query('SELECT COUNT(*) FROM wp_oj_microbit_attempts WHERE user_id=22')->fetchColumn() === 1, 'Erasing one student retains other learner records');
echo '[lessons] ' . $testChecks . " checks passed (actual SQLite transactions and isolated public/class permission fixtures; live platform/MySQL acceptance remains separate)\n";
