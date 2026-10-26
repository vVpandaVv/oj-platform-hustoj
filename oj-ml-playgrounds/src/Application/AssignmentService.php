<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Application;

use DateTimeImmutable;
use DateTimeZone;
use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Domain\AssignmentStatus;
use OJML\Playgrounds\Domain\Problem;
use OJML\Playgrounds\Domain\PortfolioTemplate;

final class AssignmentService
{
    private const TASK_TYPES = ['classification', 'regression', 'clustering'];
    private const STATUSES = ['draft', 'published', 'closed', 'archived'];
    private const MAX_AUTHORIZED_ASSIGNMENT_SCAN = 10000;
    private bool $learnerHasMore = false;

    public function __construct(
        private readonly Repository $repository,
        private readonly ContextService $contexts,
        private readonly ?TeachingDatasetService $datasets = null
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function listForLearner(int $userId, int $page = 1): array
    {
        $items = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $rows = $this->repository->listPublishedAssignmentsForLearner($userId, 201, (max(1, $page) - 1) * 200);
        $this->learnerHasMore = count($rows) > 200;
        foreach (array_slice($rows, 0, 200) as $assignment) {
            try {
                $this->contexts->assertAssignmentAccess($userId, $assignment, 'participate', $userId);
            } catch (Problem $problem) {
                if ($this->shouldSkipStaleContextProblem($problem)) {
                    if (($assignment['has_submission_history'] ?? false) !== true) {
                        continue;
                    }
                    $assignment['context_unavailable'] = true;
                } else {
                    throw $problem;
                }
            }
            $assignment = $this->decorateForLearnerList($assignment, $now);
            if (!$this->shouldIncludeForLearner($assignment)) {
                continue;
            }
            $items[] = $assignment;
        }

        $summaries = $this->repository->latestSubmittedAttemptSummariesForLearner(
            $userId,
            array_map(static fn (array $assignment): int => (int) $assignment['id'], $items)
        );
        foreach ($items as &$assignment) {
            $assignment['latest_attempt_summary'] = $summaries[(int) $assignment['id']] ?? null;
        }
        unset($assignment);

        return $items;
    }

    public function learnerHasMore(): bool
    {
        return $this->learnerHasMore;
    }

    /** @return array<string,mixed> */
    public function getForLearner(int $assignmentId, int $userId): array
    {
        $assignment = $this->requireAssignment($assignmentId);
        if (!in_array($assignment['status'], [AssignmentStatus::PUBLISHED, AssignmentStatus::CLOSED], true)) {
            throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
        }
        $this->assertLearnerAccess($assignment, $userId);
        $assignment = $this->repository->assignmentForLearner($assignment, $userId);
        $assignment = $this->decorateForLearner(
            $assignment,
            $userId,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
        if (!$this->shouldIncludeForLearner($assignment)) {
            throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
        }
        return $assignment;
    }

    /** @param array<string,mixed> $input @return array{attempt:array<string,mixed>,created:bool} */
    public function createAttempt(int $assignmentId, int $userId, array $input): array
    {
        $assignment = $this->getForLearner($assignmentId, $userId);
        if ($assignment['status'] !== AssignmentStatus::PUBLISHED) {
            throw new Problem('OJ_ML_ASSIGNMENT_CLOSED', '새 시도를 시작할 수 없는 과제입니다.', 409);
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($assignment['opens_at'] !== null && $assignment['opens_at'] > $now) {
            throw new Problem('OJ_ML_ASSIGNMENT_NOT_OPEN', '아직 시작되지 않은 과제입니다.', 409);
        }
        $allowLate = ($assignment['policy']['allowLateSubmission'] ?? false) === true;
        if ($assignment['due_at'] !== null && $assignment['due_at'] < $now && !$allowLate) {
            throw new Problem('OJ_ML_ASSIGNMENT_CLOSED', '제출 기한이 지난 과제입니다.', 409);
        }
        $rawKey = isset($input['idempotencyKey']) && is_string($input['idempotencyKey'])
            ? trim($input['idempotencyKey'])
            : '';
        if (strlen($rawKey) > 200) {
            throw new Problem('OJ_ML_IDEMPOTENCY_KEY_INVALID', '멱등성 키가 너무 깁니다.', 400);
        }
        $appVersion = isset($input['clientAppVersion']) && is_string($input['clientAppVersion'])
            ? substr(sanitize_text_field($input['clientAppVersion']), 0, 64)
            : null;
        $formatVersion = isset($input['formatVersion']) && is_numeric($input['formatVersion'])
            ? (int) $input['formatVersion']
            : null;
        if ($formatVersion !== null && $formatVersion < 1) {
            throw new Problem('OJ_ML_FORMAT_VERSION_INVALID', '형식 버전이 올바르지 않습니다.', 400);
        }
        return $this->repository->createAttempt(
            $assignmentId,
            $userId,
            $rawKey === '' ? null : hash('sha256', $rawKey),
            $appVersion === '' ? null : $appVersion,
            $formatVersion
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveProgress(string $attemptUuid, int $userId, array $input): array
    {
        $attempt = $this->repository->getOwnedAttempt($this->uuid($attemptUuid), $userId);
        if ($attempt === null) {
            throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
        }
        $assignment = $this->requireAssignment((int) $attempt['assignment_id']);
        $this->assertLearnerAccess($assignment, $userId);
        $phase = isset($input['phase']) && is_string($input['phase']) ? sanitize_key($input['phase']) : 'working';
        if (!in_array($phase, ['working', 'training', 'evaluating', 'saving', 'uploading'], true)) {
            throw new Problem('OJ_ML_PROGRESS_PHASE_INVALID', '진행 단계가 올바르지 않습니다.', 400);
        }
        $progress = ['phase' => $phase, 'reportedAt' => gmdate(DATE_ATOM)];
        if (isset($input['percent']) && is_numeric($input['percent'])) {
            $progress['percent'] = max(0, min(100, (float) $input['percent']));
        }
        if (isset($input['checkpoint']) && is_string($input['checkpoint'])) {
            $progress['checkpoint'] = substr(sanitize_text_field($input['checkpoint']), 0, 200);
        }
        return $this->repository->updateProgress($attemptUuid, $userId, $progress);
    }

    /** @return array<string,mixed> */
    public function ownResult(string $attemptUuid, int $userId): array
    {
        $result = $this->repository->getAttemptResult($this->uuid($attemptUuid), $userId);
        if ($result === null) {
            throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
        }
        $assignment = $this->requireAssignment((int) $result['assignment_id']);
        if ($assignment['status'] === AssignmentStatus::DRAFT) {
            throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
        }
        // Received records belong to their submitting learner, even after roster changes.
        if ($result['received_at'] === null) {
            $this->assertLearnerAccess($assignment, $userId);
        }
        return $result;
    }

    public function attemptHistory(int $assignmentId, int $userId, int $actorId, int $page, bool $teacher): array
    {
        $assignment = $this->requireAssignment($assignmentId);
        if ($teacher) {
            $this->contexts->assertAssignmentAccess($actorId, $assignment, 'report', $userId);
        } elseif ($actorId !== $userId || !$this->repository->hasSubmissionHistory($assignmentId, $userId)) {
            $this->assertLearnerAccess($assignment, $actorId);
            if ($actorId !== $userId) {
                throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '제출 기록을 찾을 수 없습니다.', 404);
            }
        }
        $result = $this->repository->listAttemptHistory($assignmentId, $userId, $page, 20, !$teacher);
        if ($teacher) {
            $canEvaluate = $this->contextMayEvaluate($actorId, $assignment, $userId);
            foreach ($result['items'] as &$attempt) { $attempt['context_may_evaluate'] = $canEvaluate; $attempt['rubric'] = $assignment['rubric'] ?? []; }
            unset($attempt);
        }
        return $result;
    }

    public function grantAllowance(int $assignmentId, int $userId, int $actorId, array $input, string $correlationId): array
    {
        $this->teacherAssignment($assignmentId, $actorId, 'manage');
        $revision = filter_var($input['expectedRevision'] ?? null, FILTER_VALIDATE_INT);
        $maximum = array_key_exists('maxAttempts', $input) ? filter_var($input['maxAttempts'], FILTER_VALIDATE_INT) : null;
        $reason = is_string($input['reason'] ?? null) ? trim(sanitize_text_field($input['reason'])) : '';
        $dueAt = $this->mysqlUtc($input['dueAt'] ?? null, 'dueAt');
        if ($userId < 1 || $revision === false || $revision < 0 || $maximum === false || ($maximum === null && $dueAt === null) || $reason === '' || strlen($reason) > 1000) {
            throw new Problem('OJ_ML_ALLOWANCE_INVALID', '변경 사유와 학생별 최대 시도 또는 마감 시각을 입력하세요.', 400);
        }
        return $this->repository->grantAllowance($assignmentId, $userId, $revision, $maximum, $dueAt, $reason, $actorId, $correlationId);
    }

    public function duplicateAssignment(int $assignmentId, int $actorId): array
    {
        $source = $this->teacherAssignment($assignmentId, $actorId, 'manage');
        return $this->createDraft([
            'contextType' => $source['context_type'], 'contextId' => $source['context_id'],
            'title' => $source['title'], 'description' => $source['description'], 'taskType' => $source['task_type'],
            'policy' => $source['policy'], 'rubric' => $source['rubric'], 'maxAttempts' => $source['max_attempts'],
            'opensAt' => null, 'dueAt' => null,
        ], $actorId, $source);
    }

    /** @return array{items:list<array<string,mixed>>,nextCursor:string} */
    public function teachingContexts(int $actorId, string $search, string $cursor, int $limit): array
    {
        return $this->contexts->creationGroups($actorId, $search, $cursor, $limit);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createAssignment(array $input, int $actorId): array
    {
        return $this->createDraft($input, $actorId);
    }

    private function createDraft(array $input, int $actorId, ?array $source = null): array
    {
        [$contextType, $contextId] = $this->contextFrom($input);
        $this->assertCreationGroup($contextType);
        $snapshot = $this->contexts->assignmentSnapshot($actorId, $contextType, $contextId);
        $data = $this->normalizeAssignment($input, true, null, $actorId, $source);
        $data += [
            'context_type' => $contextType,
            'context_id' => $contextId,
            'context_revision' => $snapshot['context_revision'],
            'roster_revision' => $snapshot['roster_revision'],
            'targets' => [['type' => $contextType, 'key' => $contextId]],
            'learners' => $snapshot['learners'],
            'created_by' => $actorId,
        ];
        return $this->repository->createAssignment($data);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function updateAssignment(int $assignmentId, array $input, int $actorId): array
    {
        $current = $this->teacherAssignment($assignmentId, $actorId, 'manage');
        $expectedRevision = $this->positiveInt($input['expectedRevision'] ?? null, 'expectedRevision');
        if (array_key_exists('status', $input)) {
            $allowedKeys = ['expectedRevision', 'status'];
            if (array_diff(array_keys($input), $allowedKeys) !== []) {
                throw new Problem('OJ_ML_ASSIGNMENT_IMMUTABLE', '상태 변경과 내용 변경을 동시에 수행할 수 없습니다.', 409);
            }
            $status = is_string($input['status']) ? sanitize_key($input['status']) : '';
            if ($status === AssignmentStatus::PUBLISHED) {
                $this->contexts->assertAssignmentAccess($actorId, $current, 'assign');
            }
            return $this->repository->transitionAssignment($assignmentId, $expectedRevision, $status, $actorId);
        }
        unset($input['expectedRevision']);
        if ($current['status'] === AssignmentStatus::PUBLISHED) {
            $changes = $this->normalizePublishedChanges($input, $current);
            return $this->repository->updatePublishedAssignmentOperations(
                $assignmentId,
                $expectedRevision,
                $changes,
                $actorId
            );
        }
        if ($current['status'] !== AssignmentStatus::DRAFT) {
            throw new Problem('OJ_ML_ASSIGNMENT_IMMUTABLE', '이 상태의 과제 내용은 변경할 수 없습니다.', 409);
        }
        $changes = $this->normalizeAssignment($input, false, (string) $current['task_type'], $actorId, $current);
        if (array_key_exists('task_type', $changes) && !array_key_exists('policy', $changes)) {
            $existingPolicy = is_array($current['policy'] ?? null) ? $current['policy'] : [];
            $existingPolicy['allowedTaskTypes'] = [$changes['task_type']];
            $changes['policy'] = $this->normalizePolicy($existingPolicy, (string) $changes['task_type'], $actorId, $current);
        }
        $contextType = array_key_exists('contextType', $input)
            ? $this->contextType($input['contextType'])
            : (string) $current['context_type'];
        $contextId = array_key_exists('contextId', $input)
            ? $this->contextId($input['contextId'])
            : (string) $current['context_id'];
        if ($contextType !== $current['context_type'] || $contextId !== $current['context_id']) {
            $this->assertCreationGroup($contextType);
        }
        $snapshot = $this->contexts->assignmentSnapshot($actorId, $contextType, $contextId);
        $changes += [
            'context_type' => $contextType,
            'context_id' => $contextId,
            'context_revision' => $snapshot['context_revision'],
            'roster_revision' => $snapshot['roster_revision'],
            'targets' => [['type' => $contextType, 'key' => $contextId]],
            'learners' => $snapshot['learners'],
        ];
        $opensAt = array_key_exists('opens_at', $changes) ? $changes['opens_at'] : $current['opens_at'];
        $dueAt = array_key_exists('due_at', $changes) ? $changes['due_at'] : $current['due_at'];
        $this->assertDateOrder($opensAt, $dueAt);
        return $this->repository->updateAssignment($assignmentId, $expectedRevision, $changes, $actorId);
    }

    /** @return array<string,mixed> */
    public function publishAssignment(int $assignmentId, int $expectedRevision, int $actorId): array
    {
        $assignment = $this->requireAssignment($assignmentId);
        $this->contexts->assertAssignmentAccess($actorId, $assignment, 'assign');
        if ($expectedRevision < 1) {
            throw new Problem('OJ_ML_REVISION_INVALID', '과제 리비전이 올바르지 않습니다.', 400);
        }
        return $this->repository->publishAssignment($assignmentId, $expectedRevision, $actorId);
    }

    public function deleteAssignment(int $assignmentId, int $expectedRevision, int $actorId): void
    {
        $expectedRevision = $this->positiveInt($expectedRevision, 'expectedRevision');
        $this->teacherAssignment($assignmentId, $actorId, 'manage');
        $this->repository->deleteAssignment($assignmentId, $expectedRevision, $actorId);
    }

    /** @return array<string,mixed> */
    public function teacherAssignment(int $assignmentId, int $actorId, string $operation = 'view'): array
    {
        $assignment = $this->requireAssignment($assignmentId);
        $this->contexts->assertAssignmentAccess($actorId, $assignment, $operation);
        return $assignment;
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function teacherAssignments(int $actorId, int $page, int $perPage, ?string $status): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        // This collection-only filter is not an assignment lifecycle status.
        if ($status !== null && $status !== 'unarchived' && !in_array($status, self::STATUSES, true)) {
            throw new Problem('OJ_ML_ASSIGNMENT_STATUS_INVALID', '과제 상태가 올바르지 않습니다.', 400);
        }

        return $this->authorizedTeacherAssignments(
            $actorId,
            $page,
            $perPage,
            $status,
            'manage',
            true
        );
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function reportAssignments(int $actorId, int $page, int $perPage, ?string $status): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new Problem('OJ_ML_ASSIGNMENT_STATUS_INVALID', '과제 상태가 올바르지 않습니다.', 400);
        }

        return $this->authorizedTeacherAssignments(
            $actorId,
            $page,
            $perPage,
            $status,
            'report',
            false
        );
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function reportAssignmentOptions(int $actorId): array
    {
        return $this->authorizedTeacherAssignments(
            $actorId,
            1,
            self::MAX_AUTHORIZED_ASSIGNMENT_SCAN,
            null,
            'report',
            false,
            true
        );
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function teacherResults(
        int $assignmentId,
        int $actorId,
        int $page,
        int $perPage,
        ?string $status,
        ?int $userId,
        string $search = ''
    ): array {
        $assignment = $this->teacherAssignment($assignmentId, $actorId, 'report');
        if (($assignment['status'] ?? null) === AssignmentStatus::DRAFT) {
            throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
        }
        $resultPage = $this->repository->listResults(
            $assignmentId,
            max(1, $page),
            max(1, min(100, $perPage)),
            $status === '' ? null : $status,
            $userId !== null && $userId > 0 ? $userId : null,
            substr(sanitize_text_field($search), 0, 200)
        );
        foreach ($resultPage['items'] as &$row) {
            if (is_array($row['attempt'] ?? null)) { $row['attempt']['rubric'] = $assignment['rubric'] ?? []; }
            $row['context_may_evaluate'] = $this->contextMayEvaluate(
                $actorId,
                $assignment,
                (int) $row['user_id']
            );
        }
        unset($row);
        $resultPage['assignment'] = [
            'id' => $assignmentId, 'status' => $assignment['status'],
            'maxAttempts' => (int) $assignment['max_attempts'],
            'dueAt' => $assignment['due_at'],
        ];
        return $resultPage;
    }

    /** @return array<string,mixed> */
    public function teacherResult(string $attemptUuid, int $actorId, string $operation = 'view'): array
    {
        $result = $this->repository->getAttemptResult($this->uuid($attemptUuid));
        if ($result === null) {
            throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
        }
        $assignment = $this->requireAssignment((int) $result['assignment_id']);
        $learnerId = (int) $result['user_id'];
        $result['rubric'] = $assignment['rubric'] ?? [];
        $this->contexts->assertAssignmentAccess($actorId, $assignment, $operation, $learnerId);
        if ($operation === 'report') {
            $result['context_may_evaluate'] = $this->contextMayEvaluate($actorId, $assignment, $learnerId);
        }
        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function review(string $attemptUuid, int $actorId, array $input, string $correlationId): array
    {
        $attempt = $this->teacherResult($attemptUuid, $actorId, 'evaluate');
        $expectedVersion = isset($input['expectedVersion']) && is_numeric($input['expectedVersion'])
            ? (int) $input['expectedVersion']
            : -1;
        if ($expectedVersion < 0) {
            throw new Problem('OJ_ML_REVIEW_VERSION_INVALID', '검토 버전이 올바르지 않습니다.', 400);
        }
        $criterionReview = \OJML\Playgrounds\Domain\Rubric::review($attempt['rubric'] ?? [], $input);
        $score = $criterionReview !== null ? $criterionReview['score'] : $this->nullableScore($input['score'] ?? null);
        $maxScore = $criterionReview !== null ? $criterionReview['max_score'] : $this->nullableScore($input['maxScore'] ?? null);
        if (($score === null) !== ($maxScore === null) || ($score !== null && ($maxScore <= 0 || $score > $maxScore))) {
            throw new Problem('OJ_ML_REVIEW_SCORE_INVALID', '점수 범위가 올바르지 않습니다.', 400);
        }
        $feedback = isset($input['feedback']) && is_string($input['feedback']) ? wp_kses_post($input['feedback']) : '';
        if (strlen($feedback) > 50000) {
            throw new Problem('OJ_ML_REVIEW_FEEDBACK_TOO_LONG', '피드백이 너무 깁니다.', 400);
        }
        $review = $this->repository->appendReview((int) $attempt['id'], $actorId, $expectedVersion, [
            'status' => 'draft',
            'score' => $score,
            'max_score' => $maxScore,
            'feedback' => $feedback,
            'details' => $criterionReview['details'] ?? null,
        ], 'review.saved', $correlationId);
        return $review;
    }

    /** @return array<string,mixed> */
    public function releaseReview(string $attemptUuid, int $actorId, int $expectedVersion, string $correlationId): array
    {
        $attempt = $this->teacherResult($attemptUuid, $actorId, 'evaluate');
        if ($expectedVersion < 1) {
            throw new Problem('OJ_ML_REVIEW_VERSION_INVALID', '검토 버전이 올바르지 않습니다.', 400);
        }
        return $this->repository->releaseReview((int) $attempt['id'], $actorId, $expectedVersion, $correlationId);
    }

    /** @param array<string,mixed> $assignment */
    private function assertLearnerAccess(array $assignment, int $userId): void
    {
        if (!$this->repository->isActiveLearner((int) $assignment['id'], $userId)) {
            throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
        }
        try {
            $this->contexts->assertAssignmentAccess($userId, $assignment, 'participate', $userId);
        } catch (Problem $problem) {
            if ($problem->httpStatus() === 403) {
                throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
            }
            throw $problem;
        }
    }

    /** @param array<string,mixed> $assignment @return array<string,mixed> */
    private function decorateForLearner(array $assignment, int $userId, DateTimeImmutable $now): array
    {
        $assignmentId = (int) $assignment['id'];
        $assignment['attempts_used'] = $this->repository->attemptsUsed($assignmentId, $userId);
        $assignment['active_attempt'] = $this->repository->activeAttempt($assignmentId, $userId);
        $assignment['latest_attempt'] = $this->repository->latestSubmittedAttempt($assignmentId, $userId);
        if (!array_key_exists('has_submission_history', $assignment)) {
            $assignment['has_submission_history'] = $this->repository->hasSubmissionHistory($assignmentId, $userId);
        } else {
            $assignment['has_submission_history'] = $assignment['has_submission_history'] === true;
        }
        $assignment['student_status'] = $this->studentStatus($assignment, $now);
        if (($assignment['context_unavailable'] ?? false) === true) {
            $assignment['student_status'] = 'closed';
        }
        return $assignment;
    }

    /** @param array<string,mixed> $assignment @return array<string,mixed> */
    private function decorateForLearnerList(array $assignment, DateTimeImmutable $now): array
    {
        $assignment['has_submission_history'] = ($assignment['has_submission_history'] ?? false) === true;
        $assignment['student_status'] = $this->studentStatus($assignment, $now);
        if (($assignment['context_unavailable'] ?? false) === true) {
            $assignment['student_status'] = 'closed';
        }
        return $assignment;
    }

    /** @param array<string,mixed> $assignment */
    private function shouldIncludeForLearner(array $assignment): bool
    {
        $status = (string) ($assignment['student_status'] ?? '');
        if ($status === AssignmentStatus::ARCHIVED) {
            return false;
        }
        $historical = in_array(
            $status,
            ['expired', AssignmentStatus::CLOSED],
            true
        );
        return !$historical || ($assignment['has_submission_history'] ?? false) === true;
    }
    /** @param array<string,mixed> $assignment */
    private function contextMayEvaluate(int $actorId, array $assignment, int $learnerId): bool
    {
        try {
            $this->contexts->assertAssignmentAccess($actorId, $assignment, 'evaluate', $learnerId);
            return true;
        } catch (Problem) {
            return false;
        }
    }
    private function shouldSkipStaleContextProblem(Problem $problem): bool
    {
        return in_array($problem->httpStatus(), [403, 404, 409], true);
    }

    /**
     * Rebuild teacher pagination after assignment-level authorization so a
     * denied row cannot create sparse pages or inflate the visible total.
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int}
     */
    private function authorizedTeacherAssignments(
        int $actorId,
        int $page,
        int $perPage,
        ?string $status,
        string $operation,
        bool $includeDrafts,
        bool $lean = false
    ): array {
        $scopes = $this->contexts->allTeachingScopes($actorId);
        if ($scopes === []) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $rawPage = 1;
        $rawPerPage = 100;
        $visibleOffset = ($page - 1) * $perPage;
        $visibleTotal = 0;
        $rawScanned = 0;
        $rawTotal = 0;
        $items = [];
        $authorization = [];

        do {
            $result = $this->repository->listAssignmentsInScopes(
                $rawPage,
                $rawPerPage,
                $status,
                $scopes,
                $rawPage === 1,
                $lean
            );
            $rawItems = is_array($result['items'] ?? null) ? $result['items'] : [];
            if ($rawPage === 1) {
                $rawTotal = max(0, (int) ($result['total'] ?? 0));
            }
            $rawScanned += count($rawItems);
            if (
                $rawTotal > self::MAX_AUTHORIZED_ASSIGNMENT_SCAN
                || $rawScanned > self::MAX_AUTHORIZED_ASSIGNMENT_SCAN
            ) {
                throw new Problem(
                    'OJ_ML_ASSIGNMENT_SCAN_LIMIT',
                    '과제 목록의 권한 확인 범위가 허용 한도를 초과합니다.',
                    503,
                    ['limit' => self::MAX_AUTHORIZED_ASSIGNMENT_SCAN, 'total' => $rawTotal]
                );
            }
            foreach ($rawItems as $assignment) {
                if (!$includeDrafts && ($assignment['status'] ?? null) === AssignmentStatus::DRAFT) {
                    continue;
                }
                $authorizationKey = implode("\0", [
                    $operation,
                    (string) ($assignment['context_type'] ?? ''),
                    (string) ($assignment['context_id'] ?? ''),
                    (string) ($assignment['context_revision'] ?? ''),
                ]);
                if (array_key_exists($authorizationKey, $authorization)) {
                    if ($authorization[$authorizationKey] === false) {
                        continue;
                    }
                } else {
                    try {
                        $this->contexts->assertAssignmentAccess($actorId, $assignment, $operation);
                        $authorization[$authorizationKey] = true;
                    } catch (Problem $problem) {
                        if ($this->shouldSkipStaleContextProblem($problem)) {
                            $authorization[$authorizationKey] = false;
                            continue;
                        }
                        throw $problem;
                    }
                }
                if ($visibleTotal >= $visibleOffset && count($items) < $perPage) {
                    $items[] = $assignment;
                }
                $visibleTotal++;
            }

            if ($rawItems === [] || $rawPage * $rawPerPage >= $rawTotal) {
                break;
            }
            $rawPage++;
        } while (true);

        return [
            'items' => $items,
            'total' => $visibleTotal,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }


    /** @param array<string,mixed> $assignment */
    private function studentStatus(array $assignment, ?DateTimeImmutable $now = null): string
    {
        $status = (string) ($assignment['status'] ?? '');
        if (in_array($status, [AssignmentStatus::CLOSED, AssignmentStatus::ARCHIVED], true)) {
            return $status;
        }
        if ($status !== AssignmentStatus::PUBLISHED) {
            return $status;
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowValue = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $opensAt = is_string($assignment['opens_at'] ?? null) ? $assignment['opens_at'] : null;
        if ($opensAt !== null && $opensAt > $nowValue) {
            return 'scheduled';
        }
        $dueAt = is_string($assignment['due_at'] ?? null) ? $assignment['due_at'] : null;
        if ($dueAt !== null && $dueAt < $nowValue) {
            return ($assignment['policy']['allowLateSubmission'] ?? false) === true ? 'late_open' : 'expired';
        }
        return 'open';
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function normalizePublishedChanges(array $input, array $current): array
    {
        if ($input === []) {
            throw new Problem('OJ_ML_ASSIGNMENT_UPDATE_EMPTY', '변경할 과제 항목을 입력하세요.', 400);
        }
        $allowed = ['title', 'description', 'dueAt', 'maxAttempts'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new Problem(
                'OJ_ML_ASSIGNMENT_IMMUTABLE',
                '게시된 과제는 제목, 설명, 마감 시각, 최대 제출 횟수만 변경할 수 있습니다.',
                409
            );
        }
        $changes = $this->normalizeAssignment($input, false, (string) $current['task_type']);
        if (
            array_key_exists('max_attempts', $changes)
            && (int) $changes['max_attempts'] < (int) $current['max_attempts']
        ) {
            throw new Problem(
                'OJ_ML_ASSIGNMENT_MAX_ATTEMPTS_DECREASE_FORBIDDEN',
                '게시된 과제의 최대 제출 횟수는 줄일 수 없습니다.',
                409
            );
        }
        $dueAt = array_key_exists('due_at', $changes) ? $changes['due_at'] : $current['due_at'];
        $this->assertDateOrder($current['opens_at'], $dueAt);
        return $changes;
    }

    /** @return array<string,mixed> */
    private function requireAssignment(int $assignmentId): array
    {
        if ($assignmentId < 1) {
            throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
        }
        return $this->repository->getAssignment($assignmentId)
            ?? throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeAssignment(array $input, bool $create, ?string $currentTaskType = null, int $actorId = 0, ?array $source = null): array
    {
        $data = [];
        if ($create || array_key_exists('title', $input)) {
            $title = isset($input['title']) && is_string($input['title']) ? trim(sanitize_text_field($input['title'])) : '';
            if ($title === '' || strlen($title) > 200) {
                throw new Problem('OJ_ML_ASSIGNMENT_TITLE_INVALID', '과제 제목을 1~200자로 입력하세요.', 400);
            }
            $data['title'] = $title;
        }
        if ($create || array_key_exists('description', $input)) {
            $description = isset($input['description']) && is_string($input['description']) ? wp_kses_post($input['description']) : '';
            if (strlen($description) > 100000) {
                throw new Problem('OJ_ML_ASSIGNMENT_DESCRIPTION_TOO_LONG', '과제 설명이 너무 깁니다.', 400);
            }
            $data['description'] = $description;
        }
        if ($create || array_key_exists('taskType', $input)) {
            $taskType = isset($input['taskType']) && is_string($input['taskType']) ? sanitize_key($input['taskType']) : '';
            if (!in_array($taskType, self::TASK_TYPES, true)) {
                throw new Problem('OJ_ML_ASSIGNMENT_TASK_TYPE_INVALID', '과제 유형이 올바르지 않습니다.', 400);
            }
            $data['task_type'] = $taskType;
        }
        if ($create || array_key_exists('opensAt', $input)) {
            $data['opens_at'] = $this->mysqlUtc($input['opensAt'] ?? null, 'opensAt');
        }
        if ($create || array_key_exists('dueAt', $input)) {
            $data['due_at'] = $this->mysqlUtc($input['dueAt'] ?? null, 'dueAt');
        }
        if ($create) {
            $this->assertDateOrder($data['opens_at'], $data['due_at']);
        }
        if ($create || array_key_exists('maxAttempts', $input)) {
            $maxAttempts = isset($input['maxAttempts']) && is_numeric($input['maxAttempts']) ? (int) $input['maxAttempts'] : 1;
            if ($maxAttempts < 1 || $maxAttempts > 100) {
                throw new Problem('OJ_ML_ASSIGNMENT_MAX_ATTEMPTS_INVALID', '제출 횟수는 1~100회여야 합니다.', 400);
            }
            $data['max_attempts'] = $maxAttempts;
        }
        if ($create || array_key_exists('policy', $input)) {
            $taskType = $data['task_type'] ?? $currentTaskType;
            $data['policy'] = $this->normalizePolicy(
                isset($input['policy']) && is_array($input['policy']) ? $input['policy'] : [],
                is_string($taskType) ? $taskType : null,
                $actorId,
                $source
            );
        }
        if ($create || array_key_exists('rubric', $input)) {
            $rubric = \OJML\Playgrounds\Domain\Rubric::normalize($input['rubric'] ?? []);
            $encoded = wp_json_encode($rubric);
            if (!is_string($encoded) || strlen($encoded) > 1048576) {
                throw new Problem('OJ_ML_ASSIGNMENT_RUBRIC_INVALID', '채점 기준이 너무 크거나 올바르지 않습니다.', 400);
            }
            $data['rubric'] = $rubric;
        }
        return $data;
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function normalizePolicy(array $policy, ?string $taskType, int $actorId = 0, ?array $source = null): array
    {
        $normalized = [
            'allowedFormatVersions' => $this->integerList($policy['allowedFormatVersions'] ?? [2], 1, 100),
            'allowedDataTypes' => $this->enumList($policy['allowedDataTypes'] ?? ['tabular'], ['tabular', 'image']),
            'allowedTaskTypes' => $this->enumList($policy['allowedTaskTypes'] ?? ($taskType ? [$taskType] : self::TASK_TYPES), self::TASK_TYPES),
            'allowedAlgorithms' => $this->textList($policy['allowedAlgorithms'] ?? []),
            'maxArtifactBytes' => $this->boundedPolicyInt($policy['maxArtifactBytes'] ?? \OJML\Playgrounds\Ops\Options::all()['maxArtifactBytes'], 1048576, 262144000),
            'maxEntries' => $this->boundedPolicyInt($policy['maxEntries'] ?? 10000, 10, 20000),
            'maxTotalUncompressedBytes' => $this->boundedPolicyInt($policy['maxTotalUncompressedBytes'] ?? 262144000, 1048576, 524288000),
            'maxEntryUncompressedBytes' => $this->boundedPolicyInt($policy['maxEntryUncompressedBytes'] ?? 104857600, 1048576, 262144000),
            'maxCompressionRatio' => $this->boundedPolicyInt($policy['maxCompressionRatio'] ?? 100, 1, 200),
            'maxJsonBytes' => $this->boundedPolicyInt($policy['maxJsonBytes'] ?? 2097152, 1024, 4194304),
            'maxRows' => $this->boundedPolicyInt($policy['maxRows'] ?? 20000, 1, 200000),
            'maxFeatures' => $this->boundedPolicyInt($policy['maxFeatures'] ?? 100, 1, 2000),
            'allowLateSubmission' => ($policy['allowLateSubmission'] ?? false) === true,
            'requireIntegrityManifest' => !array_key_exists('requireIntegrityManifest', $policy)
                || $policy['requireIntegrityManifest'] !== false,
        ];
        if (array_key_exists('datasetDescription', $policy) || array_key_exists('datasetDescriptionSectionId', $policy)) {
            $normalized += \OJML\Playgrounds\Domain\DatasetContext::policy($policy);
        }
        if ($normalized['allowedFormatVersions'] === [] || $normalized['allowedDataTypes'] === [] || $normalized['allowedTaskTypes'] === []) {
            throw new Problem('OJ_ML_ASSIGNMENT_POLICY_INVALID', '허용 형식·데이터·과제 유형을 하나 이상 지정하세요.', 400);
        }
        if ($taskType !== null && !in_array($taskType, $normalized['allowedTaskTypes'], true)) {
            throw new Problem(
                'OJ_ML_ASSIGNMENT_POLICY_TASK_TYPE_MISMATCH',
                '과제 유형은 정책의 허용 과제 유형에 포함되어야 합니다.',
                400,
                ['taskType' => $taskType]
            );
        }
        if (array_key_exists('portfolioTemplate', $policy)) {
            $template = PortfolioTemplate::normalize($policy['portfolioTemplate']);
            if ($template !== null) {
                $normalized['portfolioTemplate'] = $template;
            }
        }
        if (isset($policy['requiredDatasetSha256']) && is_string($policy['requiredDatasetSha256']) && $policy['requiredDatasetSha256'] !== '') {
            $hash = strtolower(trim($policy['requiredDatasetSha256']));
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new Problem('OJ_ML_DATASET_HASH_INVALID', '데이터셋 SHA-256이 올바르지 않습니다.', 400);
            }
            $normalized['requiredDatasetSha256'] = $hash;
            if (isset($policy['requiredDatasetPath']) && is_string($policy['requiredDatasetPath'])) {
                $path = trim(str_replace('\\', '/', $policy['requiredDatasetPath']));
                if (!str_starts_with($path, 'dataset/') || str_contains($path, '..') || strlen($path) > 512) {
                    throw new Problem('OJ_ML_DATASET_PATH_INVALID', '데이터셋 내부 경로가 올바르지 않습니다.', 400);
                }
                $normalized['requiredDatasetPath'] = $path;
            }
        }
        $requirements = \OJML\Playgrounds\Domain\PortfolioRequirements::normalize($policy['portfolioRequirements'] ?? [], $normalized['portfolioTemplate'] ?? null);
        if ($requirements !== []) { $normalized['portfolioRequirements'] = $requirements; }
        if (($policy['datasetResource'] ?? null) !== null) {
            if ($this->datasets === null) { throw new Problem('OJ_ML_DATASET_UNAVAILABLE', '지정 데이터 서비스가 준비되지 않았습니다.', 503); }
            $resource = $this->datasets->resolveReference($policy['datasetResource'], $actorId, $source);
            if ($resource !== null) {
                $normalized['datasetResource'] = $resource;
                $normalized['requiredDatasetSha256'] = $resource['sha256'];
                $normalized['requiredDatasetPath'] = 'dataset/data.csv';
            }
        }
        return $normalized;
    }

    /** @param array<string,mixed> $input @return array{0:string,1:string} */
    private function contextFrom(array $input): array
    {
        return [$this->contextType($input['contextType'] ?? null), $this->contextId($input['contextId'] ?? null)];
    }

    private function contextType(mixed $value): string
    {
        $type = is_string($value) ? sanitize_key($value) : '';
        if (!in_array($type, ['class', 'group'], true)) {
            throw new Problem('OJ_ML_CONTEXT_INVALID', 'class 또는 group Context가 필요합니다.', 400);
        }
        return $type;
    }

    private function assertCreationGroup(string $contextType): void
    {
        if ($contextType !== 'group') {
            throw new Problem(
                'OJ_ML_ASSIGNMENT_GROUP_REQUIRED',
                '새 과제는 그룹을 선택해 만들어 주세요. 기존 수업 과제는 그대로 열람·평가할 수 있습니다.',
                400
            );
        }
    }

    private function contextId(mixed $value): string
    {
        $id = is_string($value) ? trim($value) : '';
        if ($id === '' || strlen($id) > 160 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $id) !== 1) {
            throw new Problem('OJ_ML_CONTEXT_INVALID', 'Context ID가 올바르지 않습니다.', 400);
        }
        return $id;
    }

    private function assertDateOrder(?string $opensAt, ?string $dueAt): void
    {
        if ($opensAt !== null && $dueAt !== null && $opensAt >= $dueAt) {
            throw new Problem('OJ_ML_ASSIGNMENT_DATES_INVALID', '마감 시각은 시작 시각보다 뒤여야 합니다.', 400);
        }
    }

    private function mysqlUtc(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 50) {
            throw new Problem('OJ_ML_ASSIGNMENT_DATE_INVALID', '과제 날짜 형식이 올바르지 않습니다.', 400, ['field' => $field]);
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            throw new Problem('OJ_ML_ASSIGNMENT_DATE_INVALID', '과제 날짜 형식이 올바르지 않습니다.', 400, ['field' => $field]);
        }
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_numeric($value) || (int) $value < 1) {
            throw new Problem('OJ_ML_INTEGER_INVALID', '양의 정수가 필요합니다.', 400, ['field' => $field]);
        }
        return (int) $value;
    }

    private function nullableScore(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1000000) {
            throw new Problem('OJ_ML_REVIEW_SCORE_INVALID', '점수가 올바르지 않습니다.', 400);
        }
        return (float) $value;
    }

    private function uuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1) {
            throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
        }
        return $uuid;
    }

    /** @return list<int> */
    private function integerList(mixed $values, int $minimum, int $maximum): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value >= $minimum && (int) $value <= $maximum) {
                $result[(int) $value] = (int) $value;
            }
        }
        return array_values($result);
    }

    /** @param list<string> $allowed @return list<string> */
    private function enumList(mixed $values, array $allowed): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $value) {
            if (is_string($value) && in_array($value, $allowed, true)) {
                $result[$value] = $value;
            }
        }
        return array_values($result);
    }

    /** @return list<string> */
    private function textList(mixed $values): array
    {
        if (!is_array($values) || count($values) > 100) {
            throw new Problem('OJ_ML_ASSIGNMENT_POLICY_INVALID', '알고리즘 목록이 올바르지 않습니다.', 400);
        }
        $result = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $clean = substr(sanitize_text_field($value), 0, 100);
                if ($clean !== '') {
                    $result[$clean] = $clean;
                }
            }
        }
        return array_values($result);
    }

    private function boundedPolicyInt(mixed $value, int $minimum, int $maximum): int
    {
        if (!is_numeric($value) || (int) $value < $minimum || (int) $value > $maximum) {
            throw new Problem('OJ_ML_ASSIGNMENT_POLICY_LIMIT_INVALID', '과제 제출 제한 값이 허용 범위를 벗어났습니다.', 400);
        }
        return (int) $value;
    }
}
