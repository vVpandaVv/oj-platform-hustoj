<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Classes\Application\ClassRepository;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassSaveCommand;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassProblem;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use Throwable;

final class WordPressClassRepository implements ClassRepository, \CodingPanda\OjPlatform\Modules\Classes\Contract\ClassHomeRepository, \CodingPanda\OjPlatform\Modules\Classes\Contract\ClassLearningRepository
{
    public function __construct(
        private readonly ApplicationDatabase $database,
        private readonly Clock $clock
    ) {
    }

    public function find(int $classId): ?LearningClass
    {
        $row = $this->database->row(
            'SELECT * FROM classes WHERE id = %d LIMIT 1',
            [$classId]
        );

        return $row === null ? null : LearningClass::fromRow($row);
    }

    public function homeFor(int $userId, array $groupIds, bool $managed, bool $all, string $now, int $limit): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn (int $id): bool => $id > 0)));
        $direct = '(c.visibility IN (%s, %s) OR c.owner_user_id = %d OR e.id IS NOT NULL OR i.id IS NOT NULL)';
        $sql = 'SELECT DISTINCT c.*, ' . ($managed ? '0' : 'CASE WHEN ' . $direct . ' THEN 0 ELSE 1 END') . ' AS home_priority'
            . ' FROM classes c LEFT JOIN class_enrollments e ON e.class_id = c.id AND e.user_id = %d AND e.status = %s'
            . ' LEFT JOIN class_instructors i ON i.class_id = c.id AND i.user_id = %d AND i.status = %s'
            . ' WHERE c.deleted_at IS NULL AND c.enabled = 1';
        $values = $managed ? [] : ['public', 'password', $userId];
        array_push($values, $userId, 'active', $userId, 'active');
        if ($managed) {
            $sql .= $all ? '' : ' AND (c.owner_user_id = %d OR i.id IS NOT NULL)';
            if (! $all) { $values[] = $userId; }
        } else {
            $sql .= ' AND (' . $direct;
            array_push($values, 'public', 'password', $userId);
            if ($ids !== []) {
                $sql .= ' OR (c.visibility = %s AND EXISTS (SELECT 1 FROM class_group_links l WHERE l.class_id = c.id AND l.group_id IN ('
                    . implode(',', array_fill(0, count($ids), '%d')) . ')))';
                array_push($values, 'group_only', ...$ids);
            }
            $sql .= ')';
        }
        $sql .= ' AND (c.starts_at IS NULL OR c.starts_at <= %s) AND (c.ends_at IS NULL OR c.ends_at >= %s)'
            . ' ORDER BY home_priority, c.starts_at IS NULL DESC, c.starts_at DESC, c.id DESC LIMIT %d';
        array_push($values, $now, $now, max(1, min(100, $limit)));
        return array_map([LearningClass::class, 'fromRow'], $this->database->rows($sql, $values));
    }

    public function learningFor(int $userId, array $groupIds): array
    {
        if ($userId < 1) { return []; }
        $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn (int $id): bool => $id > 0)));
        $sql = 'SELECT c.* FROM classes c WHERE c.deleted_at IS NULL AND c.enabled = 1'
            . ' AND (EXISTS (SELECT 1 FROM class_enrollments e WHERE e.class_id = c.id AND e.user_id = %d AND e.status = %s)';
        $values = [$userId, 'active'];
        if ($ids !== []) {
            $sql .= ' OR (c.visibility = %s AND EXISTS (SELECT 1 FROM class_group_links l WHERE l.class_id = c.id AND l.group_id IN ('
                . implode(',', array_fill(0, count($ids), '%d')) . ')))';
            array_push($values, 'group_only', ...$ids);
        }
        $sql .= ') ORDER BY c.id ASC';
        return array_map([LearningClass::class, 'fromRow'], $this->database->rows($sql, $values));
    }

    public function visibleFor(int $userId): array
    {
        $rows = $this->database->rows(
            'SELECT DISTINCT c.* FROM classes c'
            . ' LEFT JOIN class_enrollments e ON e.class_id = c.id'
            . ' AND e.user_id = %d AND e.status = %s'
            . ' LEFT JOIN class_instructors i ON i.class_id = c.id'
            . ' AND i.user_id = %d AND i.status = %s'
            . ' WHERE c.deleted_at IS NULL AND c.enabled = 1'
            . ' AND (c.visibility IN (%s, %s) OR c.owner_user_id = %d'
            . ' OR e.id IS NOT NULL OR i.id IS NOT NULL)'
            . ' ORDER BY c.starts_at IS NULL DESC, c.starts_at DESC, c.id DESC',
            [$userId, 'active', $userId, 'active', 'public', 'password', $userId]
        );

        return array_map([LearningClass::class, 'fromRow'], $rows);
    }

    public function invitedFor(int $userId): array
    {
        $rows = $this->database->rows(
            'SELECT c.* FROM classes c'
                . ' INNER JOIN class_enrollments e ON e.class_id = c.id'
                . ' WHERE e.user_id = %d AND e.status = %s'
                . ' AND c.deleted_at IS NULL AND c.enabled = 1'
                . ' ORDER BY e.invited_at DESC, c.id DESC',
            [$userId, 'invited']
        );

        return array_map([LearningClass::class, 'fromRow'], $rows);
    }

    public function linkedToGroups(array $groupIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $groupIds,
            static fn (mixed $groupId): bool =>
                is_int($groupId) && $groupId > 0
        )));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->database->rows(
            'SELECT DISTINCT c.* FROM classes c'
                . ' INNER JOIN class_group_links l ON l.class_id = c.id'
                . ' WHERE c.deleted_at IS NULL AND c.enabled = 1'
                . ' AND c.visibility = %s'
                . ' AND l.group_id IN (' . $placeholders . ')'
                . ' ORDER BY c.starts_at IS NULL DESC, c.starts_at DESC, c.id DESC',
            array_merge(['group_only'], $ids)
        );

        return array_map([LearningClass::class, 'fromRow'], $rows);
    }

    public function managedBy(int $userId, bool $all): array
    {
        $rows = $all
            ? $this->database->rows(
                'SELECT * FROM classes ORDER BY deleted_at IS NOT NULL, id DESC'
            )
            : $this->database->rows(
                'SELECT DISTINCT c.* FROM classes c'
                . ' LEFT JOIN class_instructors i ON i.class_id = c.id'
                . ' AND i.user_id = %d AND i.status = %s'
                . ' WHERE c.owner_user_id = %d OR i.id IS NOT NULL'
                . ' ORDER BY c.deleted_at IS NOT NULL, c.id DESC',
                [$userId, 'active', $userId]
            );

        return array_map([LearningClass::class, 'fromRow'], $rows);
    }

    public function problems(int $classId): array
    {
        return array_map(
            [ClassProblem::class, 'fromRow'],
            $this->database->rows(
                'SELECT * FROM class_problem_links'
                . ' WHERE class_id = %d ORDER BY sort_order, problem_id',
                [$classId]
            )
        );
    }

    public function participantUserIds(
        int $classId,
        string $status = 'active'
    ): array {
        return array_values(array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            $this->database->rows(
                'SELECT user_id FROM class_enrollments'
                . ' WHERE class_id = %d AND status = %s ORDER BY id',
                [$classId, $status]
            )
        ));
    }

    public function participantApprovedAtMap(int $classId): array
    {
        $map = [];
        foreach (
            $this->database->rows(
                'SELECT user_id, approved_at FROM class_enrollments'
                . ' WHERE class_id = %d AND status = %s AND approved_at IS NOT NULL',
                [$classId, 'active']
            ) as $row
        ) {
            $map[(int) ($row['user_id'] ?? 0)] = (string) ($row['approved_at'] ?? '');
        }

        return $map;
    }

    public function instructorUserIds(int $classId): array
    {
        return array_values(array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            $this->database->rows(
                'SELECT user_id FROM class_instructors'
                . ' WHERE class_id = %d AND status = %s ORDER BY id',
                [$classId, 'active']
            )
        ));
    }

    public function groupIds(int $classId): array
    {
        return array_values(array_map(
            static fn (array $row): int => (int) ($row['group_id'] ?? 0),
            $this->database->rows(
                'SELECT group_id FROM class_group_links'
                . ' WHERE class_id = %d ORDER BY id',
                [$classId]
            )
        ));
    }

    public function isActiveParticipant(int $classId, int $userId): bool
    {
        return $this->database->value(
            'SELECT id FROM class_enrollments'
            . ' WHERE class_id = %d AND user_id = %d AND status = %s LIMIT 1',
            [$classId, $userId, 'active']
        ) !== null;
    }

    public function enrollmentStatus(int $classId, int $userId): ?string
    {
        $value = $this->database->value(
            'SELECT status FROM class_enrollments'
                . ' WHERE class_id = %d AND user_id = %d LIMIT 1',
            [$classId, $userId]
        );

        return $value === null ? null : (string) $value;
    }

    public function isActiveInstructor(int $classId, int $userId): bool
    {
        return $this->database->value(
            'SELECT id FROM class_instructors'
            . ' WHERE class_id = %d AND user_id = %d AND status = %s LIMIT 1',
            [$classId, $userId, 'active']
        ) !== null;
    }

    public function saveAggregate(
        int $actorUserId,
        ClassSaveCommand $command,
        ?string $passwordHash
    ): int {
        $this->database->beginTransaction();
        try {
            $now = $this->now();
            $data = [
                'name' => $command->name(),
                'slug' => $command->slug(),
                'description' => $command->description(),
                'visibility' => $command->visibility(),
                'approval_required' => $command->requiresApproval() ? 1 : 0,
                'progress_visibility' => $command->progressVisibility(),
                'enabled' => $command->isEnabled() ? 1 : 0,
                'starts_at' => $command->startsAt(),
                'ends_at' => $command->endsAt(),
                'blockly_profile_key' => $command->blocklyProfileKey(),
                'updated_at' => $now,
            ];
            if ($passwordHash !== null || $command->classId() < 1) {
                $data['password_hash'] = $passwordHash;
            }
            if ($command->classId() > 0) {
                $classId = $command->classId();
                $this->database->update('classes', $data, ['id' => $classId]);
            } else {
                $data['owner_user_id'] = $actorUserId;
                $data['created_at'] = $now;
                $classId = $this->database->insert('classes', $data);
            }

            $this->replaceInstructors(
                $classId,
                $actorUserId,
                $command->instructorUserIds(),
                $now
            );
            $this->replaceGroups(
                $classId,
                $actorUserId,
                $command->groupIds(),
                $now
            );
            $this->replaceProblems(
                $classId,
                $actorUserId,
                $command->problemIds(),
                $command->problemConfigurations(),
                $now
            );
            $this->database->commit();

            return $classId;
        } catch (Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }

    public function setEnrollmentStatus(
        int $classId,
        int $userId,
        string $status,
        int $actorUserId
    ): void {
        $now = $this->now();
        $existing = $this->database->row(
            'SELECT id FROM class_enrollments'
            . ' WHERE class_id = %d AND user_id = %d LIMIT 1',
            [$classId, $userId]
        );
        $data = $this->enrollmentStatusData($status, $actorUserId, $now);
        if ($existing === null) {
            $data['class_id'] = $classId;
            $data['user_id'] = $userId;
            $this->database->insert('class_enrollments', $data);

            return;
        }
        $this->database->update(
            'class_enrollments',
            $data,
            ['id' => (int) ($existing['id'] ?? 0)]
        );
    }

    public function transitionEnrollmentStatus(
        int $classId,
        int $userId,
        ?string $expectedStatus,
        string $status,
        int $actorUserId
    ): bool {
        $data = $this->enrollmentStatusData(
            $status,
            $actorUserId,
            $this->now()
        );
        if ($expectedStatus === null) {
            $data['class_id'] = $classId;
            $data['user_id'] = $userId;
            $this->database->insert('class_enrollments', $data);

            return true;
        }

        return $this->database->update(
            'class_enrollments',
            $data,
            [
                'class_id' => $classId,
                'user_id' => $userId,
                'status' => $expectedStatus,
            ]
        ) === 1;
    }

    public function setDeleted(
        int $classId,
        bool $deleted,
        int $actorUserId
    ): void {
        $this->database->update(
            'classes',
            [
                'deleted_at' => $deleted ? $this->now() : null,
                'deleted_by_user_id' => $deleted ? $actorUserId : null,
                'updated_at' => $this->now(),
            ],
            ['id' => $classId]
        );
    }

    /**
     * @param list<int> $userIds
     */
    private function replaceInstructors(
        int $classId,
        int $actorUserId,
        array $userIds,
        string $now
    ): void {
        $this->database->delete('class_instructors', ['class_id' => $classId]);
        foreach ($userIds as $userId) {
            if ($userId === $actorUserId) {
                continue;
            }
            $this->database->insert('class_instructors', [
                'class_id' => $classId,
                'user_id' => $userId,
                'role' => 'co_instructor',
                'status' => 'active',
                'granted_by_user_id' => $actorUserId,
                'granted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param list<int> $groupIds
     */
    private function replaceGroups(
        int $classId,
        int $actorUserId,
        array $groupIds,
        string $now
    ): void {
        $this->database->delete('class_group_links', ['class_id' => $classId]);
        foreach ($groupIds as $groupId) {
            $this->database->insert('class_group_links', [
                'class_id' => $classId,
                'group_id' => $groupId,
                'created_by_user_id' => $actorUserId,
                'created_at' => $now,
            ]);
        }
    }

    /**
     * @param list<int> $problemIds
     * @param array<int, ClassProblem> $problemConfigurations
     */
    private function replaceProblems(
        int $classId,
        int $actorUserId,
        array $problemIds,
        array $problemConfigurations,
        string $now
    ): void {
        $current = [];
        foreach ($this->problems($classId) as $problem) {
            $current[$problem->problemId()] = $problem;
        }
        $this->database->delete('class_problem_links', ['class_id' => $classId]);
        foreach ($problemIds as $sortOrder => $problemId) {
            $configuration = $problemConfigurations[$problemId] ?? $current[$problemId] ?? null;
            $this->database->insert('class_problem_links', [
                'class_id' => $classId,
                'problem_id' => $problemId,
                'sort_order' => $sortOrder,
                'is_visible' => $configuration?->isVisible() === false ? 0 : 1,
                'available_from' => $configuration?->availableFrom(),
                'available_until' => $configuration?->availableUntil(),
                'created_by_user_id' => $actorUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    private function enrollmentStatusData(
        string $status,
        int $actorUserId,
        string $now
    ): array {
        $data = ['status' => $status];
        $timestampColumn = match ($status) {
            'pending' => 'requested_at',
            'invited' => 'invited_at',
            'active' => 'approved_at',
            'rejected' => 'rejected_at',
            'left' => 'left_at',
            'removed' => 'removed_at',
            default => null,
        };
        if ($timestampColumn !== null) {
            $data[$timestampColumn] = $now;
        }
        $actorColumn = match ($status) {
            'invited' => 'invited_by_user_id',
            'active' => 'approved_by_user_id',
            'rejected' => 'rejected_by_user_id',
            'removed' => 'removed_by_user_id',
            default => null,
        };
        if ($actorColumn !== null) {
            $data[$actorColumn] = $actorUserId;
        }

        return $data;
    }
}
