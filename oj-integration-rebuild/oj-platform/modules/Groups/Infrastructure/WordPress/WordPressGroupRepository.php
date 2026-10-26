<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Groups\Application\GroupRepository;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupSaveCommand;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;

final class WordPressGroupRepository implements GroupRepository, \CodingPanda\OjPlatform\Modules\Groups\Contract\GroupHomeRepository, \CodingPanda\OjPlatform\Modules\Groups\Contract\GroupLearningRepository
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function find(int $groupId): ?LearningGroup
    {
        $row = $this->database->row(
            'SELECT * FROM learning_groups WHERE id = %d LIMIT 1',
            [$groupId]
        );

        return $row === null ? null : LearningGroup::fromRow($row);
    }

    public function homeFor(int $userId, bool $managed, bool $all, int $limit): array
    {
        $sql = 'SELECT DISTINCT g.* FROM learning_groups g'
            . ' LEFT JOIN group_memberships m ON m.group_id = g.id AND m.user_id = %d AND m.status = %s'
            . ' LEFT JOIN group_managers gm ON gm.group_id = g.id AND gm.user_id = %d AND gm.revoked_at IS NULL'
            . ' WHERE g.deleted_at IS NULL AND g.enabled = 1';
        $values = [$userId, 'active', $userId];
        if ($managed) {
            if (!$all) { $sql .= ' AND (g.owner_user_id = %d OR gm.id IS NOT NULL)'; $values[] = $userId; }
        } else {
            $sql .= ' AND (g.visibility IN (%s, %s) OR g.owner_user_id = %d OR m.id IS NOT NULL OR gm.id IS NOT NULL)';
            array_push($values, 'public', 'code', $userId);
        }
        $sql .= ' ORDER BY g.id DESC LIMIT %d'; $values[] = max(1, min(100, $limit));
        return array_map([LearningGroup::class, 'fromRow'], $this->database->rows($sql, $values));
    }

    public function visibleFor(int $userId): array
    {
        $rows = $this->database->rows(
            'SELECT DISTINCT g.* FROM learning_groups g'
            . ' LEFT JOIN group_memberships m ON m.group_id = g.id'
            . ' AND m.user_id = %d AND m.status = %s'
            . ' LEFT JOIN group_managers gm ON gm.group_id = g.id'
            . ' AND gm.user_id = %d AND gm.revoked_at IS NULL'
            . ' WHERE g.deleted_at IS NULL AND g.enabled = 1'
            . ' AND (g.visibility IN (%s, %s) OR g.owner_user_id = %d'
            . ' OR m.id IS NOT NULL OR gm.id IS NOT NULL)'
            . ' ORDER BY g.id DESC',
            [$userId, 'active', $userId, 'public', 'code', $userId]
        );

        return array_map([LearningGroup::class, 'fromRow'], $rows);
    }

    public function learningFor(int $userId): array
    {
        if ($userId < 1) { return []; }
        return array_map([LearningGroup::class, 'fromRow'], $this->database->rows(
            'SELECT g.* FROM learning_groups g WHERE g.deleted_at IS NULL AND g.enabled = 1'
            . ' AND EXISTS (SELECT 1 FROM group_memberships m WHERE m.group_id = g.id AND m.user_id = %d AND m.status = %s)'
            . ' ORDER BY g.id ASC', [$userId, 'active']
        ));
    }

    public function managedBy(int $userId, bool $all): array
    {
        $rows = $all
            ? $this->database->rows(
                'SELECT * FROM learning_groups'
                . ' ORDER BY deleted_at IS NOT NULL, id DESC'
            )
            : $this->database->rows(
                'SELECT DISTINCT g.* FROM learning_groups g'
                . ' LEFT JOIN group_managers gm ON gm.group_id = g.id'
                . ' AND gm.user_id = %d AND gm.revoked_at IS NULL'
                . ' WHERE g.owner_user_id = %d OR gm.id IS NOT NULL'
                . ' ORDER BY g.deleted_at IS NOT NULL, g.id DESC',
                [$userId, $userId]
            );

        return array_map([LearningGroup::class, 'fromRow'], $rows);
    }

    public function memberUserIds(
        int $groupId,
        string $status = 'active'
    ): array {
        return array_values(array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            $this->database->rows(
                'SELECT user_id FROM group_memberships'
                . ' WHERE group_id = %d AND status = %s ORDER BY id',
                [$groupId, $status]
            )
        ));
    }

    public function managerUserIds(int $groupId): array
    {
        return array_values(array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            $this->database->rows(
                'SELECT user_id FROM group_managers'
                . ' WHERE group_id = %d AND revoked_at IS NULL ORDER BY id',
                [$groupId]
            )
        ));
    }

    public function activeGroupIdsForUser(int $userId): array
    {
        return $this->ids(
            $this->database->rows(
                'SELECT group_id FROM group_memberships'
                . ' WHERE user_id = %d AND status = %s',
                [$userId, 'active']
            ),
            'group_id'
        );
    }

    public function managedGroupIdsForUser(int $userId): array
    {
        return $this->ids(
            $this->database->rows(
                'SELECT g.id FROM learning_groups g'
                . ' LEFT JOIN group_managers gm ON gm.group_id = g.id'
                . ' AND gm.user_id = %d AND gm.revoked_at IS NULL'
                . ' WHERE g.owner_user_id = %d OR gm.id IS NOT NULL',
                [$userId, $userId]
            ),
            'id'
        );
    }

    public function isActiveMember(int $groupId, int $userId): bool
    {
        return $this->database->value(
            'SELECT id FROM group_memberships'
            . ' WHERE group_id = %d AND user_id = %d AND status = %s LIMIT 1',
            [$groupId, $userId, 'active']
        ) !== null;
    }

    public function membershipStatus(int $groupId, int $userId): ?string
    {
        $value = $this->database->value(
            'SELECT status FROM group_memberships'
            . ' WHERE group_id = %d AND user_id = %d LIMIT 1',
            [$groupId, $userId]
        );

        return $value === null ? null : (string) $value;
    }
    public function isActiveManager(int $groupId, int $userId): bool
    {
        return $this->database->value(
            'SELECT id FROM group_managers'
            . ' WHERE group_id = %d AND user_id = %d'
            . ' AND revoked_at IS NULL LIMIT 1',
            [$groupId, $userId]
        ) !== null;
    }

    public function hasActivePerformanceGroup(int $userId): bool
    {
        return $this->activePerformanceGroupIdsForUser($userId) !== [];
    }

    public function activePerformanceGroupIdsForUser(int $userId): array
    {
        return $this->ids(
            $this->database->rows(
                'SELECT g.id FROM learning_groups g'
            . ' INNER JOIN group_memberships m ON m.group_id = g.id'
            . ' WHERE m.user_id = %d AND m.status = %s'
            . ' AND g.evaluation_mode = %s AND g.enabled = 1'
                . ' AND g.deleted_at IS NULL ORDER BY g.id',
                [$userId, 'active', 'performance']
            ),
            'id'
        );
    }

    public function saveAggregate(
        int $actorUserId,
        GroupSaveCommand $command,
        ?string $joinCodeHash
    ): int {
        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'name' => $command->name(),
            'slug' => $command->slug(),
            'description' => $command->description(),
            'visibility' => $command->visibility(),
            'approval_mode' => $command->approvalMode(),
            'enabled' => $command->isEnabled() ? 1 : 0,
            'blockly_profile_key' => $command->blocklyProfileKey(),
            'evaluation_mode' => $command->evaluationMode(),
            'evaluation_mode_changed_by_user_id' => $actorUserId,
            'evaluation_mode_changed_at' => $now,
            'updated_at' => $now,
        ];
        if ($joinCodeHash !== null || $command->groupId() < 1) {
            $data['join_code_hash'] = $joinCodeHash;
        }
        if ($command->groupId() > 0) {
            $this->database->update(
                'learning_groups',
                $data,
                ['id' => $command->groupId()]
            );

            return $command->groupId();
        }
        $data['owner_user_id'] = $actorUserId;
        $data['created_at'] = $now;

        return $this->database->insert('learning_groups', $data);
    }

    public function setMembershipStatus(
        int $groupId,
        int $userId,
        string $status,
        int $actorUserId
    ): void {
        $existing = $this->database->row(
            'SELECT id FROM group_memberships'
            . ' WHERE group_id = %d AND user_id = %d LIMIT 1',
            [$groupId, $userId]
        );
        $now = gmdate('Y-m-d H:i:s');
        $data = ['status' => $status];
        $timeColumn = match ($status) {
            'pending' => 'requested_at',
            'active' => 'approved_at',
            'rejected' => 'rejected_at',
            'left' => 'left_at',
            'removed' => 'removed_at',
            default => null,
        };
        $actorColumn = match ($status) {
            'active' => 'approved_by_user_id',
            'rejected' => 'rejected_by_user_id',
            'removed' => 'removed_by_user_id',
            default => null,
        };
        if ($timeColumn !== null) {
            $data[$timeColumn] = $now;
        }
        if ($actorColumn !== null) {
            $data[$actorColumn] = $actorUserId;
        }
        if ($existing === null) {
            $data['group_id'] = $groupId;
            $data['user_id'] = $userId;
            $this->database->insert('group_memberships', $data);

            return;
        }
        $this->database->update(
            'group_memberships',
            $data,
            ['id' => (int) ($existing['id'] ?? 0)]
        );
    }

    public function setManager(
        int $groupId,
        int $userId,
        bool $enabled,
        int $actorUserId
    ): void {
        $existing = $this->database->row(
            'SELECT id FROM group_managers'
            . ' WHERE group_id = %d AND user_id = %d LIMIT 1',
            [$groupId, $userId]
        );
        $data = [
            'granted_by_user_id' => $actorUserId,
            'granted_at' => gmdate('Y-m-d H:i:s'),
            'revoked_at' => $enabled ? null : gmdate('Y-m-d H:i:s'),
        ];
        if ($existing === null) {
            $data['group_id'] = $groupId;
            $data['user_id'] = $userId;
            $this->database->insert('group_managers', $data);

            return;
        }
        $this->database->update(
            'group_managers',
            $data,
            ['id' => (int) ($existing['id'] ?? 0)]
        );
    }

    public function setDeleted(
        int $groupId,
        bool $deleted,
        int $actorUserId
    ): void {
        $this->database->update(
            'learning_groups',
            [
                'deleted_at' => $deleted ? gmdate('Y-m-d H:i:s') : null,
                'deleted_by_user_id' => $deleted ? $actorUserId : null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $groupId]
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows, string $column): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row[$column] ?? 0),
            $rows
        ))));
    }
}
