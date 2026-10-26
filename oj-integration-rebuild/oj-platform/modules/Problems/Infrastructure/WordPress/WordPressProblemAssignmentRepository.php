<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CollaboratorRole;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemAssignment;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;

final class WordPressProblemAssignmentRepository implements ProblemAssignmentRepository
{
    public function __construct(private readonly ProblemPlatformDatabase $database)
    {
    }

    public function find(ProblemId $problemId): ProblemAssignment
    {
        $ownership = $this->database->row(
            'SELECT `owner_user_id` FROM `problem_ownerships` WHERE `problem_id` = %d',
            [$problemId->value()]
        );
        $rows = $this->database->rows(
            'SELECT `user_id`, `role` FROM `problem_collaborators`'
                . ' WHERE `problem_id` = %d AND `status` = %s',
            [$problemId->value(), 'active']
        );
        $collaborators = [];
        foreach ($rows as $row) {
            $role = CollaboratorRole::tryFrom((string) ($row['role'] ?? ''));
            $userId = (int) ($row['user_id'] ?? 0);
            if ($role !== null && $userId > 0) {
                $collaborators[$userId] = $role;
            }
        }

        return new ProblemAssignment(
            $problemId,
            $ownership === null ? null : (int) ($ownership['owner_user_id'] ?? 0),
            $collaborators
        );
    }

    public function assignOwner(
        ProblemId $problemId,
        int $ownerUserId,
        int $actorUserId
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $this->database->execute(
            'INSERT INTO `problem_ownerships`'
                . ' (`problem_id`, `owner_user_id`, `assigned_by_user_id`,'
                . ' `assigned_at`, `updated_at`) VALUES (%d, %d, %d, %s, %s)'
                . ' ON DUPLICATE KEY UPDATE'
                . ' `owner_user_id` = VALUES(`owner_user_id`),'
                . ' `assigned_by_user_id` = VALUES(`assigned_by_user_id`),'
                . ' `assigned_at` = VALUES(`assigned_at`),'
                . ' `updated_at` = VALUES(`updated_at`)',
            [$problemId->value(), $ownerUserId, $actorUserId, $now, $now]
        );
    }

    public function grant(
        ProblemId $problemId,
        int $userId,
        CollaboratorRole $role,
        int $actorUserId
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $this->database->execute(
            'INSERT INTO `problem_collaborators`'
                . ' (`problem_id`, `user_id`, `role`, `status`,'
                . ' `granted_by_user_id`, `granted_at`, `created_at`, `updated_at`)'
                . ' VALUES (%d, %d, %s, %s, %d, %s, %s, %s)'
                . ' ON DUPLICATE KEY UPDATE'
                . ' `role` = VALUES(`role`), `status` = %s,'
                . ' `granted_by_user_id` = VALUES(`granted_by_user_id`),'
                . ' `granted_at` = VALUES(`granted_at`), `revoked_at` = NULL,'
                . ' `updated_at` = VALUES(`updated_at`)',
            [
                $problemId->value(),
                $userId,
                $role->value,
                'active',
                $actorUserId,
                $now,
                $now,
                $now,
                'active',
            ]
        );
    }

    public function revoke(ProblemId $problemId, int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->database->update(
            'problem_collaborators',
            ['status' => 'revoked', 'revoked_at' => $now, 'updated_at' => $now],
            ['problem_id' => $problemId->value(), 'user_id' => $userId],
            ['%s', '%s', '%s'],
            ['%d', '%d']
        );
    }

    public function problemIdsForUser(int $userId): array
    {
        $rows = $this->database->rows(
            'SELECT `problem_id` FROM `problem_ownerships` WHERE `owner_user_id` = %d'
                . ' UNION SELECT `problem_id` FROM `problem_collaborators`'
                . ' WHERE `user_id` = %d AND `status` = %s'
                . ' ORDER BY `problem_id`',
            [$userId, $userId, 'active']
        );

        return array_values(array_map(
            static fn (array $row): int => (int) ($row['problem_id'] ?? 0),
            $rows
        ));
    }
}
