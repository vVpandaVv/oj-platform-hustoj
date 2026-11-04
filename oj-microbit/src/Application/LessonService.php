<?php
declare(strict_types=1);
namespace OjMicrobit\Application;

use InvalidArgumentException;
use OjMicrobit\Domain\ExerciseSchema;
use OjMicrobit\Domain\ProjectSchema;
use OjMicrobit\Domain\ResultVisibility;
use OjMicrobit\Support\OperationError;

/** Publication is independent of lesson use. Only explicit assignment routes create lesson records. */
final class LessonService
{
    public function __construct(private readonly LessonRepository $lessons, private readonly Repository $records, private readonly Access $access, private readonly LessonAccess $rosters, private readonly Runner $runner) {}
    private function page(array $rows, int $limit): array
    {
        $more = count($rows) > $limit; $items = array_slice($rows, 0, $limit);
        return ['items' => $items, 'nextBeforeId' => $more ? (int) $items[array_key_last($items)]['id'] : null];
    }
    private function validatePage(int $before, int $limit): void
    { if ($before < 0 || $limit < 1 || $limit > 50) { throw new InvalidArgumentException('목록 기준 번호와 페이지 크기를 확인해 주세요.'); } }
    public function publicExercises(int $beforeId = 0, int $limit = 20, bool $teacher = false): array
    {
        $teacher ? $this->access->requireTeacher() : $this->access->requireLearner(); $this->validatePage($beforeId, $limit);
        return $this->page($this->lessons->publicExercises($beforeId, $limit + 1), $limit);
    }
    public function teacherAssignments(int $classId, int $beforeId = 0, int $limit = 20): array
    {
        $this->access->requireTeacher(); $this->validatePage($beforeId, $limit);
        $title = $this->rosters->lessonTitle($classId, true); $rows = $this->lessons->assignments([$classId], $beforeId, $limit + 1, false);
        foreach ($rows as &$row) { $row['classTitle'] = $title; } unset($row); return $this->page($rows, $limit);
    }
    /** One explicit native class page, with fresh participation authority and no membership-list fanout. */
    public function classAssignments(int $classId, int $beforeId = 0, int $limit = 20): array
    {
        $this->access->requireLearner(); $this->validatePage($beforeId, $limit);
        if ($classId < 1) { throw new InvalidArgumentException('수업 번호를 확인해 주세요.'); }
        $title = $this->rosters->lessonTitle($classId, false);
        $rows = $this->lessons->assignments([$classId], $beforeId, $limit + 1);
        foreach ($rows as &$row) { $row['classTitle'] = $title; } unset($row);
        return $this->page($rows, $limit);
    }
    public function assignments(int $beforeId = 0, int $limit = 20): array
    {
        $this->access->requireLearner(); $this->validatePage($beforeId, $limit); $contexts = $this->rosters->learningLessons(); $classes = $contexts === null ? null : array_column($contexts, 'id');
        $titles = $contexts === null ? [] : array_column($contexts, 'title', 'id');
        $rows = $this->lessons->assignments($classes ?? [], $beforeId, $limit + 1);
        foreach ($rows as &$row) { $row['classTitle'] = $titles[$row['classId']] ?? '수업 #' . $row['classId']; } unset($row);
        return $this->page($rows, $limit)
            + ['classAccessAvailable' => $classes !== null, 'audienceWarning' => $classes === null ? '수업 소속을 확인하지 못했습니다. 공개 문제는 계속 풀 수 있습니다.' : null];
    }
    public function saveAssignment(int $classId, array $input): array
    {
        $this->managedClass($classId); $id = $input['exerciseId'] ?? null;
        if (!is_int($id) || $id < 1 || ($input['revision'] ?? 0) !== 0) { throw new InvalidArgumentException('적용할 공개 문제 번호를 확인해 주세요.'); }
        $this->requirePublicSource($id, false);
        $saved = $this->lessons->saveAssignment($classId, $id, $this->access->actorId());
        $this->audit('lesson_assigned', ['assignmentId' => $saved['id'], 'exerciseId' => $id, 'classId' => $classId]); return $saved;
    }
    public function archiveAssignment(int $id, array $input): array
    {
        $assignment = $this->context($id, true, false); $revision = $input['revision'] ?? null;
        if (!is_int($revision) || $revision !== $assignment['revision']) { throw new OperationError('conflict', 409, '수업 적용 상태가 바뀌었습니다. 최신 목록을 확인해 주세요.'); }
        $this->lessons->archiveAssignment($id, $revision); $this->audit('lesson_unassigned', ['assignmentId' => $id, 'classId' => $assignment['classId']]);
        return ['deleted' => true, 'recordsRetained' => true];
    }
    public function assignment(int $id, bool $teacher = false): array
    {
        $assignment = $this->context($id, $teacher, false); $exercise = $this->publicSource($assignment['exerciseId'], true);
        return ['assignment' => $assignment, 'exercise' => $exercise === null ? null : $this->publicProblem($exercise), 'sourceAvailable' => $exercise !== null, 'workAvailable' => !$assignment['archived'] && $exercise !== null];
    }
    private function managedClass(int $id): void
    {
        $this->access->requireTeacher(); if ($id < 1) { throw new InvalidArgumentException('수업 번호를 확인해 주세요.'); }
        $this->access->requireClassManagement([$id]);
    }
    private function context(int $id, bool $teacher, bool $working): array
    {
        $teacher ? $this->access->requireTeacher() : $this->access->requireLearner();
        $assignment = $this->lessons->assignment($id);
        if ($assignment === null) { throw new OperationError('not_found', 404, '수업 문제를 찾을 수 없습니다.'); }
        // Assignment creator and source author are not lesson access principals.
        $assignment['classTitle'] = $this->rosters->lessonTitle($assignment['classId'], $teacher);
        if ($working && $assignment['archived']) { throw new OperationError('access_denied', 403, '수업에서 적용 해제한 문제입니다. 이전 제출 기록을 확인할 수 있습니다.'); }
        return $assignment;
    }
    private function publicSource(int $id, bool $hydrate): ?array
    {
        $summary = $this->records->exerciseSummary($id);
        if ($summary === null || $summary['status'] !== 'published' || ($summary['audience']['mode'] ?? null) !== 'site') { return null; }
        if (!$hydrate) { return $summary; }
        $exercise = $this->records->exercise($id);
        if ($exercise === null || $exercise['status'] !== 'published' || ($exercise['audience']['mode'] ?? null) !== 'site') { return null; }
        $exercise = ExerciseSchema::normalize($exercise) + array_intersect_key($exercise, array_flip(['id', 'ownerId', 'revision', 'updatedAt']));
        return $exercise;
    }
    private function requirePublicSource(int $id, bool $hydrate): array
    {
        return $this->publicSource($id, $hydrate) ?? throw new OperationError('access_denied', 403, '현재 공개된 문제만 수업에서 풀고 채점할 수 있습니다. 기존 결과는 제출 내역에서 확인해 주세요.');
    }
    private function publicProblem(array $exercise): array
    {
        $exercise['scenarios'] = array_values(array_filter($exercise['scenarios'], static fn (array $row): bool => $row['visible'] === true)); return $exercise;
    }
    private function rejectBodyContext(array $input): void
    {
        foreach (['assignmentId', 'classId', 'exerciseId'] as $key) { if (array_key_exists($key, $input)) { throw new InvalidArgumentException('수업 문제 링크를 통해 다시 요청해 주세요.'); } }
    }
    public function project(int $id): array
    {
        $assignment = $this->context($id, false, true); $source = $this->requirePublicSource($assignment['exerciseId'], false);
        $saved = $this->lessons->lessonProject($id, $this->access->actorId());
        if ($saved === null) { $source = $this->requirePublicSource($assignment['exerciseId'], true); }
        return ['project' => ProjectSchema::normalize($saved['project'] ?? $source['starterProject']), 'revision' => $saved['revision'] ?? 0, 'exerciseRevision' => $source['revision'], 'assignmentId' => $id];
    }
    public function saveProject(int $id, array $input): array
    {
        $this->rejectBodyContext($input); $assignment = $this->context($id, false, true); $source = $this->requirePublicSource($assignment['exerciseId'], false);
        $revision = $input['revision'] ?? null;
        if (!is_int($revision) || $revision < 0 || !is_array($input['project'] ?? null)) { throw new InvalidArgumentException('수업 프로젝트와 저장 버전을 확인해 주세요.'); }
        return $this->lessons->saveLessonProject($assignment, $this->access->actorId(), ProjectSchema::normalize($input['project']), $revision) + ['assignmentId' => $id, 'exerciseRevision' => $source['revision']];
    }
    public function run(int $id, array $input): array
    {
        $this->rejectBodyContext($input); $assignment = $this->context($id, false, true); $exercise = $this->requirePublicSource($assignment['exerciseId'], true);
        if (!is_array($input['project'] ?? null)) { throw new InvalidArgumentException('제출할 수업 프로젝트를 확인해 주세요.'); } $project = ProjectSchema::normalize($input['project']);
        return $this->records->withRunLease($this->access->actorId(), function () use ($id, $assignment, $exercise, $project): array {
            $result = $this->runner->run($project, $exercise); $result['_visibility'] = array_column($exercise['scenarios'], 'visible', 'id');
            $current = $this->context($id, false, true); $this->requirePublicSource($assignment['exerciseId'], false);
            if ($current['revision'] !== $assignment['revision'] || $current['classId'] !== $assignment['classId'] || $current['exerciseId'] !== $assignment['exerciseId']) { throw new OperationError('conflict', 409, '채점 중 수업 적용이 변경됐습니다. 수업 문제를 다시 열어 제출해 주세요.'); }
            $attempt = $this->lessons->saveLessonAttempt($assignment, $exercise['revision'], $this->access->actorId(), $project, $result);
            $this->audit('lesson_graded', ['assignmentId' => $id, 'attemptId' => $attempt, 'classId' => $assignment['classId'], 'exerciseId' => $assignment['exerciseId']]);
            return ResultVisibility::learner($result) + ['id' => $attempt, 'exerciseRevision' => $exercise['revision'], 'assignmentId' => $id];
        });
    }
    public function preview(int $id, array $input): array
    {
        $this->rejectBodyContext($input); $assignment = $this->context($id, false, true); $exercise = $this->requirePublicSource($assignment['exerciseId'], true);
        if (!is_array($input['project'] ?? null) || !is_array($input['inputs'] ?? null)) { throw new InvalidArgumentException('실험할 수업 프로젝트와 입력값을 확인해 주세요.'); }
        $project = ProjectSchema::normalize($input['project']);
        $preview = ExerciseSchema::normalize(['title' => '수업 직접 실험', 'status' => 'draft', 'description' => '', 'connections' => $exercise['connections'], 'i2cDevices' => $exercise['i2cDevices'], 'starterProject' => $project, 'scenarios' => [['id' => 'preview', 'name' => '직접 실험', 'visible' => true, 'durationMs' => $input['durationMs'] ?? 2000, 'inputs' => $input['inputs'], 'assertions' => [], 'i2cInputs' => $input['i2cInputs'] ?? [], 'i2cAssertions' => [], 'outputSequences' => []]]]);
        return $this->records->withRunLease($this->access->actorId(), function () use ($id, $assignment, $project, $preview): array {
            $result = $this->runner->run($project, $preview); $current = $this->context($id, false, true); $this->requirePublicSource($assignment['exerciseId'], false);
            if ($current['revision'] !== $assignment['revision']) { throw new OperationError('conflict', 409, '실험 중 수업 적용이 변경됐습니다. 다시 열어 주세요.'); }
            return $result + ['preview' => true, 'assignmentId' => $id];
        });
    }
    public function attempts(int $id, bool $teacher = false, int $beforeId = 0, int $limit = 20): array
    {
        $this->context($id, $teacher, false); $this->validatePage($beforeId, $limit);
        return $this->page($this->lessons->lessonAttempts($id, $teacher ? null : $this->access->actorId(), $beforeId, $limit + 1), $limit);
    }
    public function attempt(int $id, int $attemptId, bool $teacher = false): array
    {
        $this->context($id, $teacher, false); $row = $this->lessons->lessonAttempt($id, $attemptId, $teacher ? null : $this->access->actorId());
        if ($row === null) { throw new OperationError('not_found', 404, '이 수업의 제출 기록을 찾을 수 없습니다.'); }
        // Lesson managers see student submissions, never another author's hidden test definitions.
        $row['result'] = ResultVisibility::learner($row['rawResult']); unset($row['rawResult']); return $row;
    }
    public function results(int $id, string $cursor = '', int $limit = 20, ?string $contextRevision = null, ?string $rosterRevision = null): array
    {
        $assignment = $this->context($id, true, false); $this->validatePage(0, $limit);
        $page = $this->rosters->lessonRoster($assignment['classId'], $cursor, $limit, $contextRevision, $rosterRevision);
        $latest = $this->lessons->lessonResults($id, array_column($page['items'], 'userId'));
        foreach ($page['items'] as &$learner) { $learner['latest'] = $latest[$learner['userId']] ?? null; } unset($learner);
        $this->context($id, true, false);
        return ['assignment' => $assignment] + $page;
    }
    private function audit(string $event, array $data): void
    {
        if (function_exists('do_action')) { do_action('oj_microbit_audit', $event, $this->access->actorId(), $data); }
        if (function_exists('oj_log_audit_event')) { oj_log_audit_event('microbit.' . $event, '마이크로비트 수업 활동', $data); }
    }
}
