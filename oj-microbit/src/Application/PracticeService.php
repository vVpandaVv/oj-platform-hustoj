<?php
declare(strict_types=1);
namespace OjMicrobit\Application;

use DomainException;
use InvalidArgumentException;
use OjMicrobit\Domain\ExerciseSchema;
use OjMicrobit\Domain\ProjectSchema;
use OjMicrobit\Domain\PublicationReview;
use OjMicrobit\Domain\ResultVisibility;
use OjMicrobit\Support\OperationError;

final class PracticeService
{
    public function __construct(private readonly Repository $records, private readonly Access $access, private readonly Runner $runner) {}

    public function teacherExercises(int $beforeId = 0, int $limit = 20): array
    {
        $this->access->requireTeacher();
        $this->validatePage($beforeId, $limit);
        return $this->page($this->records->exercises($this->access->isAdmin() ? null : $this->access->actorId(), false, $beforeId, $limit + 1), $limit);
    }
    public function teacherExercise(int $id): array { return $this->owned($id); }
    public function teacherGroups(string $cursor = '', int $limit = 20): array
    {
        $this->access->requireTeacher();
        return $this->access->teachingGroups($cursor, $limit);
    }
    public function teacherClasses(string $cursor = '', int $limit = 20): array
    {
        $this->access->requireTeacher();
        return $this->access->teachingClasses($cursor, $limit);
    }
    public function reviewExercise(array $input): array
    {
        $this->access->requireTeacher();
        $existing = null;
        if (isset($input['id'])) {
            if (!is_int($input['id']) || $input['id'] < 1) { throw new InvalidArgumentException('과제 번호를 확인해 주세요.'); }
            $existing = $this->owned($input['id']);
        }
        if ($existing !== null && !array_key_exists('audience', $input)) { $input['audience'] = $existing['audience']; }
        $normalized = ExerciseSchema::normalize(PublicationReview::prepareInput($input));
        $this->validateAudience($normalized, $existing);
        return PublicationReview::describe($normalized, $input['publicationReview']['starterMode'] ?? null);
    }
    public function saveExercise(array $input): array
    {
        $this->access->requireTeacher();
        $id = $input['id'] ?? null;
        $revision = $input['revision'] ?? 0;
        if (($id !== null && (!is_int($id) || $id < 1)) || !is_int($revision) || $revision < 0) {
            throw new InvalidArgumentException('과제 번호와 저장 버전을 확인해 주세요.');
        }
        $owner = $this->access->actorId(); $existing = null;
        if ($id !== null) { $existing = $this->owned($id); $owner = (int) $existing['ownerId']; }
        if ($existing !== null && !array_key_exists('audience', $input)) { $input['audience'] = $existing['audience']; }
        $normalized = ExerciseSchema::normalize(PublicationReview::prepareInput($input));
        $this->validateAudience($normalized, $existing);
        PublicationReview::requireConfirmed($normalized, $input['publicationReview'] ?? null);
        $saved = $this->records->saveExercise($normalized, $owner, $id, $revision);
        $this->audit('exercise_saved', ['exerciseId' => $saved['id'], 'revision' => $saved['revision'], 'status' => $saved['status']]);
        return $saved;
    }
    public function deleteExercise(int $id, array $input): array
    {
        $exercise = $this->owned($id);
        $revision = $input['revision'] ?? null;
        if (!is_int($revision) || $revision !== $exercise['revision']) { throw new OperationError('conflict', 409, '과제가 변경되었습니다. 최신 내용을 다시 불러와 주세요.'); }
        $this->records->archiveExercise($id, $revision);
        $this->audit('exercise_archived', ['exerciseId' => $id]);
        return ['deleted' => true];
    }
    public function exercises(int $beforeId = 0, int $limit = 20): array
    {
        $this->access->requireLearner();
        $this->validatePage($beforeId, $limit);
        $groups = $this->access->learnerGroupIds();
        $classes = $this->access->learnerClassIds();
        return $this->page($this->records->learnerExercises($classes ?? [], $beforeId, $limit + 1, $groups ?? []), $limit) + [
            'groupAccessAvailable' => $groups !== null, 'classAccessAvailable' => $classes !== null,
            'audienceWarning' => $groups === null || $classes === null ? '일부 그룹(학급) 또는 수업 소속을 확인하지 못해 확인된 배포 대상과 사이트 공개 문제만 표시합니다.' : null];
    }
    public function exercise(int $id): array { return $this->publicExercise($this->readable($id)); }
    public function project(int $id): array
    {
        $exercise = $this->summaryAccess($id, false);
        $saved = $this->records->project($id, $this->access->actorId());
        if ($saved === null) { $exercise = $this->readable($id); }
        return ['project' => ProjectSchema::normalize($saved['project'] ?? $exercise['starterProject']), 'revision' => $saved['revision'] ?? 0, 'exerciseRevision' => $exercise['revision']];
    }
    public function saveProject(int $id, array $input): array
    {
        foreach (['assignmentId', 'classId', 'exerciseId'] as $key) { if (array_key_exists($key, $input)) { throw new InvalidArgumentException('수업 제출은 수업 문제 링크에서 진행해 주세요.'); } }
        $exercise = $this->summaryAccess($id, false);
        $revision = $input['revision'] ?? null;
        if (!is_int($revision) || $revision < 0 || !is_array($input['project'] ?? null)) { throw new InvalidArgumentException('프로젝트와 저장 버전을 확인해 주세요.'); }
        $saved = $this->records->saveProject($id, $this->access->actorId(), ProjectSchema::normalize($input['project']), $revision);
        return $saved + ['exerciseRevision' => $exercise['revision']];
    }
    public function run(int $id, array $input): array
    {
        foreach (['assignmentId', 'classId', 'exerciseId'] as $key) { if (array_key_exists($key, $input)) { throw new InvalidArgumentException('수업 제출은 수업 문제 링크에서 진행해 주세요.'); } }
        $exercise = $this->readable($id);
        if (!is_array($input['project'] ?? null)) { throw new InvalidArgumentException('채점할 프로젝트를 확인해 주세요.'); }
        $project = ProjectSchema::normalize($input['project']);
        return $this->records->withRunLease($this->access->actorId(), function () use ($id, $exercise, $project): array {
            // Only the server's saved scenario is sent. Client scores, traces and assertions are never accepted.
            $result = $this->runner->run($project, $exercise);
            $result['_visibility'] = array_column($exercise['scenarios'], 'visible', 'id');
            $this->summaryAccess($id, false); // Recheck publication and access without loading project/scenario JSON again.
            $attemptId = $this->records->saveAttempt($id, $exercise['revision'], $this->access->actorId(), $project, $result);
            $this->audit('graded', ['exerciseId' => $id, 'attemptId' => $attemptId, 'revision' => $exercise['revision']]);
            return $this->publicResult($result, $exercise) + ['id' => $attemptId, 'exerciseRevision' => $exercise['revision']];
        });
    }
    public function preview(int $id, array $input): array
    {
        foreach (['assignmentId', 'classId', 'exerciseId'] as $key) { if (array_key_exists($key, $input)) { throw new InvalidArgumentException('수업 제출은 수업 문제 링크에서 진행해 주세요.'); } }
        $exercise = $this->readable($id);
        if (!is_array($input['project'] ?? null) || !is_array($input['inputs'] ?? null)) { throw new InvalidArgumentException('시뮬레이션할 프로젝트와 입력을 확인해 주세요.'); }
        $project = ProjectSchema::normalize($input['project']);
        // Validate arbitrary student inputs against the published connection contract.
        $preview = ExerciseSchema::normalize([
            'title' => '직접 실험', 'description' => '', 'status' => 'draft',
            'connections' => $exercise['connections'], 'i2cDevices' => $exercise['i2cDevices'] ?? [], 'starterProject' => $project,
            'scenarios' => [['id' => 'preview', 'name' => '직접 실험', 'visible' => true,
                'durationMs' => $input['durationMs'] ?? 2000, 'inputs' => $input['inputs'], 'assertions' => [],
                'i2cInputs' => array_key_exists('i2cInputs', $input) ? $input['i2cInputs'] : [], 'i2cAssertions' => [], 'outputSequences' => []]],
        ]);
        return $this->records->withRunLease($this->access->actorId(), function () use ($id, $project, $preview): array {
            $result = $this->runner->run($project, $preview);
            $this->summaryAccess($id, false);
            return $result + ['preview' => true];
        });
    }
    public function attempts(int $id, bool $teacher = false, int $beforeId = 0, int $limit = 20): array
    {
        $this->summaryAccess($id, $teacher); $this->validatePage($beforeId, $limit);
        return $this->page($this->records->attempts($id, $teacher ? null : $this->access->actorId(), $beforeId, $limit + 1), $limit);
    }
    public function attempt(int $id, int $attemptId, bool $teacher = false): array
    {
        $this->summaryAccess($id, $teacher);
        $row = $this->records->attempt($id, $attemptId, $teacher ? null : $this->access->actorId());
        if ($row === null) { throw new OperationError('not_found', 404, '채점 기록을 볼 권한이 없거나 기록이 없습니다.'); }
        if (!$teacher) { $row['result'] = ResultVisibility::learner($row['result']); }
        return $row;
    }
    private function summaryAccess(int $id, bool $teacher): array
    {
        $teacher ? $this->access->requireTeacher() : $this->access->requireLearner();
        $exercise = $this->records->exerciseSummary($id);
        if ($exercise === null || ($teacher && !$this->access->isAdmin() && $exercise['ownerId'] !== $this->access->actorId())
            || (!$teacher && $exercise['status'] !== 'published' && !$this->access->isAdmin() && $exercise['ownerId'] !== $this->access->actorId())) {
            throw new OperationError('access_denied', 403, '이 과제에 접근할 권한이 없습니다.');
        }
        if (!$teacher) { $this->requireAudienceAccess($exercise); }
        return $exercise;
    }
    private function validatePage(int $beforeId, int $limit): void
    {
        if ($beforeId < 0 || $limit < 1 || $limit > 50) { throw new InvalidArgumentException('목록 기준 번호와 페이지 크기(1~50)를 확인해 주세요.'); }
    }
    private function page(array $rows, int $limit): array
    {
        $hasMore = count($rows) > $limit; $items = array_slice($rows, 0, $limit);
        return ['items' => $items, 'nextBeforeId' => $hasMore ? (int) $items[array_key_last($items)]['id'] : null];
    }
    public function status(): array
    {
        $this->access->requireLearner();
        return $this->runner->status();
    }
    private function owned(int $id): array
    {
        $this->access->requireTeacher();
        $exercise = $this->records->exercise($id);
        if ($exercise === null || (!$this->access->isAdmin() && $exercise['ownerId'] !== $this->access->actorId())) {
            throw new OperationError('access_denied', 403, '이 과제를 관리할 권한이 없습니다.');
        }
        return $this->normalizedExercise($exercise);
    }
    private function readable(int $id): array
    {
        $this->access->requireLearner();
        $exercise = $this->records->exercise($id);
        if ($exercise === null || ($exercise['status'] !== 'published' && !$this->access->isAdmin() && $exercise['ownerId'] !== $this->access->actorId())) {
            throw new OperationError('access_denied', 403, '공개되지 않았거나 접근할 수 없는 과제입니다.');
        }
        $this->requireAudienceAccess($exercise);
        return $this->normalizedExercise($exercise);
    }
    private function validateAudience(array $exercise, ?array $existing): void
    {
        if ($exercise['audience']['mode'] !== 'site' && ($existing === null || $existing['audience'] !== $exercise['audience'])) {
            throw new InvalidArgumentException('exercise.audience: 새 문제는 전체 공개 범위로 작성합니다. 수업 적용은 공개 후 수업 문제에서 별도로 설정해 주세요.');
        }
        if ($exercise['audience']['mode'] === 'groups') { $this->access->requireGroupManagement($exercise['audience']['groupIds']); }
        if ($exercise['audience']['mode'] === 'classes') { $this->access->requireClassManagement($exercise['audience']['classIds']); }
    }
    private function requireAudienceAccess(array $exercise): void
    {
        if ($this->access->isAdmin() || $exercise['ownerId'] === $this->access->actorId()) { return; }
        $audience = ExerciseSchema::audience($exercise['audience'] ?? ['mode' => 'site', 'classIds' => []]);
        if ($audience['mode'] === 'groups') { $this->access->requireGroupParticipation($audience['groupIds']); }
        if ($audience['mode'] === 'classes') { $this->access->requireClassParticipation($audience['classIds']); }
    }
    private function normalizedExercise(array $exercise): array
    {
        // Hydrate optional protocol fields for records created before this version.
        return ExerciseSchema::normalize($exercise) + array_intersect_key($exercise, array_flip(['id', 'ownerId', 'revision', 'updatedAt']));
    }
    private function publicExercise(array $exercise): array
    {
        $exercise['scenarios'] = array_values(array_filter($exercise['scenarios'], static fn (array $scenario): bool => $scenario['visible'] === true));
        return $exercise;
    }
    private function publicResult(array $result, array $exercise): array
    {
        return ResultVisibility::learner($result);
    }
    private function audit(string $event, array $metadata): void
    {
        if (function_exists('do_action')) { do_action('oj_microbit_audit', $event, $this->access->actorId(), $metadata); }
        if (function_exists('oj_log_audit_event')) { oj_log_audit_event('microbit.' . $event, '마이크로비트 활동', $metadata); }
    }
}
