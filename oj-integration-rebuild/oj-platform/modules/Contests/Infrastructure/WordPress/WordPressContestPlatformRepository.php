<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Modules\Contests\Application\ContestPlatformRepository;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestSaveCommand;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestIpChangeRequest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestSettings;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use Throwable;

final class WordPressContestPlatformRepository implements ContestPlatformRepository, \CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSettingsBatchRepository, \CodingPanda\OjPlatform\Modules\Contests\Contract\ContestParticipantTransition
{
    public function __construct(private readonly ApplicationDatabase $database)
    {
    }

    public function settings(int $contestId): ?ContestSettings
    {
        $row = $this->database->row(
            'SELECT * FROM contest_settings WHERE contest_id = %d LIMIT 1',
            [$contestId]
        );

        return $row === null ? null : ContestSettings::fromRow($row);
    }

    public function settingsMany(array $ids): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            foreach ($this->database->rows('SELECT * FROM contest_settings WHERE contest_id IN ('
                . implode(',', array_fill(0, count($chunk), '%d')) . ')', $chunk) as $row) {
                $settings = ContestSettings::fromRow($row); $found[(int) $row['contest_id']] = $settings;
            }
        }
        return $found;
    }

    public function problemScores(int $contestId): array
    {
        $scores = [];
        foreach ($this->database->rows(
            'SELECT problem_id, score FROM contest_problem_settings'
                . ' WHERE contest_id = %d ORDER BY problem_id',
            [$contestId]
        ) as $row) {
            $problemId = (int) ($row['problem_id'] ?? 0);
            if ($problemId > 0) {
                $scores[$problemId] = (float) ($row['score'] ?? 100);
            }
        }

        return $scores;
    }

    public function saveSettings(
        int $contestId,
        int $ownerUserId,
        ContestSaveCommand $command
    ): void {
        $this->database->beginTransaction();
        try {
            $now = gmdate('Y-m-d H:i:s');
            $data = [
                'owner_user_id' => $ownerUserId,
                'visibility' => $command->visibility(),
                'group_id' => $command->groupId(),
                'result_visibility' => $command->resultVisibility(),
                'ranking_visibility' => $command->rankingVisibility(),
                'wrong_penalty_points' => $command->wrongPenaltyPoints(),
                'time_score_percent' => $command->timeScorePercent(),
                'partial_score_policy' => $command->partialScorePolicy(),
                'scoring_policy' => $command->scoringPolicy(),
                'ranking_policy' => $command->rankingPolicy(),
                'freeze_minutes' => $command->freezeMinutes(),
                'blockly_profile_key' => $command->blocklyProfileKey(),
                'allow_ai_feedback' => $command->allowAiFeedback() ? 1 : 0,
                'approval_required' => $command->requiresApproval() ? 1 : 0,
                'wrong_answer_policy' => $command->wrongAnswerPolicy(),
                'ip_policy' => $command->ipPolicy(),
                'updated_at' => $now,
            ];
            if ($this->settings($contestId) === null) {
                $data['contest_id'] = $contestId;
                $data['created_at'] = $now;
                $this->database->insert('contest_settings', $data);
            } else {
                $this->database->update(
                    'contest_settings',
                    $data,
                    ['contest_id' => $contestId]
                );
            }
            $this->database->delete(
                'contest_managers',
                ['contest_id' => $contestId]
            );
            foreach ($command->managerRoles() as $userId => $role) {
                $this->database->insert('contest_managers', [
                    'contest_id' => $contestId,
                    'user_id' => $userId,
                    'role' => $role,
                    'status' => 'active',
                    'granted_by_user_id' => $ownerUserId,
                    'granted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->database->delete(
                'contest_problem_settings',
                ['contest_id' => $contestId]
            );
            foreach ($command->problemIds() as $problemId) {
                $this->database->insert('contest_problem_settings', [
                    'contest_id' => $contestId,
                    'problem_id' => $problemId,
                    'score' => (float) (
                        $command->problemScores()[$problemId] ?? 100
                    ),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->database->commit();
        } catch (Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }

    public function managedContestIds(int $userId): array
    {
        return array_values(array_map(
            static fn (array $row): int => (int) ($row['contest_id'] ?? 0),
            $this->database->rows(
                'SELECT contest_id FROM contest_settings WHERE owner_user_id = %d'
                . ' UNION SELECT contest_id FROM contest_managers'
                . ' WHERE user_id = %d AND status = %s',
                [$userId, $userId, 'active']
            )
        ));
    }

    public function managerRoles(int $contestId): array
    {
        $roles = [];
        foreach ($this->database->rows(
            'SELECT user_id, role FROM contest_managers'
                . ' WHERE contest_id = %d AND status = %s ORDER BY id',
            [$contestId, 'active']
        ) as $row) {
            $roles[(int) ($row['user_id'] ?? 0)] = (string) ($row['role'] ?? 'manager');
        }

        return $roles;
    }
    public function isManager(int $contestId, int $userId): bool
    {
        return $this->database->value(
            'SELECT id FROM contest_managers WHERE contest_id = %d'
                . ' AND user_id = %d AND status = %s LIMIT 1',
            [$contestId, $userId, 'active']
        ) !== null;
    }

    public function participantUserIds(
        int $contestId,
        string $status = 'active'
    ): array {
        return array_values(array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            $this->database->rows(
                'SELECT user_id FROM contest_participants'
                . ' WHERE contest_id = %d AND status = %s ORDER BY id',
                [$contestId, $status]
            )
        ));
    }

    public function isActiveParticipant(int $contestId, int $userId): bool
    {
        return $this->database->value(
            'SELECT id FROM contest_participants WHERE contest_id = %d'
                . ' AND user_id = %d AND status = %s LIMIT 1',
            [$contestId, $userId, 'active']
        ) !== null;
    }

    public function participantStatus(int $contestId, int $userId): ?string
    {
        $value = $this->database->value(
            'SELECT status FROM contest_participants'
                . ' WHERE contest_id = %d AND user_id = %d LIMIT 1',
            [$contestId, $userId]
        );

        return $value === null ? null : (string) $value;
    }

    public function transitionParticipantStatus(int $contestId, int $userId, ?string $expectedStatus, string $status, int $actorUserId): bool
    {
        $this->database->beginTransaction();
        try {
            $row = $this->database->row('SELECT status FROM contest_participants WHERE contest_id = %d AND user_id = %d FOR UPDATE', [$contestId, $userId]);
            $currentStatus = $row === null ? null : (string) $row['status'];
            if ($currentStatus !== $expectedStatus) {
                $this->database->rollBack();
                return false;
            }
            $this->setParticipantStatus($contestId, $userId, $status, $actorUserId);
            $this->database->commit();
            return true;
        } catch (\Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }

    public function setParticipantStatus(
        int $contestId,
        int $userId,
        string $status,
        int $actorUserId
    ): void {
        $existing = $this->database->row(
            'SELECT id FROM contest_participants'
                . ' WHERE contest_id = %d AND user_id = %d LIMIT 1',
            [$contestId, $userId]
        );
        $now = gmdate('Y-m-d H:i:s');
        $data = ['status' => $status];
        $timeColumn = match ($status) {
            'pending' => 'requested_at',
            'active' => 'approved_at',
            'withdrawn' => 'withdrawn_at',
            'removed' => 'removed_at',
            default => null,
        };
        if ($timeColumn !== null) {
            $data[$timeColumn] = $now;
        }
        if ($status === 'active') {
            $data['approved_by_user_id'] = $actorUserId;
        }
        if ($status === 'removed') {
            $data['removed_by_user_id'] = $actorUserId;
        }
        if ($existing === null) {
            $data['contest_id'] = $contestId;
            $data['user_id'] = $userId;
            $this->database->insert('contest_participants', $data);

            return;
        }
        $this->database->update(
            'contest_participants',
            $data,
            ['id' => (int) ($existing['id'] ?? 0)]
        );
    }

    public function allowedIp(int $contestId, int $userId): ?string
    {
        $value = $this->database->value(
            'SELECT allowed_ip FROM contest_participants'
                . ' WHERE contest_id = %d AND user_id = %d LIMIT 1',
            [$contestId, $userId]
        );

        return $value === null || trim((string) $value) === ''
            ? null
            : (string) $value;
    }

    public function setAllowedIp(
        int $contestId,
        int $userId,
        ?string $ipAddress
    ): void {
        $this->database->update(
            'contest_participants',
            ['allowed_ip' => $ipAddress],
            ['contest_id' => $contestId, 'user_id' => $userId]
        );
    }

    public function logIp(
        int $contestId,
        int $userId,
        string $ipAddress,
        string $action,
        array $metadata = []
    ): void {
        $this->database->insert('contest_ip_logs', [
            'contest_id' => $contestId,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'action' => $action,
            'metadata' => json_encode(
                $metadata,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: '{}',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function pendingIpChangeRequest(
        int $contestId,
        int $userId
    ): ?ContestIpChangeRequest {
        return $this->hydrateIpChangeRequest($this->database->row(
            'SELECT id, contest_id, user_id, ip_address, metadata, created_at'
                . ' FROM contest_ip_logs WHERE contest_id = %d AND user_id = %d'
                . " AND action = 'change_requested' AND resolution IS NULL"
                . ' ORDER BY id DESC LIMIT 1',
            [$contestId, $userId]
        ));
    }

    public function ipChangeRequest(int $requestId): ?ContestIpChangeRequest
    {
        return $this->hydrateIpChangeRequest($this->database->row(
            'SELECT id, contest_id, user_id, ip_address, metadata, created_at'
                . ' FROM contest_ip_logs WHERE id = %d'
                . " AND action = 'change_requested' AND resolution IS NULL"
                . ' LIMIT 1',
            [$requestId]
        ));
    }

    public function pendingIpChangeRequests(int $contestId): array
    {
        $requests = [];
        foreach ($this->database->rows(
            'SELECT id, contest_id, user_id, ip_address, metadata, created_at'
                . ' FROM contest_ip_logs WHERE contest_id = %d'
                . " AND action = 'change_requested' AND resolution IS NULL"
                . ' ORDER BY created_at, id',
            [$contestId]
        ) as $row) {
            $request = $this->hydrateIpChangeRequest($row);
            if ($request !== null) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    public function createIpChangeRequest(
        int $contestId,
        int $userId,
        string $requestedIp,
        ?string $previousIp,
        string $reason
    ): int {
        return $this->database->insert('contest_ip_logs', [
            'contest_id' => $contestId,
            'user_id' => $userId,
            'ip_address' => $requestedIp,
            'action' => 'change_requested',
            'metadata' => json_encode([
                'previous_ip' => $previousIp,
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'resolution' => null,
            'resolved_at' => null,
            'resolved_by_user_id' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function resolveIpChangeRequest(
        ContestIpChangeRequest $request,
        bool $approved,
        int $actorUserId
    ): void {
        $resolution = $approved ? 'approved' : 'rejected';
        $now = gmdate('Y-m-d H:i:s');
        $this->database->beginTransaction();
        try {
            $updated = $this->database->update(
                'contest_ip_logs',
                [
                    'resolution' => $resolution,
                    'resolved_at' => $now,
                    'resolved_by_user_id' => $actorUserId,
                ],
                ['id' => $request->id()]
            );
            if ($updated !== 1) {
                throw new \RuntimeException('IP 변경 요청 상태를 갱신하지 못했습니다.');
            }
            if ($approved) {
                $this->database->update(
                    'contest_participants',
                    ['allowed_ip' => $request->requestedIp()],
                    [
                        'contest_id' => $request->contestId(),
                        'user_id' => $request->userId(),
                    ]
                );
            }
            $this->database->insert('contest_ip_logs', [
                'contest_id' => $request->contestId(),
                'user_id' => $request->userId(),
                'ip_address' => $request->requestedIp(),
                'action' => $approved ? 'change_approved' : 'change_rejected',
                'metadata' => json_encode([
                    'request_id' => $request->id(),
                    'actor_user_id' => $actorUserId,
                    'previous_ip' => $request->previousIp(),
                    'reason' => $request->reason(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'created_at' => $now,
            ]);
            $this->database->commit();
        } catch (Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function hydrateIpChangeRequest(
        ?array $row
    ): ?ContestIpChangeRequest {
        if ($row === null) {
            return null;
        }
        $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $previousIp = $metadata['previous_ip'] ?? null;

        return new ContestIpChangeRequest(
            (int) ($row['id'] ?? 0),
            (int) ($row['contest_id'] ?? 0),
            (int) ($row['user_id'] ?? 0),
            (string) ($row['ip_address'] ?? ''),
            is_string($previousIp) && $previousIp !== '' ? $previousIp : null,
            is_string($metadata['reason'] ?? null)
                ? $metadata['reason']
                : '',
            (string) ($row['created_at'] ?? '')
        );
    }

    public function storeDeletedSnapshot(
        int $contestId,
        string $title,
        string $snapshot,
        int $actorUserId
    ): void {
        $data = [
            'title' => $title,
            'snapshot' => $snapshot,
            'deleted_by_user_id' => $actorUserId,
            'deleted_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($this->database->value(
            'SELECT contest_id FROM deleted_contests'
                . ' WHERE contest_id = %d LIMIT 1',
            [$contestId]
        ) === null) {
            $data['contest_id'] = $contestId;
            $this->database->insert('deleted_contests', $data);
        } else {
            $this->database->update(
                'deleted_contests',
                $data,
                ['contest_id' => $contestId]
            );
        }
    }

    public function removeDeletedSnapshot(int $contestId): void
    {
        $this->database->delete(
            'deleted_contests',
            ['contest_id' => $contestId]
        );
    }
}
