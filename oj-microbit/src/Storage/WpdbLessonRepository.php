<?php
declare(strict_types=1);
namespace OjMicrobit\Storage;

use InvalidArgumentException;
use OjMicrobit\Application\LessonRepository;
use OjMicrobit\Support\OperationError;
use RuntimeException;

/** Every list reads bounded metadata; source and result JSON are hydrated only in authorized details. */
final class WpdbLessonRepository implements LessonRepository
{
    public function __construct(private readonly object $db) {}
    private function raw(string $name): string { return $this->db->prefix . 'oj_microbit_' . $name; }
    private function table(string $name): string { return chr(96) . str_replace(chr(96), chr(96) . chr(96), $this->raw($name)) . chr(96); }
    private function rows(mixed $rows): array
    {
        if (!is_array($rows) || (string) $this->db->last_error !== '') { throw new RuntimeException('Microbit lesson query failed.'); }
        return $rows;
    }
    private function page(int $before, int $limit): void
    { if ($before < 0 || $limit < 1 || $limit > 51) { throw new InvalidArgumentException('목록 기준과 조회 개수를 확인해 주세요.'); } }
    private function encode(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private function decode(string $value): array
    {
        $data = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new RuntimeException('Invalid lesson record.'); }
        return $data;
    }
    public function publicExercises(int $beforeId = 0, int $limit = 21): array
    {
        $this->page($beforeId, $limit); $args = [];
        $sql = 'SELECT id,owner_id,title,status,revision,updated_at FROM ' . $this->table('exercises') . " WHERE audience_mode='site' AND status='published' AND archived=0";
        if ($beforeId > 0) { $sql .= ' AND id<%d'; $args[] = $beforeId; } $args[] = $limit;
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'ownerId' => (int) $r['owner_id'], 'title' => $r['title'], 'status' => $r['status'], 'revision' => (int) $r['revision'], 'updatedAt' => $r['updated_at'], 'audience' => ['mode' => 'site', 'classIds' => []]], $this->rows($this->db->get_results($this->db->prepare($sql . ' ORDER BY id DESC LIMIT %d', ...$args), ARRAY_A)));
    }
    private function metadata(array $r): array
    {
        return ['id' => (int) $r['id'], 'classId' => (int) $r['class_id'], 'exerciseId' => (int) $r['exercise_id'], 'exerciseRevision' => (int) $r['exercise_revision'], 'title' => $r['title'], 'ownerId' => (int) $r['owner_id'], 'revision' => (int) $r['revision'], 'archived' => (bool) $r['archived'], 'updatedAt' => $r['updated_at']];
    }
    public function assignments(array $classIds, int $beforeId = 0, int $limit = 21, bool $publicOnly = true): array
    {
        $this->page($beforeId, $limit);
        if (!array_is_list($classIds) || count($classIds) > 100 || count(array_unique($classIds, SORT_REGULAR)) !== count($classIds)) { throw new InvalidArgumentException('수업 목록 범위를 확인해 주세요.'); }
        foreach ($classIds as $id) { if (!is_int($id) || $id < 1) { throw new InvalidArgumentException('수업 번호를 확인해 주세요.'); } }
        if ($classIds === []) { return []; }
        $parts = [];
        foreach ($classIds as $index => $classId) {
            // Bound assignment keys before looking at source publication. A
            // course with many withdrawn sources must not scan all its links.
            $sql = 'SELECT id FROM ' . $this->table('assignments') . ' WHERE class_id=%d'; $args = [$classId];
            if ($beforeId > 0) { $sql .= ' AND id<%d'; $args[] = $beforeId; } $args[] = $limit;
            $parts[] = 'SELECT id FROM (' . $this->db->prepare($sql . ' ORDER BY id DESC LIMIT %d', ...$args) . ') AS lesson_' . $index;
        }
        $sql = 'SELECT a.id,a.class_id,a.exercise_id,a.exercise_revision,a.title,a.owner_id,a.revision,a.archived,a.updated_at,e.id AS source_id,e.status AS source_status,e.audience_mode AS source_mode,e.archived AS source_archived FROM ' . $this->table('assignments') . ' a INNER JOIN (' . implode(' UNION ', $parts) . ') eligible ON eligible.id=a.id LEFT JOIN ' . $this->table('exercises') . ' e ON e.id=a.exercise_id ORDER BY a.id DESC LIMIT ' . $limit;
        return array_map(function (array $r): array {
            $available = $r['source_id'] !== null && $r['source_status'] === 'published' && $r['source_mode'] === 'site' && (int) $r['source_archived'] === 0;
            return $this->metadata($r) + ['sourceAvailable' => $available, 'workAvailable' => $available && !(bool) $r['archived']];
        }, $this->rows($this->db->get_results($sql, ARRAY_A)));
    }
    public function assignment(int $id): ?array
    {
        $rows = $this->rows($this->db->get_results($this->db->prepare('SELECT id,class_id,exercise_id,exercise_revision,title,owner_id,revision,archived,updated_at FROM ' . $this->table('assignments') . ' WHERE id=%d LIMIT 1', $id), ARRAY_A));
        return $rows === [] ? null : $this->metadata($rows[0]);
    }
    private function begin(array $names): void
    {
        foreach ($names as $name) {
            $rows = $this->rows($this->db->get_results($this->db->prepare('SHOW TABLE STATUS WHERE Name=%s', $this->raw($name)), ARRAY_A));
            if (count($rows) !== 1 || strcasecmp((string) ($rows[0]['Engine'] ?? ''), 'InnoDB') !== 0) { throw new OperationError('storage_unavailable', 503, '수업 기록을 안전하게 저장할 수 없습니다. InnoDB 저장소를 확인해 주세요.'); }
        }
        if ($this->db->query('START TRANSACTION') === false) { throw new RuntimeException('Lesson transaction failed.'); }
    }
    private function commit(): void { if ($this->db->query('COMMIT') === false) { throw new RuntimeException('Lesson commit failed.'); } }
    private function publicSourceLock(int $id): array
    {
        $rows = $this->rows($this->db->get_results($this->db->prepare('SELECT id,title,revision,status,audience_mode,archived FROM ' . $this->table('exercises') . ' WHERE id=%d LIMIT 1 FOR UPDATE', $id), ARRAY_A));
        $row = $rows[0] ?? null;
        if ($row === null || $row['status'] !== 'published' || $row['audience_mode'] !== 'site' || (int) $row['archived'] !== 0) { throw new OperationError('access_denied', 403, '현재 공개된 문제만 수업에 적용하거나 제출할 수 있습니다.'); }
        return $row;
    }
    public function saveAssignment(int $classId, int $exerciseId, int $ownerId): array
    {
        $this->begin(['exercises', 'assignments']);
        try {
            $source = $this->publicSourceLock($exerciseId);
            $rows = $this->rows($this->db->get_results($this->db->prepare('SELECT id,revision,archived FROM ' . $this->table('assignments') . ' WHERE class_id=%d AND exercise_id=%d LIMIT 1 FOR UPDATE', $classId, $exerciseId), ARRAY_A));
            $now = gmdate('Y-m-d H:i:s');
            if ($rows === []) {
                $ok = $this->db->insert($this->raw('assignments'), ['class_id' => $classId, 'exercise_id' => $exerciseId, 'exercise_revision' => (int) $source['revision'], 'title' => $source['title'], 'owner_id' => $ownerId, 'revision' => 1, 'archived' => 0, 'created_at' => $now, 'updated_at' => $now]);
                if ($ok !== 1) { throw new OperationError('conflict', 409, '수업 적용 상태가 바뀌었습니다. 목록을 새로고침해 주세요.'); } $id = (int) $this->db->insert_id;
            } else {
                $row = $rows[0];
                if ((int) $row['archived'] !== 1) { throw new OperationError('conflict', 409, '이미 이 수업에 적용한 문제입니다. 수업 문제 목록을 확인해 주세요.'); }
                $id = (int) $row['id'];
                $ok = $this->db->query($this->db->prepare('UPDATE ' . $this->table('assignments') . ' SET archived=0,revision=revision+1,owner_id=%d,title=%s,exercise_revision=%d,updated_at=%s WHERE id=%d AND revision=%d AND archived=1', $ownerId, $source['title'], $source['revision'], $now, $id, $row['revision']));
                if ($ok !== 1) { throw new OperationError('conflict', 409, '수업 적용 상태가 바뀌었습니다. 목록을 새로고침해 주세요.'); }
            }
            $this->commit();
        } catch (\Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
        return $this->assignment($id) ?? throw new RuntimeException('Lesson assignment not found.');
    }
    public function archiveAssignment(int $id, int $revision): void
    {
        $ok = $this->db->query($this->db->prepare('UPDATE ' . $this->table('assignments') . ' SET archived=1,revision=revision+1,updated_at=%s WHERE id=%d AND revision=%d AND archived=0', gmdate('Y-m-d H:i:s'), $id, $revision));
        if ($ok === false) { throw new RuntimeException('Lesson archive failed.'); }
        if ($ok !== 1) { throw new OperationError('conflict', 409, '수업 적용 상태가 바뀌었습니다. 목록을 새로고침해 주세요.'); }
    }
    public function lessonProject(int $assignmentId, int $userId): ?array
    {
        $rows = $this->rows($this->db->get_results($this->db->prepare('SELECT revision,data_json FROM ' . $this->table('lesson_projects') . ' WHERE assignment_id=%d AND user_id=%d LIMIT 1', $assignmentId, $userId), ARRAY_A));
        return $rows === [] ? null : ['project' => $this->decode($rows[0]['data_json']), 'revision' => (int) $rows[0]['revision']];
    }
    public function saveLessonProject(array $assignment, int $userId, array $project, int $revision): array
    {
        $assignmentId = $assignment['id']; $this->begin(['exercises', 'assignments', 'lesson_projects']);
        try {
            $this->publicSourceLock($assignment['exerciseId']);
            $rows = $this->rows($this->db->get_results($this->db->prepare('SELECT id,revision,class_id,exercise_id FROM ' . $this->table('assignments') . ' WHERE id=%d AND archived=0 LIMIT 1 FOR UPDATE', $assignmentId), ARRAY_A));
            $current = $rows[0] ?? null;
            if ($current === null || (int) $current['revision'] !== $assignment['revision'] || (int) $current['class_id'] !== $assignment['classId'] || (int) $current['exercise_id'] !== $assignment['exerciseId']) { throw new OperationError('conflict', 409, '수업 적용이 변경됐습니다. 현재 코드를 내려받고 수업 문제를 다시 열어 주세요.'); }
            if ($revision === 0) {
                $ok = $this->db->query($this->db->prepare('INSERT IGNORE INTO ' . $this->table('lesson_projects') . ' (assignment_id,user_id,revision,data_json,updated_at) VALUES (%d,%d,1,%s,%s)', $assignmentId, $userId, $this->encode($project), gmdate('Y-m-d H:i:s')));
            } else {
                $ok = $this->db->query($this->db->prepare('UPDATE ' . $this->table('lesson_projects') . ' SET revision=revision+1,data_json=%s,updated_at=%s WHERE assignment_id=%d AND user_id=%d AND revision=%d', $this->encode($project), gmdate('Y-m-d H:i:s'), $assignmentId, $userId, $revision));
            }
            if ($ok === false) { throw new RuntimeException('Lesson project save failed.'); }
            if ($ok !== 1) { throw new OperationError('conflict', 409, '다른 창에서 수업 코드를 저장했습니다. 현재 코드를 내려받고 최신 저장본을 확인해 주세요.'); }
            $this->commit();
        } catch (\Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
        return ['project' => $project, 'revision' => $revision + 1];
    }
    private function resultColumns(array $result): array
    {
        $status = $result['status'] ?? null;
        if (!in_array($status, ['graded', 'compile_error', 'runtime_error'], true)) { throw new RuntimeException('Unrecordable lesson verdict.'); }
        $score = $result['score'] ?? null;
        $score = is_numeric($score) && is_finite((float) $score) && $score >= 0 && $score <= 100 ? round((float) $score, 2) : null;
        return ['result_status' => $status, 'result_passed' => is_bool($result['passed'] ?? null) ? (int) $result['passed'] : null, 'result_score' => $score];
    }
    public function saveLessonAttempt(array $assignment, int $exerciseRevision, int $userId, array $project, array $result): int
    {
        $summary = $this->resultColumns($result); $this->begin(['exercises', 'assignments', 'attempts', 'lesson_results']);
        try {
            $this->publicSourceLock($assignment['exerciseId']);
            $rows = $this->rows($this->db->get_results($this->db->prepare('SELECT id,revision,class_id,exercise_id FROM ' . $this->table('assignments') . ' WHERE id=%d AND archived=0 LIMIT 1 FOR UPDATE', $assignment['id']), ARRAY_A));
            $current = $rows[0] ?? null;
            if ($current === null || (int) $current['revision'] !== $assignment['revision'] || (int) $current['class_id'] !== $assignment['classId'] || (int) $current['exercise_id'] !== $assignment['exerciseId']) { throw new OperationError('conflict', 409, '채점 중 수업 적용이 변경됐습니다. 수업 문제를 다시 열어 제출해 주세요.'); }
            $now = gmdate('Y-m-d H:i:s');
            $ok = $this->db->insert($this->raw('attempts'), ['exercise_id' => $assignment['exerciseId'], 'exercise_revision' => $exerciseRevision, 'assignment_id' => $assignment['id'], 'user_id' => $userId, 'project_json' => $this->encode($project), 'result_json' => $this->encode($result), 'created_at' => $now] + $summary);
            if ($ok !== 1) { throw new RuntimeException('Lesson attempt insert failed.'); } $attemptId = (int) $this->db->insert_id;
            // One latest row per student, no aggregate scan of the attempt history.
            $passed = $summary['result_passed'] === null ? 'NULL' : (string) $summary['result_passed']; $score = $summary['result_score'] === null ? 'NULL' : (string) $summary['result_score'];
            $sql = 'INSERT INTO ' . $this->table('lesson_results') . ' (assignment_id,user_id,attempt_id,exercise_revision,result_status,result_passed,result_score,updated_at) VALUES (%d,%d,%d,%d,%s,' . $passed . ',' . $score . ',%s) ON DUPLICATE KEY UPDATE attempt_id=VALUES(attempt_id),exercise_revision=VALUES(exercise_revision),result_status=VALUES(result_status),result_passed=VALUES(result_passed),result_score=VALUES(result_score),updated_at=VALUES(updated_at)';
            if ($this->db->query($this->db->prepare($sql, $assignment['id'], $userId, $attemptId, $exerciseRevision, $summary['result_status'], $now)) === false) { throw new RuntimeException('Lesson latest result save failed.'); }
            $this->commit(); return $attemptId;
        } catch (\Throwable $error) { $this->db->query('ROLLBACK'); throw $error; }
    }
    private function attemptMetadata(array $r): array
    {
        return ['id' => (int) $r['id'], 'exerciseId' => (int) $r['exercise_id'], 'exerciseRevision' => (int) $r['exercise_revision'], 'assignmentId' => (int) $r['assignment_id'], 'userId' => (int) $r['user_id'], 'result' => ['status' => $r['result_status'], 'passed' => $r['result_passed'] === null ? null : (bool) $r['result_passed'], 'score' => $r['result_score'] === null ? null : (float) $r['result_score']], 'createdAt' => $r['created_at']];
    }
    public function lessonAttempts(int $assignmentId, ?int $userId, int $beforeId = 0, int $limit = 21): array
    {
        $this->page($beforeId, $limit); $args = [$assignmentId, $assignmentId];
        $sql = 'SELECT id,exercise_id,exercise_revision,assignment_id,user_id,result_status,result_passed,result_score,created_at FROM ' . $this->table('attempts') . ' WHERE assignment_id=%d AND exercise_id=(SELECT exercise_id FROM ' . $this->table('assignments') . ' WHERE id=%d)';
        if ($userId !== null) { $sql .= ' AND user_id=%d'; $args[] = $userId; } if ($beforeId > 0) { $sql .= ' AND id<%d'; $args[] = $beforeId; } $args[] = $limit;
        return array_map(fn (array $r): array => $this->attemptMetadata($r), $this->rows($this->db->get_results($this->db->prepare($sql . ' ORDER BY id DESC LIMIT %d', ...$args), ARRAY_A)));
    }
    public function lessonAttempt(int $assignmentId, int $attemptId, ?int $userId): ?array
    {
        $args = [$assignmentId, $assignmentId, $attemptId]; $sql = 'SELECT id,exercise_id,exercise_revision,assignment_id,user_id,result_status,result_passed,result_score,project_json,result_json,created_at FROM ' . $this->table('attempts') . ' WHERE assignment_id=%d AND exercise_id=(SELECT exercise_id FROM ' . $this->table('assignments') . ' WHERE id=%d) AND id=%d';
        if ($userId !== null) { $sql .= ' AND user_id=%d'; $args[] = $userId; }
        $rows = $this->rows($this->db->get_results($this->db->prepare($sql . ' LIMIT 1', ...$args), ARRAY_A));
        return $rows === [] ? null : $this->attemptMetadata($rows[0]) + ['project' => $this->decode($rows[0]['project_json']), 'rawResult' => $this->decode($rows[0]['result_json'])];
    }
    public function lessonResults(int $assignmentId, array $userIds): array
    {
        if (!array_is_list($userIds) || count($userIds) > 50 || count(array_unique($userIds, SORT_REGULAR)) !== count($userIds)) { throw new InvalidArgumentException('수업 결과 명단 범위를 확인해 주세요.'); }
        foreach ($userIds as $id) { if (!is_int($id) || $id < 1) { throw new InvalidArgumentException('학생 번호를 확인해 주세요.'); } }
        if ($userIds === []) { return []; }
        $slots = implode(',', array_fill(0, count($userIds), '%d'));
        $sql = 'SELECT assignment_id,user_id,attempt_id,exercise_revision,result_status,result_passed,result_score,updated_at FROM ' . $this->table('lesson_results') . ' WHERE assignment_id=%d AND user_id IN (' . $slots . ') ORDER BY user_id LIMIT ' . count($userIds);
        $rows = $this->rows($this->db->get_results($this->db->prepare($sql, $assignmentId, ...$userIds), ARRAY_A)); $result = [];
        foreach ($rows as $r) { $result[(int) $r['user_id']] = ['id' => (int) $r['attempt_id'], 'exerciseRevision' => (int) $r['exercise_revision'], 'result' => ['status' => $r['result_status'], 'passed' => $r['result_passed'] === null ? null : (bool) $r['result_passed'], 'score' => $r['result_score'] === null ? null : (float) $r['result_score']], 'createdAt' => $r['updated_at']]; }
        return $result;
    }
}
