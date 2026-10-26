<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Database;

use OJML\Playgrounds\Domain\AssignmentStatus;
use OJML\Playgrounds\Domain\AttemptStatus;
use OJML\Playgrounds\Domain\Problem;
use Throwable;
use wpdb;

final class Repository
{
    private readonly Tables $tables;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->tables = new Tables($wpdb);
    }

    /** @param array<string,mixed> $data */
    public function createAssignment(array $data): array
    {
        return $this->transaction(function () use ($data): array {
            $now = $this->now();
            $written = $this->wpdb->insert(
                $this->tables->assignments,
                [
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'task_type' => $data['task_type'],
                    'context_type' => $data['context_type'],
                    'context_id' => $data['context_id'],
                    'context_revision' => $data['context_revision'],
                    'roster_revision' => $data['roster_revision'],
                    'status' => AssignmentStatus::DRAFT,
                    'policy_json' => $this->json($data['policy']),
                    'rubric_json' => $this->json($data['rubric']),
                    'opens_at' => $data['opens_at'],
                    'due_at' => $data['due_at'],
                    'max_attempts' => $data['max_attempts'],
                    'revision' => 1,
                    'created_by' => $data['created_by'],
                    'created_at' => $now,
                    'updated_at' => $now,
                    'published_at' => null,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s']
            );
            $this->assertWritten($written, 'OJ_ML_ASSIGNMENT_CREATE_FAILED');
            $assignmentId = (int) $this->wpdb->insert_id;
            $this->replaceTargets($assignmentId, $data['targets'] ?? []);
            $this->replaceLearners($assignmentId, $data['learners'] ?? []);
            $this->insertEvent($assignmentId, null, (int) $data['created_by'], 'assignment.created', ['revision' => 1], wp_generate_uuid4());
            return $this->requireAssignment($assignmentId);
        });
    }

    /** @param array<string,mixed> $changes */
    public function updateAssignment(int $assignmentId, int $expectedRevision, array $changes, int $actorId = 0): array
    {
        return $this->transaction(function () use ($assignmentId, $expectedRevision, $changes, $actorId): array {
            $current = $this->requireAssignmentForUpdate($assignmentId);
            $this->assertRevision($current, $expectedRevision);
            if ($current['status'] !== AssignmentStatus::DRAFT) {
                throw new Problem('OJ_ML_ASSIGNMENT_IMMUTABLE', '게시된 과제의 핵심 내용과 평가 정책은 변경할 수 없습니다.', 409);
            }

            $columnMap = [
                'title' => ['title', '%s'],
                'description' => ['description', '%s'],
                'task_type' => ['task_type', '%s'],
                'context_type' => ['context_type', '%s'],
                'context_id' => ['context_id', '%s'],
                'context_revision' => ['context_revision', '%s'],
                'roster_revision' => ['roster_revision', '%s'],
                'opens_at' => ['opens_at', '%s'],
                'due_at' => ['due_at', '%s'],
                'max_attempts' => ['max_attempts', '%d'],
            ];
            $update = [];
            $formats = [];
            foreach ($columnMap as $input => [$column, $format]) {
                if (array_key_exists($input, $changes)) {
                    $update[$column] = $changes[$input];
                    $formats[] = $format;
                }
            }
            if (array_key_exists('policy', $changes)) {
                $update['policy_json'] = $this->json($changes['policy']);
                $formats[] = '%s';
            }
            if (array_key_exists('rubric', $changes)) {
                $update['rubric_json'] = $this->json($changes['rubric']);
                $formats[] = '%s';
            }
            $update['revision'] = $expectedRevision + 1;
            $update['updated_at'] = $this->now();
            $formats[] = '%d';
            $formats[] = '%s';

            $written = $this->wpdb->update(
                $this->tables->assignments,
                $update,
                ['id' => $assignmentId, 'revision' => $expectedRevision, 'status' => AssignmentStatus::DRAFT],
                $formats,
                ['%d', '%d', '%s']
            );
            if ($written !== 1) {
                throw new Problem('OJ_ML_REVISION_CONFLICT', '과제가 다른 곳에서 변경되었습니다.', 409);
            }
            if (array_key_exists('targets', $changes)) {
                $this->replaceTargets($assignmentId, $changes['targets']);
            }
            if (array_key_exists('learners', $changes)) {
                $this->replaceLearners($assignmentId, $changes['learners']);
            }
            $this->insertEvent($assignmentId, null, $actorId, 'assignment.updated', ['revision' => $expectedRevision + 1, 'fields' => array_keys($changes)], wp_generate_uuid4());
            return $this->requireAssignment($assignmentId);
        });
    }

    /** @param array<string,mixed> $changes */
    public function updatePublishedAssignmentOperations(
        int $assignmentId,
        int $expectedRevision,
        array $changes,
        int $actorId
    ): array {
        return $this->transaction(function () use ($assignmentId, $expectedRevision, $changes, $actorId): array {
            $current = $this->requireAssignmentForUpdate($assignmentId);
            $this->assertRevision($current, $expectedRevision);
            if ($current['status'] !== AssignmentStatus::PUBLISHED) {
                throw new Problem('OJ_ML_ASSIGNMENT_IMMUTABLE', '게시 중인 과제의 운영 항목만 변경할 수 있습니다.', 409);
            }
            if ($changes === []) {
                throw new Problem('OJ_ML_ASSIGNMENT_UPDATE_EMPTY', '변경할 과제 항목을 입력하세요.', 400);
            }
            $allowed = ['title', 'description', 'due_at', 'max_attempts'];
            if (array_diff(array_keys($changes), $allowed) !== []) {
                throw new Problem(
                    'OJ_ML_ASSIGNMENT_IMMUTABLE',
                    '게시된 과제는 제목, 설명, 마감 시각, 최대 제출 횟수만 변경할 수 있습니다.',
                    409
                );
            }
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

            $columnMap = [
                'title' => ['title', '%s'],
                'description' => ['description', '%s'],
                'due_at' => ['due_at', '%s'],
                'max_attempts' => ['max_attempts', '%d'],
            ];
            $update = [];
            $formats = [];
            foreach ($columnMap as $input => [$column, $format]) {
                if (array_key_exists($input, $changes)) {
                    $update[$column] = $changes[$input];
                    $formats[] = $format;
                }
            }
            $update['revision'] = $expectedRevision + 1;
            $update['updated_at'] = $this->now();
            $formats[] = '%d';
            $formats[] = '%s';

            $written = $this->wpdb->update(
                $this->tables->assignments,
                $update,
                ['id' => $assignmentId, 'revision' => $expectedRevision, 'status' => AssignmentStatus::PUBLISHED],
                $formats,
                ['%d', '%d', '%s']
            );
            if ($written !== 1) {
                throw new Problem('OJ_ML_REVISION_CONFLICT', '과제가 다른 곳에서 변경되었습니다.', 409);
            }
            $eventPayload = ['fields' => array_values(array_keys($changes))];
            if (array_key_exists('due_at', $changes)) {
                $eventPayload['dueAt'] = $changes['due_at'];
            }
            if (array_key_exists('max_attempts', $changes)) {
                $eventPayload['maxAttempts'] = (int) $changes['max_attempts'];
            }
            $this->insertEvent(
                $assignmentId,
                null,
                $actorId,
                'assignment.operations_updated',
                $eventPayload,
                wp_generate_uuid4()
            );
            return $this->requireAssignment($assignmentId);
        });
    }

    public function publishAssignment(int $assignmentId, int $expectedRevision, int $actorId): array
    {
        return $this->transaction(function () use ($assignmentId, $expectedRevision, $actorId): array {
            $current = $this->requireAssignmentForUpdate($assignmentId);
            $this->assertRevision($current, $expectedRevision);
            if ($current['status'] !== AssignmentStatus::DRAFT) {
                throw new Problem('OJ_ML_ASSIGNMENT_NOT_DRAFT', '초안 과제만 게시할 수 있습니다.', 409);
            }
            if ($current['context_revision'] === '' || $current['roster_revision'] === '') {
                throw new Problem('OJ_ML_CONTEXT_SNAPSHOT_MISSING', 'Context/명단 스냅샷이 없어 게시할 수 없습니다.', 409);
            }
            if ($this->learnerCount($assignmentId) < 1) {
                throw new Problem('OJ_ML_ASSIGNMENT_LEARNERS_EMPTY', '학습자가 없는 과제는 게시할 수 없습니다.', 409);
            }
            $now = $this->now();
            $written = $this->wpdb->update(
                $this->tables->assignments,
                [
                    'status' => AssignmentStatus::PUBLISHED,
                    'revision' => $expectedRevision + 1,
                    'updated_at' => $now,
                    'published_at' => $now,
                ],
                ['id' => $assignmentId, 'revision' => $expectedRevision, 'status' => AssignmentStatus::DRAFT],
                ['%s', '%d', '%s', '%s'],
                ['%d', '%d', '%s']
            );
            if ($written !== 1) {
                throw new Problem('OJ_ML_REVISION_CONFLICT', '과제를 게시하지 못했습니다.', 409);
            }
            $this->insertEvent($assignmentId, null, $actorId, 'assignment.published', [], wp_generate_uuid4());
            return $this->requireAssignment($assignmentId);
        });
    }

    public function transitionAssignment(
        int $assignmentId,
        int $expectedRevision,
        string $nextStatus,
        int $actorId
    ): array {
        return $this->transaction(function () use ($assignmentId, $expectedRevision, $nextStatus, $actorId): array {
            $current = $this->requireAssignmentForUpdate($assignmentId);
            $this->assertRevision($current, $expectedRevision);
            $allowed = [
                AssignmentStatus::DRAFT => [],
                AssignmentStatus::PUBLISHED => [AssignmentStatus::CLOSED, AssignmentStatus::ARCHIVED],
                AssignmentStatus::CLOSED => [AssignmentStatus::PUBLISHED, AssignmentStatus::ARCHIVED],
                AssignmentStatus::ARCHIVED => [AssignmentStatus::CLOSED],
            ];
            if (!in_array($nextStatus, $allowed[$current['status']] ?? [], true)) {
                throw new Problem('OJ_ML_ASSIGNMENT_STATUS_TRANSITION_INVALID', '허용되지 않은 과제 상태 변경입니다.', 409);
            }
            $written = $this->wpdb->update(
                $this->tables->assignments,
                ['status' => $nextStatus, 'revision' => $expectedRevision + 1, 'updated_at' => $this->now()],
                ['id' => $assignmentId, 'revision' => $expectedRevision],
                ['%s', '%d', '%s'],
                ['%d', '%d']
            );
            if ($written !== 1) {
                throw new Problem('OJ_ML_REVISION_CONFLICT', '과제 상태를 변경하지 못했습니다.', 409);
            }
            $this->insertEvent($assignmentId, null, $actorId, 'assignment.status_changed', ['status' => $nextStatus], wp_generate_uuid4());
            return $this->requireAssignment($assignmentId);
        });
    }

    public function deleteAssignment(int $assignmentId, int $expectedRevision, int $actorId = 0): void
    {
        $this->transaction(function () use ($assignmentId, $expectedRevision, $actorId): void {
            $current = $this->requireAssignmentForUpdate($assignmentId);
            $this->assertRevision($current, $expectedRevision);
            if ($current['status'] !== AssignmentStatus::DRAFT) {
                throw new Problem('OJ_ML_ASSIGNMENT_IMMUTABLE', '초안 과제만 삭제할 수 있습니다.', 409);
            }
            $attempts = (int) $this->selectValue($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables->attempts} WHERE assignment_id = %d",
                $assignmentId
            ));
            if ($attempts > 0) {
                throw new Problem('OJ_ML_ASSIGNMENT_HAS_ATTEMPTS', '시도 이력이 있는 과제는 삭제할 수 없습니다.', 409);
            }
            $this->assertWritten(
                $this->wpdb->delete($this->tables->assignmentTargets, ['assignment_id' => $assignmentId], ['%d']),
                'OJ_ML_ASSIGNMENT_DELETE_FAILED'
            );
            $this->assertWritten(
                $this->wpdb->delete($this->tables->assignmentLearners, ['assignment_id' => $assignmentId], ['%d']),
                'OJ_ML_ASSIGNMENT_DELETE_FAILED'
            );
            $deleted = $this->wpdb->delete(
                $this->tables->assignments,
                ['id' => $assignmentId, 'revision' => $expectedRevision, 'status' => AssignmentStatus::DRAFT],
                ['%d', '%d', '%s']
            );
            if ($deleted === false) {
                throw new Problem('OJ_ML_ASSIGNMENT_DELETE_FAILED', '과제를 삭제하지 못했습니다.', 500);
            }
            if ($deleted !== 1) {
                throw new Problem('OJ_ML_REVISION_CONFLICT', '과제가 다른 곳에서 변경되었습니다.', 409);
            }
            $this->insertEvent($assignmentId, null, $actorId, 'assignment.deleted', ['revision' => $expectedRevision], wp_generate_uuid4());
        });
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function listAssignments(int $page, int $perPage, ?string $status = null): array
    {
        $where = '';
        $values = [];
        if ($status !== null && $status !== '') {
            $where = ' WHERE status = %s';
            $values[] = $status;
        }
        $countSql = "SELECT COUNT(*) FROM {$this->tables->assignments}{$where}";
        if ($values !== []) {
            $countSql = $this->wpdb->prepare($countSql, ...$values);
        }
        $total = (int) $this->selectValue($countSql);
        $offset = ($page - 1) * $perPage;
        $query = "SELECT * FROM {$this->tables->assignments}{$where} ORDER BY updated_at DESC,id DESC LIMIT %d OFFSET %d";
        $rows = $this->selectRows(
            $this->wpdb->prepare($query, ...[...$values, $perPage, $offset]),
            ARRAY_A
        ) ?: [];
        return [
            'items' => array_map(fn (array $row): array => $this->hydrateAssignment($row, false), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Scope-first teacher listing. An empty scope list returns no rows and never
     * falls back to a global assignment query.
     *
     * @param list<array{type:string,id:string}> $scopes
     * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function listAssignmentsInScopes(
        int $page,
        int $perPage,
        ?string $status,
        array $scopes,
        bool $countTotal = true,
        bool $lean = false
    ): array {
        if ($scopes === []) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }
        $scopeClauses = [];
        $values = [];
        foreach ($scopes as $scope) {
            $type = is_string($scope['type'] ?? null) ? $scope['type'] : '';
            $id = is_string($scope['id'] ?? null) ? $scope['id'] : '';
            if (!in_array($type, ['class', 'group'], true)
                || $id === ''
                || strlen($id) > 160
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $id) !== 1) {
                throw new Problem('OJ_ML_CONTEXT_SCOPE_INVALID', 'Context 조회 범위가 올바르지 않습니다.', 503);
            }
            $scopeClauses[] = '(context_type = %s AND context_id = %s)';
            $values[] = $type;
            $values[] = $id;
        }
        $where = ['(' . implode(' OR ', $scopeClauses) . ')'];
        if ($status === 'unarchived') {
            $where[] = 'status IN (%s,%s,%s)';
            array_push($values, AssignmentStatus::DRAFT, AssignmentStatus::PUBLISHED, AssignmentStatus::CLOSED);
        } elseif ($status !== null && $status !== '') {
            $where[] = 'status = %s';
            $values[] = $status;
        }
        $clause = implode(' AND ', $where);
        $total = 0;
        if ($countTotal) {
            $total = (int) $this->selectValue($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tables->assignments} WHERE {$clause}",
                ...$values
            ));
        }
        $offset = ($page - 1) * $perPage;
        $columns = $lean
            ? 'id,title,status,context_type,context_id,context_revision'
            : '*';
        $rows = $this->selectRows($this->wpdb->prepare(
            "SELECT {$columns} FROM {$this->tables->assignments}
             WHERE {$clause} ORDER BY updated_at DESC,id DESC LIMIT %d OFFSET %d",
            ...[...$values, $perPage, $offset]
        ), ARRAY_A) ?: [];
        $items = $lean
            ? array_map(static function (array $row): array {
                $row['id'] = (int) $row['id'];
                return $row;
            }, $rows)
            : array_map(fn (array $row): array => $this->hydrateAssignment($row, false), $rows);
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listPublishedAssignments(int $limit = 200): array
    {
        $rows = $this->selectRows($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->assignments}
             WHERE status IN (%s,%s)
             ORDER BY due_at IS NULL,due_at ASC,id DESC LIMIT %d",
            AssignmentStatus::PUBLISHED,
            AssignmentStatus::CLOSED,
            $limit
        ), ARRAY_A) ?: [];
        return array_map(fn (array $row): array => $this->hydrateAssignment($row, false), $rows);
    }

    /**
     * Fetch only assignments assigned to this learner before applying the
     * bounded result limit. Published work is kept ahead of closed history.
     *
     * @return list<array<string,mixed>>
     */
    public function listPublishedAssignmentsForLearner(int $userId, int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $latePolicyPattern = '%"allowLateSubmission":true%';
        $current = 'a.status = %s AND ('
            . 'a.due_at IS NULL OR GREATEST(a.due_at, COALESCE(l.due_at_override,a.due_at)) >= UTC_TIMESTAMP() OR a.policy_json LIKE %s)';
        $rows = $this->selectRows($this->wpdb->prepare(
            "SELECT a.*, l.max_attempts_override,l.due_at_override, EXISTS (
                    SELECT 1 FROM {$this->tables->attempts} ah
                     WHERE ah.assignment_id = a.id
                       AND ah.user_id = l.user_id
                       AND ah.status <> %s
                       AND ah.artifact_id IS NOT NULL
                       AND ah.received_at IS NOT NULL
               ) AS has_submission_history
               FROM {$this->tables->assignments} a
             INNER JOIN {$this->tables->assignmentLearners} l
                ON l.assignment_id = a.id
               AND l.user_id = %d
               AND l.removed_at IS NULL
             WHERE a.status IN (%s,%s)
             HAVING (({$current}) OR has_submission_history = 1)
             ORDER BY CASE WHEN ({$current}) THEN 0 ELSE 1 END,
                      CASE WHEN a.status = %s THEN 0 WHEN a.status = %s THEN 1 ELSE 2 END,
                      a.due_at IS NULL,a.due_at ASC,a.id DESC
             LIMIT %d OFFSET %d",
            AttemptStatus::CANCELLED,
            $userId,
            AssignmentStatus::PUBLISHED,
            AssignmentStatus::CLOSED,
            AssignmentStatus::PUBLISHED,
            $latePolicyPattern,
            AssignmentStatus::PUBLISHED,
            $latePolicyPattern,
            AssignmentStatus::PUBLISHED,
            AssignmentStatus::CLOSED,
            $limit,
            max(0, $offset)
        ), ARRAY_A) ?: [];
        return array_map(fn (array $row): array => $this->applyAllowance($this->hydrateAssignment($row, false), $row), $rows);
    }

    /**
     * @param list<int> $assignmentIds
     * @return array<int,array{uuid:string,received_at:?string}>
     */
    public function latestSubmittedAttemptSummariesForLearner(int $userId, array $assignmentIds): array
    {
        $assignmentIds = $this->positiveIds($assignmentIds);
        if ($assignmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($assignmentIds), '%d'));
        $rows = $this->selectRows($this->wpdb->prepare(
            "SELECT a.assignment_id,a.uuid,a.received_at
               FROM {$this->tables->attempts} a
              WHERE a.user_id = %d
                AND a.assignment_id IN ({$placeholders})
                AND a.id = (
                    SELECT a2.id FROM {$this->tables->attempts} a2
                     WHERE a2.assignment_id = a.assignment_id
                       AND a2.user_id = a.user_id
                       AND a2.status <> %s
                       AND a2.artifact_id IS NOT NULL
                       AND a2.received_at IS NOT NULL
                     ORDER BY a2.attempt_no DESC,a2.id DESC LIMIT 1
                )",
            ...[$userId, ...$assignmentIds, AttemptStatus::CANCELLED]
        ), ARRAY_A) ?: [];
        $summaries = [];
        foreach ($rows as $row) {
            $assignmentId = (int) ($row['assignment_id'] ?? 0);
            if ($assignmentId < 1 || !is_string($row['uuid'] ?? null)) {
                continue;
            }
            $summaries[$assignmentId] = [
                'uuid' => $row['uuid'],
                'received_at' => is_string($row['received_at'] ?? null) ? $row['received_at'] : null,
            ];
        }
        return $summaries;
    }

    public function getAssignment(int $assignmentId): ?array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->assignments} WHERE id = %d",
            $assignmentId
        ), ARRAY_A);
        return is_array($row) ? $this->hydrateAssignment($row, true) : null;
    }

    public function isActiveLearner(int $assignmentId, int $userId): bool
    {
        return (bool) $this->selectValue($this->wpdb->prepare(
            "SELECT 1 FROM {$this->tables->assignmentLearners}
             WHERE assignment_id = %d AND user_id = %d AND removed_at IS NULL LIMIT 1",
            $assignmentId,
            $userId
        ));
    }

    public function attemptsUsed(int $assignmentId, int $userId): int
    {
        return (int) $this->selectValue($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables->attempts}
             WHERE assignment_id = %d AND user_id = %d AND status <> %s",
            $assignmentId,
            $userId,
            AttemptStatus::CANCELLED
        ));
    }

    /** @return array<string,mixed> */
    public function learnerAllowance(int $assignmentId, int $userId): array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT max_attempts_override,due_at_override,override_revision FROM {$this->tables->assignmentLearners}
             WHERE assignment_id = %d AND user_id = %d AND removed_at IS NULL", $assignmentId, $userId
        ));
        return $row ?? ['max_attempts_override' => null, 'due_at_override' => null, 'override_revision' => 0];
    }

    public function assignmentForLearner(array $assignment, int $userId): array
    {
        return $this->applyAllowance($assignment, $this->learnerAllowance((int) $assignment['id'], $userId));
    }

    private function applyAllowance(array $assignment, array $allowance): array
    {
        $assignment['max_attempts'] = max((int) $assignment['max_attempts'], (int) ($allowance['max_attempts_override'] ?? 0));
        if (is_string($assignment['due_at'] ?? null) && is_string($allowance['due_at_override'] ?? null)) {
            $assignment['due_at'] = max($assignment['due_at'], $allowance['due_at_override']);
        }
        return $assignment;
    }

    public function grantAllowance(int $assignmentId, int $userId, int $expectedRevision, ?int $maxAttempts, ?string $dueAt, string $reason, int $actorId, string $correlationId): array
    {
        return $this->transaction(function () use ($assignmentId, $userId, $expectedRevision, $maxAttempts, $dueAt, $reason, $actorId, $correlationId): array {
            $assignment = $this->requireAssignmentForUpdate($assignmentId);
            if ($assignment['status'] !== AssignmentStatus::PUBLISHED || !$this->isActiveLearner($assignmentId, $userId)) {
                throw new Problem('OJ_ML_ALLOWANCE_UNAVAILABLE', '게시 중인 과제에 배정된 학생만 변경할 수 있습니다.', 409);
            }
            $before = $this->learnerAllowance($assignmentId, $userId);
            if ((int) $before['override_revision'] !== $expectedRevision) {
                throw new Problem('OJ_ML_REVISION_CONFLICT', '학생별 제출 설정이 변경되었습니다. 새로 조회하세요.', 409);
            }
            $effective = $this->applyAllowance($assignment, $before);
            if ($maxAttempts !== null && ($maxAttempts < (int) $effective['max_attempts'] || $maxAttempts > 100)) {
                throw new Problem('OJ_ML_ALLOWANCE_INVALID', '최대 시도는 현재 값 이상, 100회 이하로 입력하세요.', 400);
            }
            if ($dueAt !== null && ($assignment['due_at'] === null || $dueAt < $effective['due_at'])) {
                throw new Problem('OJ_ML_ALLOWANCE_INVALID', '개인 마감은 기존 마감보다 늦게 지정하세요.', 400);
            }
            $update = ['override_revision' => $expectedRevision + 1];
            if ($maxAttempts !== null) { $update['max_attempts_override'] = $maxAttempts; }
            if ($dueAt !== null) { $update['due_at_override'] = $dueAt; }
            $changed = $this->wpdb->update($this->tables->assignmentLearners, $update,
                ['assignment_id' => $assignmentId, 'user_id' => $userId, 'override_revision' => $expectedRevision]
            );
            $this->assertWritten($changed, 'OJ_ML_ALLOWANCE_SAVE_FAILED');
            if ($changed !== 1) { throw new Problem('OJ_ML_REVISION_CONFLICT', '학생 배정이나 제출 설정이 변경되었습니다. 새로 조회하세요.', 409); }
            $this->insertEvent($assignmentId, null, $actorId, 'learner.allowance_granted', [
                'userId' => $userId, 'before' => $before, 'after' => $update, 'reason' => $reason,
            ], $correlationId);
            return $this->learnerAllowance($assignmentId, $userId);
        });
    }

    public function listAttemptHistory(int $assignmentId, int $userId, int $page, int $perPage, bool $student): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $total = (int) $this->selectValue($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables->attempts} WHERE assignment_id = %d AND user_id = %d", $assignmentId, $userId
        ));
        $rows = $this->selectRows($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->attempts} WHERE assignment_id = %d AND user_id = %d
             ORDER BY attempt_no DESC,id DESC LIMIT %d OFFSET %d", $assignmentId, $userId, $perPage, ($page - 1) * $perPage
        ));
        $items = $this->decorateAttempts(array_map(fn (array $row): array => $this->hydrateAttempt($row), $rows), $student);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function hasSubmissionHistory(int $assignmentId, int $userId): bool
    {
        return (bool) $this->selectValue($this->wpdb->prepare(
            "SELECT 1 FROM {$this->tables->attempts}
             WHERE assignment_id = %d AND user_id = %d
               AND status <> %s
               AND artifact_id IS NOT NULL
               AND received_at IS NOT NULL
             LIMIT 1",
            $assignmentId,
            $userId,
            AttemptStatus::CANCELLED
        ));
    }

    public function activeAttempt(int $assignmentId, int $userId): ?array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->attempts}
             WHERE assignment_id = %d AND user_id = %d AND status IN (%s,%s)
             ORDER BY id DESC LIMIT 1",
            $assignmentId,
            $userId,
            AttemptStatus::IN_PROGRESS,
            AttemptStatus::UPLOADING
        ), ARRAY_A);
        return is_array($row) ? $this->hydrateAttempt($row) : null;
    }

    public function latestAttempt(int $assignmentId, int $userId): ?array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->attempts}
             WHERE assignment_id = %d AND user_id = %d
             ORDER BY attempt_no DESC,id DESC LIMIT 1",
            $assignmentId,
            $userId
        ), ARRAY_A);
        return is_array($row) ? $this->decorateAttempt($this->hydrateAttempt($row), true) : null;
    }

    public function latestSubmittedAttempt(int $assignmentId, int $userId): ?array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->attempts}
             WHERE assignment_id = %d AND user_id = %d
               AND status <> %s
               AND artifact_id IS NOT NULL
               AND received_at IS NOT NULL
             ORDER BY attempt_no DESC,id DESC LIMIT 1",
            $assignmentId,
            $userId,
            AttemptStatus::CANCELLED
        ), ARRAY_A);
        return is_array($row) ? $this->decorateAttempt($this->hydrateAttempt($row), true) : null;
    }

    /** @return array{attempt:array<string,mixed>,created:bool} */
    public function createAttempt(
        int $assignmentId,
        int $userId,
        ?string $idempotencyKey,
        ?string $clientAppVersion,
        ?int $formatVersion
    ): array {
        return $this->transaction(function () use ($assignmentId, $userId, $idempotencyKey, $clientAppVersion, $formatVersion): array {
            $assignment = $this->requireAssignmentForUpdate($assignmentId);
            $assignment = $this->assignmentForLearner($assignment, $userId);
            if ($idempotencyKey !== null) {
                $existing = $this->selectRow($this->wpdb->prepare(
                    "SELECT * FROM {$this->tables->attempts}
                     WHERE assignment_id = %d AND user_id = %d AND idempotency_key = %s LIMIT 1",
                    $assignmentId,
                    $userId,
                    $idempotencyKey
                ), ARRAY_A);
                if (is_array($existing)) {
                    return ['attempt' => $this->hydrateAttempt($existing), 'created' => false];
                }
            }
            $active = $this->activeAttempt($assignmentId, $userId);
            if ($active !== null) {
                return ['attempt' => $active, 'created' => false];
            }
            if ($assignment['status'] !== AssignmentStatus::PUBLISHED) {
                throw new Problem('OJ_ML_ASSIGNMENT_CLOSED', '새 시도를 시작할 수 없는 과제입니다.', 409);
            }
            $now = $this->now();
            if ($assignment['opens_at'] !== null && $assignment['opens_at'] > $now) {
                throw new Problem('OJ_ML_ASSIGNMENT_NOT_OPEN', '아직 시작되지 않은 과제입니다.', 409);
            }
            $allowLate = ($assignment['policy']['allowLateSubmission'] ?? false) === true;
            if ($assignment['due_at'] !== null && $assignment['due_at'] < $now && !$allowLate) {
                throw new Problem('OJ_ML_ASSIGNMENT_CLOSED', '제출 기한이 지난 과제입니다.', 409);
            }
            if (!$this->isActiveLearner($assignmentId, $userId)) {
                throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
            }
            $used = $this->attemptsUsed($assignmentId, $userId);
            if ($used >= (int) $assignment['max_attempts']) {
                throw new Problem('OJ_ML_ATTEMPT_LIMIT_REACHED', '허용된 제출 횟수를 모두 사용했습니다.', 409, [
                    'maxAttempts' => (int) $assignment['max_attempts'],
                ]);
            }
            $uuid = wp_generate_uuid4();
            $written = $this->wpdb->insert(
                $this->tables->attempts,
                [
                    'uuid' => $uuid,
                    'assignment_id' => $assignmentId,
                    'user_id' => $userId,
                    'attempt_no' => (int) $this->selectValue($this->wpdb->prepare(
                        "SELECT COALESCE(MAX(attempt_no),0) + 1 FROM {$this->tables->attempts} WHERE assignment_id = %d AND user_id = %d",
                        $assignmentId, $userId
                    )),
                    'status' => AttemptStatus::IN_PROGRESS,
                    'idempotency_key' => $idempotencyKey,
                    'artifact_id' => null,
                    'policy_revision' => (int) $assignment['revision'],
                    'client_app_version' => $clientAppVersion,
                    'format_version' => $formatVersion,
                    'progress_json' => null,
                    'last_progress_at' => null,
                    'failure_code' => null,
                    'failure_json' => null,
                    'late' => 0,
                    'received_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
            );
            $this->assertWritten($written, 'OJ_ML_ATTEMPT_CREATE_FAILED');
            $attempt = $this->getAttemptByUuid($uuid);
            if ($attempt === null) {
                throw new Problem('OJ_ML_ATTEMPT_CREATE_FAILED', '시도를 생성하지 못했습니다.', 500);
            }
            $this->insertEvent($assignmentId, (int) $attempt['id'], $userId, 'attempt.started', ['attemptNo' => $attempt['attempt_no']], wp_generate_uuid4());
            return ['attempt' => $attempt, 'created' => true];
        });
    }

    /** @param array<string,mixed> $progress */
    public function updateProgress(string $uuid, int $userId, array $progress): array
    {
        $attempt = $this->requireOwnedAttempt($uuid, $userId);
        if (!in_array($attempt['status'], AttemptStatus::active(), true)) {
            throw new Problem('OJ_ML_ATTEMPT_IMMUTABLE', '이미 접수된 시도는 변경할 수 없습니다.', 409);
        }
        $expectedStatus = (string) $attempt['status'];
        $status = ($progress['phase'] ?? '') === 'uploading' ? AttemptStatus::UPLOADING : $expectedStatus;
        $now = $this->now();
        $written = $this->wpdb->update(
            $this->tables->attempts,
            ['status' => $status, 'progress_json' => $this->json($progress), 'last_progress_at' => $now, 'updated_at' => $now],
            ['id' => (int) $attempt['id'], 'user_id' => $userId, 'status' => $expectedStatus],
            ['%s', '%s', '%s', '%s'],
            ['%d', '%d', '%s']
        );
        if ($written === false) {
            throw new Problem('OJ_ML_PROGRESS_SAVE_FAILED', '진행 상태를 저장하지 못했습니다.', 500);
        }
        if ($written === 0) {
            $current = $this->getOwnedAttempt($uuid, $userId);
            if ($current === null) {
                throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
            }
            if ($current['artifact_id'] !== null || !in_array($current['status'], AttemptStatus::active(), true)) {
                throw new Problem('OJ_ML_ATTEMPT_IMMUTABLE', '이미 접수된 시도는 변경할 수 없습니다.', 409);
            }
            throw new Problem('OJ_ML_PROGRESS_CONFLICT', '진행 상태가 다른 요청에서 변경되었습니다.', 409, [
                'currentStatus' => (string) $current['status'],
            ]);
        }
        return $this->requireOwnedAttempt($uuid, $userId);
    }

    public function getAttemptByUuid(string $uuid): ?array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->attempts} WHERE uuid = %s",
            $uuid
        ), ARRAY_A);
        return is_array($row) ? $this->hydrateAttempt($row) : null;
    }

    public function getOwnedAttempt(string $uuid, int $userId): ?array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->attempts} WHERE uuid = %s AND user_id = %d",
            $uuid,
            $userId
        ), ARRAY_A);
        return is_array($row) ? $this->hydrateAttempt($row) : null;
    }

    /** @param array<string,mixed> $artifact */
    public function attachArtifact(string $attemptUuid, int $userId, array $artifact): array
    {
        return $this->transaction(function () use ($attemptUuid, $userId, $artifact): array {
            $reference = $this->selectRow($this->wpdb->prepare(
                "SELECT assignment_id FROM {$this->tables->attempts} WHERE uuid = %s AND user_id = %d LIMIT 1",
                $attemptUuid,
                $userId
            ), ARRAY_A);
            if (!is_array($reference)) {
                throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
            }
            $assignmentId = (int) $reference['assignment_id'];
            $assignment = $this->requireAssignmentForUpdate($assignmentId);
            $assignment = $this->assignmentForLearner($assignment, $userId);
            $row = $this->selectRow($this->wpdb->prepare(
                "SELECT * FROM {$this->tables->attempts} WHERE uuid = %s AND user_id = %d FOR UPDATE",
                $attemptUuid,
                $userId
            ), ARRAY_A);
            if (!is_array($row) || (int) $row['assignment_id'] !== $assignmentId) {
                throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
            }
            if ($row['artifact_id'] !== null) {
                return $this->hydrateAttempt($row);
            }
            if (!AttemptStatus::canReceiveArtifact((string) $row['status'])) {
                throw new Problem('OJ_ML_ATTEMPT_IMMUTABLE', '이미 접수된 시도는 변경할 수 없습니다.', 409);
            }
            if ($assignment['status'] !== AssignmentStatus::PUBLISHED) {
                throw new Problem('OJ_ML_ASSIGNMENT_CLOSED', '종료된 과제에는 제출할 수 없습니다.', 409);
            }
            $now = $this->now();
            $late = $assignment['due_at'] !== null && $assignment['due_at'] < $now;
            if ($late && (($assignment['policy']['allowLateSubmission'] ?? false) !== true)) {
                throw new Problem('OJ_ML_ASSIGNMENT_CLOSED', '제출 기한이 지난 과제입니다.', 409);
            }
            $written = $this->wpdb->insert(
                $this->tables->artifacts,
                [
                    'uuid' => $artifact['uuid'],
                    'attempt_id' => (int) $row['id'],
                    'storage_key' => $artifact['storage_key'],
                    'original_name' => $artifact['original_name'],
                    'sha256' => $artifact['sha256'],
                    'bytes' => $artifact['bytes'],
                    'mime_type' => $artifact['mime_type'],
                    'created_at' => $now,
                    'deleted_at' => null,
                ],
                ['%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
            );
            $this->assertWritten($written, 'OJ_ML_ARTIFACT_RECORD_FAILED');
            $artifactId = (int) $this->wpdb->insert_id;
            $written = $this->wpdb->update(
                $this->tables->attempts,
                [
                    'artifact_id' => $artifactId,
                    'status' => AttemptStatus::QUARANTINED,
                    'late' => $late ? 1 : 0,
                    'received_at' => $now,
                    'updated_at' => $now,
                    'progress_json' => null,
                ],
                ['id' => (int) $row['id']],
                ['%d', '%s', '%d', '%s', '%s', '%s'],
                ['%d']
            );
            if ($written !== 1) {
                throw new Problem('OJ_ML_ARTIFACT_RECORD_FAILED', '제출 파일을 시도에 연결하지 못했습니다.', 500);
            }
            $this->insertEvent($assignmentId, (int) $row['id'], $userId, 'attempt.submitted', [
                'artifactId' => $artifact['uuid'], 'bytes' => $artifact['bytes'], 'sha256' => $artifact['sha256'],
            ], wp_generate_uuid4());
            return $this->requireOwnedAttempt($attemptUuid, $userId);
        });
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @param array<string,mixed> $facts
     * @param array{actorUserId:int,type:string,payload:array<string,mixed>,correlationId:string}|null $event
     */
    public function completeInspection(
        int $attemptId,
        string $evaluationStatus,
        string $attemptStatus,
        array $findings,
        array $facts,
        ?string $failureCode,
        string $evaluator = 'mlpx-preflight',
        string $evaluatorVersion = '1',
        ?string $evaluatorDigest = null,
        ?array $event = null
    ): array {
        return $this->transaction(function () use ($attemptId, $evaluationStatus, $attemptStatus, $findings, $facts, $failureCode, $evaluator, $evaluatorVersion, $evaluatorDigest, $event): array {
            $attempt = $this->getAttemptById($attemptId, true);
            if ($attempt === null) {
                throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
            }
            if (!in_array($attempt['status'], [AttemptStatus::QUARANTINED, AttemptStatus::SYSTEM_PENDING], true)) {
                throw new Problem('OJ_ML_ATTEMPT_IMMUTABLE', '확정된 검사 결과는 덮어쓸 수 없습니다.', 409);
            }
            $now = $this->now();
            $this->assertWritten($this->wpdb->insert(
                $this->tables->evaluations,
                [
                    'attempt_id' => $attemptId,
                    'status' => $evaluationStatus,
                    'evaluator' => $evaluator,
                    'evaluator_version' => $evaluatorVersion,
                    'evaluator_digest' => $evaluatorDigest,
                    'findings_json' => $this->json($findings),
                    'facts_json' => $this->json($facts),
                    'created_at' => $now,
                    'completed_at' => $evaluationStatus === 'system_pending' ? null : $now,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            ), 'OJ_ML_EVALUATION_SAVE_FAILED');
            $written = $this->wpdb->update(
                $this->tables->attempts,
                [
                    'status' => $attemptStatus,
                    'failure_code' => $failureCode,
                    'failure_json' => $failureCode === null ? null : $this->json($findings),
                    'format_version' => isset($facts['formatVersion']) ? (int) $facts['formatVersion'] : null,
                    'client_app_version' => isset($facts['appVersion']) ? (string) $facts['appVersion'] : null,
                    'updated_at' => $now,
                ],
                ['id' => $attemptId],
                ['%s', '%s', '%s', '%d', '%s', '%s'],
                ['%d']
            );
            if ($written === false) {
                throw new Problem('OJ_ML_EVALUATION_SAVE_FAILED', '검사 결과를 저장하지 못했습니다.', 500);
            }
            if ($event !== null) {
                $this->insertEvent(
                    (int) $attempt['assignment_id'],
                    $attemptId,
                    $event['actorUserId'],
                    $event['type'],
                    $event['payload'],
                    $event['correlationId']
                );
            }
            return $this->getAttemptById($attemptId) ?? throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
        });
    }

    public function getAttemptResult(string $uuid, ?int $ownerId = null): ?array
    {
        $attempt = $ownerId === null ? $this->getAttemptByUuid($uuid) : $this->getOwnedAttempt($uuid, $ownerId);
        if ($attempt === null) {
            return null;
        }
        return $this->decorateAttempt($attempt, $ownerId !== null);
    }

    /** @return array<string,mixed>|null Internal-only record; never pass it to a REST presenter. */
    public function getArtifactForInspection(int $attemptId): ?array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT id,uuid,attempt_id,storage_key,original_name,sha256,bytes,mime_type,created_at
             FROM {$this->tables->artifacts}
             WHERE attempt_id = %d AND deleted_at IS NULL",
            $attemptId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['attempt_id'] = (int) $row['attempt_id'];
        $row['bytes'] = (int) $row['bytes'];
        return $row;
    }

    /**
     * @return array{
     *   items:list<array{user_id:int,attempts_used:int,attempt:?array}>,
     *   total:int,page:int,per_page:int
     * }
     */
    public function listResults(
        int $assignmentId,
        int $page,
        int $perPage,
        ?string $status = null,
        ?int $userId = null,
        string $search = ''
    ): array {
        $join = "LEFT JOIN {$this->tables->attempts} a ON a.id = (
            SELECT a2.id FROM {$this->tables->attempts} a2
            WHERE a2.assignment_id = l.assignment_id AND a2.user_id = l.user_id
              AND a2.status <> %s
              AND a2.artifact_id IS NOT NULL
              AND a2.received_at IS NOT NULL
            ORDER BY a2.id DESC LIMIT 1
        )";
        $where = ['l.assignment_id = %d', 'l.removed_at IS NULL'];
        $values = [$assignmentId];
        if ($status === 'not_submitted') {
            $where[] = 'a.id IS NULL';
        } elseif ($status !== null && $status !== '') {
            $where[] = 'a.status = %s';
            $values[] = $status;
        }
        if ($userId !== null && $userId > 0) {
            $where[] = 'l.user_id = %d';
            $values[] = $userId;
        }
        if ($search !== '') {
            $join .= " INNER JOIN {$this->wpdb->users} u ON u.ID = l.user_id";
            $where[] = '(u.display_name LIKE %s OR u.user_login LIKE %s)';
            $needle = '%' . $this->wpdb->esc_like($search) . '%';
            $values[] = $needle;
            $values[] = $needle;
        }
        $clause = implode(' AND ', $where);
        $total = (int) $this->selectValue($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables->assignmentLearners} l {$join} WHERE {$clause}",
            AttemptStatus::CANCELLED, ...$values
        ));
        $offset = ($page - 1) * $perPage;
        $rows = $this->selectRows($this->wpdb->prepare(
            "SELECT l.user_id AS roster_user_id,l.max_attempts_override,l.due_at_override,l.override_revision,
                    (SELECT COUNT(*) FROM {$this->tables->attempts} au
                     WHERE au.assignment_id = l.assignment_id
                       AND au.user_id = l.user_id
                       AND au.status <> %s) AS attempts_used,
                    a.*
             FROM {$this->tables->assignmentLearners} l {$join}
             WHERE {$clause} ORDER BY l.user_id ASC LIMIT %d OFFSET %d",
            ...[AttemptStatus::CANCELLED, AttemptStatus::CANCELLED, ...$values, $perPage, $offset]
        ), ARRAY_A) ?: [];
        $items = [];
        $attempts = [];
        foreach ($rows as $row) {
            $rosterUserId = (int) $row['roster_user_id'];
            $attemptsUsed = (int) $row['attempts_used'];
            unset($row['roster_user_id'], $row['attempts_used']);
            $attempt = $row['id'] === null ? null : $this->hydrateAttempt($row);
            if ($attempt !== null) {
                $attempts[] = $attempt;
            }
            $items[] = [
                'user_id' => $rosterUserId,
                'attempts_used' => $attemptsUsed,
                'allowance' => [
                    'maxAttempts' => isset($row['max_attempts_override']) ? (int) $row['max_attempts_override'] : null,
                    'dueAt' => $row['due_at_override'] ?? null,
                    'revision' => (int) ($row['override_revision'] ?? 0),
                ],
                'attempt' => $attempt,
            ];
        }
        $decoratedAttempts = [];
        foreach ($this->decorateAttempts($attempts, false) as $attempt) {
            $decoratedAttempts[(int) $attempt['id']] = $attempt;
        }
        foreach ($items as &$item) {
            if (is_array($item['attempt'])) {
                $item['attempt'] = $decoratedAttempts[(int) $item['attempt']['id']] ?? $item['attempt'];
            }
        }
        unset($item);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @param array<string,mixed> $review */
    public function appendReview(
        int $attemptId,
        int $reviewerId,
        int $expectedVersion,
        array $review,
        ?string $eventType = null,
        string $correlationId = ''
    ): array
    {
        return $this->transaction(function () use ($attemptId, $reviewerId, $expectedVersion, $review, $eventType, $correlationId): array {
            $attempt = $this->getAttemptById($attemptId, true);
            if ($attempt === null) {
                throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
            }
            if (!in_array($attempt['status'], [AttemptStatus::ACCEPTED, AttemptStatus::UNDER_REVIEW, AttemptStatus::GRADED], true)) {
                throw new Problem('OJ_ML_ATTEMPT_NOT_REVIEWABLE', '검사를 통과한 시도만 검토할 수 있습니다.', 409);
            }
            $latest = $this->selectRow($this->wpdb->prepare(
                "SELECT * FROM {$this->tables->reviews} WHERE attempt_id = %d ORDER BY version DESC LIMIT 1 FOR UPDATE",
                $attemptId
            ), ARRAY_A);
            $currentVersion = is_array($latest) ? (int) $latest['version'] : 0;
            if ($currentVersion !== $expectedVersion) {
                throw new Problem('OJ_ML_REVIEW_VERSION_CONFLICT', '검토 내용이 다른 곳에서 변경되었습니다.', 409, [
                    'expectedVersion' => $expectedVersion,
                    'currentVersion' => $currentVersion,
                ]);
            }
            $version = $currentVersion + 1;
            $status = (string) ($review['status'] ?? 'draft');
            if (!in_array($status, ['draft', 'released'], true)) {
                throw new Problem('OJ_ML_REVIEW_STATUS_INVALID', '검토 상태가 올바르지 않습니다.', 400);
            }
            $evaluation = $this->latestEvaluation($attemptId);
            $now = $this->now();
            $this->assertWritten($this->wpdb->insert(
                $this->tables->reviews,
                [
                    'attempt_id' => $attemptId,
                    'evaluation_id' => $evaluation['id'] ?? null,
                    'reviewer_id' => $reviewerId,
                    'version' => $version,
                    'status' => $status,
                    'score' => $review['score'],
                    'max_score' => $review['max_score'],
                    'feedback' => $review['feedback'],
                    'details_json' => isset($review['details']) ? $this->json($review['details']) : null,
                    'rubric_revision' => (int) $attempt['policy_revision'],
                    'created_at' => $now,
                    'released_at' => $status === 'released' ? $now : null,
                ],
                ['%d', '%d', '%d', '%d', '%s', '%f', '%f', '%s', '%s', '%d', '%s', '%s']
            ), 'OJ_ML_REVIEW_SAVE_FAILED');
            $nextAttemptStatus = $status === 'released' ? AttemptStatus::GRADED : AttemptStatus::UNDER_REVIEW;
            if ($this->wpdb->update(
                $this->tables->attempts,
                ['status' => $nextAttemptStatus, 'updated_at' => $now],
                ['id' => $attemptId],
                ['%s', '%s'],
                ['%d']
            ) === false) {
                throw new Problem('OJ_ML_REVIEW_SAVE_FAILED', '검토 상태를 저장하지 못했습니다.', 500);
            }
            $stored = $this->latestReview($attemptId, false)
                ?? throw new Problem('OJ_ML_REVIEW_SAVE_FAILED', '검토 내용을 저장하지 못했습니다.', 500);
            if ($eventType !== null) {
                $this->insertEvent(
                    (int) $attempt['assignment_id'],
                    $attemptId,
                    $reviewerId,
                    $eventType,
                    ['version' => (int) $stored['version']],
                    $correlationId
                );
            }
            return $stored;
        });
    }

    public function releaseReview(
        int $attemptId,
        int $reviewerId,
        int $expectedVersion,
        string $correlationId = ''
    ): array
    {
        $latest = $this->latestReview($attemptId, false);
        if ($latest === null) {
            throw new Problem('OJ_ML_REVIEW_NOT_FOUND', '공개할 검토 내용이 없습니다.', 404);
        }
        if ((int) $latest['version'] !== $expectedVersion) {
            throw new Problem('OJ_ML_REVIEW_VERSION_CONFLICT', '공개할 검토 버전이 변경되었습니다. 최신 평가를 확인하세요.', 409);
        }
        return $this->appendReview(
            $attemptId,
            $reviewerId,
            $expectedVersion,
            [
                'status' => 'released',
                'score' => $latest['score'],
                'max_score' => $latest['max_score'],
                'feedback' => $latest['feedback'],
                'details' => $latest['details'] ?? null,
            ],
            'review.released',
            $correlationId
        );
    }

    /** @param array<string,mixed> $payload */
    public function insertEvent(
        ?int $assignmentId,
        ?int $attemptId,
        ?int $actorUserId,
        string $eventType,
        array $payload,
        string $correlationId
    ): void {
        $this->assertWritten($this->wpdb->insert(
            $this->tables->events,
            [
                'assignment_id' => $assignmentId,
                'attempt_id' => $attemptId,
                'actor_user_id' => $actorUserId,
                'event_type' => $eventType,
                'payload_json' => $this->json($payload),
                'correlation_id' => substr($correlationId, 0, 100),
                'created_at' => $this->now(),
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s']
        ), 'OJ_ML_EVENT_SAVE_FAILED');
    }

    private function learnerCount(int $assignmentId): int
    {
        return (int) $this->selectValue($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tables->assignmentLearners} WHERE assignment_id = %d AND removed_at IS NULL",
            $assignmentId
        ));
    }

    /**
     * Queue a fresh inspection without changing the received bytes or spending
     * another learner attempt. The caller checks current management/context access.
     * @return array{attempt:array,created:bool}
     */
    public function queueReconsideration(
        string $uuid,
        int $actorId,
        int $expectedEvaluationId,
        string $reason,
        string $idempotencyKey,
        string $correlationId
    ): array {
        if ($expectedEvaluationId < 1 || $actorId < 1 || trim($reason) === ''
            || strlen($reason) > 1200 || preg_match('//u', $reason) !== 1
            || preg_match_all('/./us', $reason) > 300
            || preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $idempotencyKey) !== 1) {
            throw new Problem('OJ_ML_RECONSIDER_INPUT_INVALID', '재검토 사유와 검사 버전을 확인하세요.', 400);
        }
        return $this->transaction(function () use ($uuid, $actorId, $expectedEvaluationId, $reason, $idempotencyKey, $correlationId): array {
            $row = $this->selectRow($this->wpdb->prepare(
                "SELECT * FROM {$this->tables->attempts} WHERE uuid = %s FOR UPDATE", $uuid
            ), ARRAY_A);
            if (!is_array($row)) {
                throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
            }
            $attempt = $this->hydrateAttempt($row);
            $attemptId = (int) $attempt['id'];
            // The marker contains only a SHA-256 value and matches our compact
            // own JSON encoding. No DB-specific JSON operator is required.
            $marker = hash('sha256', $idempotencyKey);
            $previous = $this->selectRow($this->wpdb->prepare(
                "SELECT actor_user_id,payload_json FROM {$this->tables->events}
                 WHERE attempt_id = %d AND event_type = 'reconsideration.requested'
                   AND payload_json LIKE %s ORDER BY id DESC LIMIT 1",
                $attemptId, '%"requestKey":"' . $marker . '"%'
            ), ARRAY_A);
            if (is_array($previous)) {
                $payload = $this->decodeJson((string) $previous['payload_json']);
                if ((int) ($previous['actor_user_id'] ?? 0) !== $actorId
                    || (int) ($payload['evaluationId'] ?? 0) !== $expectedEvaluationId
                    || ($payload['reason'] ?? '') !== $reason) {
                    throw new Problem('OJ_ML_RECONSIDER_IDEMPOTENCY_CONFLICT', '같은 재검토 요청 키를 다른 내용으로 사용할 수 없습니다.', 409);
                }
                return ['attempt' => $this->decorateAttempt($attempt, false), 'created' => false];
            }
            $evaluation = $this->latestEvaluation($attemptId);
            if ((int) ($evaluation['id'] ?? 0) !== $expectedEvaluationId) {
                throw new Problem('OJ_ML_RECONSIDER_VERSION_CONFLICT', '검사 결과가 변경되었습니다. 최신 상태를 확인하세요.', 409);
            }
            if ($attempt['status'] !== AttemptStatus::NEEDS_RESUBMIT) {
                throw new Problem('OJ_ML_RECONSIDER_STATE_INVALID', '재제출 필요 상태의 접수 파일만 재검토할 수 있습니다.', 409);
            }
            $artifact = $this->artifactForAttempt($attemptId);
            if ($artifact === null || (int) $artifact['id'] !== (int) ($attempt['artifact_id'] ?? 0)) {
                throw new Problem('OJ_ML_ARTIFACT_NOT_FOUND', '보존된 제출 원본이 없어 재검토할 수 없습니다.', 404);
            }
            $payload = ['evaluationId' => $expectedEvaluationId, 'reason' => $reason, 'requestKey' => $marker];
            $this->insertEvent((int) $attempt['assignment_id'], $attemptId, $actorId,
                'reconsideration.requested', $payload, $correlationId);
            $updated = $this->wpdb->update($this->tables->attempts, [
                'status' => AttemptStatus::SYSTEM_PENDING, 'inspection_retries' => 0,
                'next_inspection_at' => $this->now(), 'updated_at' => $this->now(),
                'failure_code' => null, 'failure_json' => null,
            ], ['id' => $attemptId], ['%s', '%d', '%s', '%s', '%s', '%s'], ['%d']);
            if ($updated !== 1) {
                throw new Problem('OJ_ML_RECONSIDER_QUEUE_FAILED', '재검토 요청을 저장하지 못했습니다.', 503);
            }
            $this->insertEvent((int) $attempt['assignment_id'], $attemptId, $actorId,
                'reconsideration.queued', $payload, $correlationId);
            $attempt = $this->getAttemptById($attemptId)
                ?? throw new Problem('OJ_ML_RECONSIDER_QUEUE_FAILED', '재검토 상태를 확인하지 못했습니다.', 503);
            return ['attempt' => $this->decorateAttempt($attempt, false), 'created' => true];
        });
    }

    /** Pending work survives PHP interruption. Five failures require operator intervention. */
    public function pendingAttempts(int $limit = 10): array
    {
        return $this->selectColumn($this->wpdb->prepare(
            "SELECT uuid FROM {$this->tables->attempts}
             WHERE status IN ('quarantined','system_pending') AND artifact_id IS NOT NULL
               AND inspection_retries < 5 AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
               AND (next_inspection_at IS NULL OR next_inspection_at <= UTC_TIMESTAMP())
             ORDER BY updated_at,id LIMIT %d", max(1, min(100, $limit))
        ));
    }

    public function recordInspectionRetry(int $attemptId): void
    {
        $this->assertWritten($this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tables->attempts} SET inspection_retries = inspection_retries + 1,
             next_inspection_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
             WHERE id = %d AND status IN ('quarantined','system_pending')", $attemptId
        )), 'OJ_ML_RETRY_SAVE_FAILED');
    }

    public function storageBytes(): int
    {
        return (int) $this->selectValue("SELECT COALESCE(SUM(bytes),0) FROM {$this->tables->artifacts} WHERE deleted_at IS NULL");
    }

    private function requireAssignment(int $assignmentId): array
    {
        return $this->getAssignment($assignmentId) ?? throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
    }

    private function requireAssignmentForUpdate(int $assignmentId): array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->assignments} WHERE id = %d FOR UPDATE",
            $assignmentId
        ), ARRAY_A);
        if (!is_array($row)) {
            throw new Problem('OJ_ML_ASSIGNMENT_NOT_FOUND', '과제를 찾을 수 없습니다.', 404);
        }
        return $this->hydrateAssignment($row, true);
    }

    /** @param array<string,mixed> $assignment */
    private function assertRevision(array $assignment, int $expectedRevision): void
    {
        if ((int) $assignment['revision'] !== $expectedRevision) {
            throw new Problem('OJ_ML_REVISION_CONFLICT', '과제가 다른 곳에서 변경되었습니다.', 409, [
                'expectedRevision' => $expectedRevision,
                'currentRevision' => (int) $assignment['revision'],
            ]);
        }
    }

    private function requireOwnedAttempt(string $uuid, int $userId): array
    {
        return $this->getOwnedAttempt($uuid, $userId) ?? throw new Problem('OJ_ML_ATTEMPT_NOT_FOUND', '시도를 찾을 수 없습니다.', 404);
    }

    private function getAttemptById(int $attemptId, bool $forUpdate = false): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->attempts} WHERE id = %d{$suffix}",
            $attemptId
        ), ARRAY_A);
        return is_array($row) ? $this->hydrateAttempt($row) : null;
    }

    /** @param list<array{type:string,key:string}> $targets */
    private function replaceTargets(int $assignmentId, array $targets): void
    {
        $this->assertWritten($this->wpdb->delete($this->tables->assignmentTargets, ['assignment_id' => $assignmentId], ['%d']), 'OJ_ML_ROSTER_REPLACE_FAILED');
        foreach ($targets as $target) {
            $this->assertWritten($this->wpdb->insert(
                $this->tables->assignmentTargets,
                [
                    'assignment_id' => $assignmentId,
                    'target_type' => $target['type'],
                    'target_key' => $target['key'],
                    'created_at' => $this->now(),
                ],
                ['%d', '%s', '%s', '%s']
            ), 'OJ_ML_TARGET_SAVE_FAILED');
        }
    }

    /** @param list<int> $learners */
    private function replaceLearners(int $assignmentId, array $learners): void
    {
        $this->assertWritten($this->wpdb->delete($this->tables->assignmentLearners, ['assignment_id' => $assignmentId], ['%d']), 'OJ_ML_ROSTER_REPLACE_FAILED');
        foreach ($learners as $userId) {
            $this->assertWritten($this->wpdb->insert(
                $this->tables->assignmentLearners,
                [
                    'assignment_id' => $assignmentId,
                    'user_id' => $userId,
                    'source' => 'context_snapshot',
                    'enrolled_at' => $this->now(),
                    'removed_at' => null,
                ],
                ['%d', '%d', '%s', '%s', '%s']
            ), 'OJ_ML_LEARNER_SAVE_FAILED');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateAssignment(array $row, bool $relations): array
    {
        foreach (['id', 'created_by', 'max_attempts', 'revision'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        if (array_key_exists('has_submission_history', $row)) {
            $row['has_submission_history'] = (bool) (int) $row['has_submission_history'];
        }
        $row['policy'] = $this->decodeJson((string) $row['policy_json']);
        $row['rubric'] = $this->decodeJson((string) $row['rubric_json']);
        unset($row['policy_json'], $row['rubric_json']);
        if ($relations) {
            $row['targets'] = $this->selectRows($this->wpdb->prepare(
                "SELECT target_type AS type,target_key AS `key` FROM {$this->tables->assignmentTargets}
                 WHERE assignment_id = %d ORDER BY id ASC",
                $row['id']
            ), ARRAY_A) ?: [];
            $row['learners'] = array_map('intval', $this->selectColumn($this->wpdb->prepare(
                "SELECT user_id FROM {$this->tables->assignmentLearners}
                 WHERE assignment_id = %d AND removed_at IS NULL ORDER BY user_id ASC",
                $row['id']
            )));
        }
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateAttempt(array $row): array
    {
        foreach (['id', 'assignment_id', 'user_id', 'attempt_no', 'policy_revision'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['artifact_id'] = $row['artifact_id'] === null ? null : (int) $row['artifact_id'];
        $row['format_version'] = $row['format_version'] === null ? null : (int) $row['format_version'];
        $row['late'] = (bool) $row['late'];
        $row['progress'] = $this->decodeNullableJson($row['progress_json']);
        $row['failure'] = $row['failure_code'] === null ? null : [
            'code' => $row['failure_code'],
            'details' => $this->decodeNullableJson($row['failure_json']),
        ];
        unset($row['progress_json'], $row['failure_code'], $row['failure_json'], $row['idempotency_key']);
        return $row;
    }

    /**
     * @param list<array<string,mixed>> $attempts
     * @return list<array<string,mixed>>
     */
    private function decorateAttempts(array $attempts, bool $releasedReviewOnly): array
    {
        $attemptIds = $this->positiveIds(array_map(
            static fn (array $attempt): int => (int) ($attempt['id'] ?? 0),
            $attempts
        ));
        if ($attemptIds === []) {
            return $attempts;
        }
        $artifacts = $this->artifactsForAttempts($attemptIds);
        $evaluations = $this->latestEvaluationsForAttempts($attemptIds);
        $reviewPairs = $this->latestReviewPairsForAttempts($attemptIds);
        foreach ($attempts as &$attempt) {
            $attemptId = (int) $attempt['id'];
            $releasedReview = $reviewPairs['released'][$attemptId] ?? null;
            $attempt['artifact'] = $artifacts[$attemptId] ?? null;
            $attempt['evaluation'] = $evaluations[$attemptId] ?? null;
            $attempt['review'] = $releasedReviewOnly
                ? $releasedReview
                : ($reviewPairs['latest'][$attemptId] ?? null);
            $attempt['released_review'] = $releasedReview;
        }
        unset($attempt);
        return $attempts;
    }

    /** @param list<int> $attemptIds @return array<int,array<string,mixed>> */
    private function artifactsForAttempts(array $attemptIds): array
    {
        $placeholders = implode(',', array_fill(0, count($attemptIds), '%d'));
        $rows = $this->selectRows($this->wpdb->prepare(
            "SELECT id,uuid,attempt_id,original_name,sha256,bytes,mime_type,created_at
               FROM {$this->tables->artifacts}
              WHERE attempt_id IN ({$placeholders}) AND deleted_at IS NULL",
            ...$attemptIds
        ), ARRAY_A) ?: [];
        $artifacts = [];
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['attempt_id'] = (int) $row['attempt_id'];
            $row['bytes'] = (int) $row['bytes'];
            $artifacts[$row['attempt_id']] = $row;
        }
        return $artifacts;
    }

    /** @param list<int> $attemptIds @return array<int,array<string,mixed>> */
    private function latestEvaluationsForAttempts(array $attemptIds): array
    {
        $placeholders = implode(',', array_fill(0, count($attemptIds), '%d'));
        $rows = $this->selectRows($this->wpdb->prepare(
            "SELECT e.* FROM {$this->tables->evaluations} e
              WHERE e.attempt_id IN ({$placeholders})
                AND e.id = (
                    SELECT e2.id FROM {$this->tables->evaluations} e2
                     WHERE e2.attempt_id = e.attempt_id
                     ORDER BY e2.id DESC LIMIT 1
                )",
            ...$attemptIds
        ), ARRAY_A) ?: [];
        $evaluations = [];
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['attempt_id'] = (int) $row['attempt_id'];
            $row['findings'] = $this->decodeJson((string) $row['findings_json']);
            $row['facts'] = $this->decodeJson((string) $row['facts_json']);
            unset($row['findings_json'], $row['facts_json']);
            $evaluations[$row['attempt_id']] = $row;
        }
        return $evaluations;
    }

    /**
     * Load the latest review of any status and the latest released review in
     * one bulk query so teacher result pages keep a constant five-query budget.
     *
     * @param list<int> $attemptIds
     * @return array{latest:array<int,array<string,mixed>>,released:array<int,array<string,mixed>>}
     */
    private function latestReviewPairsForAttempts(array $attemptIds): array
    {
        $placeholders = implode(',', array_fill(0, count($attemptIds), '%d'));
        $rows = $this->selectRows($this->wpdb->prepare(
            "SELECT r.* FROM {$this->tables->reviews} r
              WHERE r.attempt_id IN ({$placeholders})
                AND (
                    r.id = (
                        SELECT r2.id FROM {$this->tables->reviews} r2
                         WHERE r2.attempt_id = r.attempt_id
                         ORDER BY r2.version DESC,r2.id DESC LIMIT 1
                    )
                    OR r.id = (
                        SELECT r3.id FROM {$this->tables->reviews} r3
                         WHERE r3.attempt_id = r.attempt_id AND r3.status = 'released'
                         ORDER BY r3.version DESC,r3.id DESC LIMIT 1
                    )
                )
              ORDER BY r.attempt_id ASC,r.version DESC,r.id DESC",
            ...$attemptIds
        ), ARRAY_A) ?: [];
        $latest = [];
        $released = [];
        foreach ($rows as $row) {
            foreach (['id', 'attempt_id', 'reviewer_id', 'version', 'rubric_revision'] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $row['evaluation_id'] = $row['evaluation_id'] === null ? null : (int) $row['evaluation_id'];
            $row['score'] = $row['score'] === null ? null : (float) $row['score'];
            $row['max_score'] = $row['max_score'] === null ? null : (float) $row['max_score'];
            $row['details'] = isset($row['details_json']) ? $this->decodeJson((string) $row['details_json']) : null;
            unset($row['details_json']);
            $attemptId = $row['attempt_id'];
            if (!isset($latest[$attemptId])
                || $row['version'] > $latest[$attemptId]['version']
                || ($row['version'] === $latest[$attemptId]['version'] && $row['id'] > $latest[$attemptId]['id'])
            ) {
                $latest[$attemptId] = $row;
            }
            if ($row['status'] === 'released'
                && (!isset($released[$attemptId])
                    || $row['version'] > $released[$attemptId]['version']
                    || ($row['version'] === $released[$attemptId]['version'] && $row['id'] > $released[$attemptId]['id']))
            ) {
                $released[$attemptId] = $row;
            }
        }
        return ['latest' => $latest, 'released' => $released];
    }

    /** @param list<int> $ids @return list<int> */
    private function positiveIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }
        return array_values($normalized);
    }

    /** @param array<string,mixed> $attempt @return array<string,mixed> */
    private function decorateAttempt(array $attempt, bool $releasedReviewOnly): array
    {
        $attemptId = (int) $attempt['id'];
        $releasedReview = $this->latestReview($attemptId, true);
        $attempt['artifact'] = $this->artifactForAttempt($attemptId);
        $attempt['evaluation'] = $this->latestEvaluation($attemptId);
        $attempt['review'] = $releasedReviewOnly
            ? $releasedReview
            : $this->latestReview($attemptId, false);
        $attempt['released_review'] = $releasedReview;
        return $attempt;
    }

    private function artifactForAttempt(int $attemptId): ?array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT id,uuid,attempt_id,original_name,sha256,bytes,mime_type,created_at
             FROM {$this->tables->artifacts} WHERE attempt_id = %d AND deleted_at IS NULL",
            $attemptId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['attempt_id'] = (int) $row['attempt_id'];
        $row['bytes'] = (int) $row['bytes'];
        return $row;
    }

    private function latestEvaluation(int $attemptId): ?array
    {
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->evaluations} WHERE attempt_id = %d ORDER BY id DESC LIMIT 1",
            $attemptId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['attempt_id'] = (int) $row['attempt_id'];
        $row['findings'] = $this->decodeJson((string) $row['findings_json']);
        $row['facts'] = $this->decodeJson((string) $row['facts_json']);
        unset($row['findings_json'], $row['facts_json']);
        return $row;
    }

    private function latestReview(int $attemptId, bool $releasedOnly): ?array
    {
        $released = $releasedOnly ? " AND status = 'released'" : '';
        $row = $this->selectRow($this->wpdb->prepare(
            "SELECT * FROM {$this->tables->reviews}
             WHERE attempt_id = %d{$released} ORDER BY version DESC LIMIT 1",
            $attemptId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        foreach (['id', 'attempt_id', 'reviewer_id', 'version', 'rubric_revision'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['evaluation_id'] = $row['evaluation_id'] === null ? null : (int) $row['evaluation_id'];
        $row['score'] = $row['score'] === null ? null : (float) $row['score'];
        $row['max_score'] = $row['max_score'] === null ? null : (float) $row['max_score'];
        $row['details'] = isset($row['details_json']) ? $this->decodeJson((string) $row['details_json']) : null;
        unset($row['details_json']);
        return $row;
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->wpdb->query('START TRANSACTION') === false) {
            throw new Problem('OJ_ML_DATABASE_TRANSACTION_FAILED', '데이터베이스 트랜잭션을 시작하지 못했습니다.', 503);
        }
        try {
            $result = $callback();
            if ($this->wpdb->query('COMMIT') === false) {
                throw new Problem('OJ_ML_DATABASE_COMMIT_FAILED', '데이터베이스 변경을 완료하지 못했습니다.', 500);
            }
            return $result;
        } catch (Throwable $throwable) {
            if ($this->wpdb->query('ROLLBACK') === false) {
                throw new Problem('OJ_ML_DATABASE_ROLLBACK_FAILED', '데이터베이스 연결이 중단되었습니다. 운영자 확인이 필요합니다.', 503);
            }
            throw $throwable;
        }
    }

    private function selectValue(string $query): mixed
    {
        $result = $this->wpdb->get_var($query);
        $this->assertReadable();
        return $result;
    }

    private function selectRow(string $query, mixed $output = null): ?array
    {
        $result = $this->wpdb->get_row($query, $output ?? ARRAY_A);
        $this->assertReadable();
        return is_array($result) ? $result : null;
    }

    private function selectRows(string $query, mixed $output = null): array
    {
        $result = $this->wpdb->get_results($query, $output ?? ARRAY_A);
        $this->assertReadable();
        return is_array($result) ? $result : [];
    }

    private function selectColumn(string $query): array
    {
        $result = $this->wpdb->get_col($query);
        $this->assertReadable();
        return is_array($result) ? $result : [];
    }

    private function assertReadable(): void
    {
        if ((string) ($this->wpdb->last_error ?? '') !== '') {
            throw new Problem('OJ_ML_DATABASE_READ_FAILED', '데이터를 조회하지 못했습니다. 잠시 후 다시 시도하세요.', 503);
        }
    }
    private function assertWritten(int|false $written, string $code): void
    {
        if ($written === false) {
            throw new Problem($code, '데이터베이스에 저장하지 못했습니다.', 500);
        }
    }

    private function json(mixed $value): string
    {
        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new Problem('OJ_ML_JSON_ENCODE_FAILED', 'JSON 데이터를 저장할 수 없습니다.', 500);
        }
        return $json;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed>|null */
    private function decodeNullableJson(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $value = json_decode($json, true);
        return is_array($value) ? $value : null;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
