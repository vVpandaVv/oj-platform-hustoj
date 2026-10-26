<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Application;

use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestIpChangeRequest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestSettings;

interface ContestPlatformRepository
{
    public function settings(int $contestId): ?ContestSettings;

    /** @return array<int, float> */
    public function problemScores(int $contestId): array;

    public function saveSettings(
        int $contestId,
        int $ownerUserId,
        ContestSaveCommand $command
    ): void;

    /** @return list<int> */
    public function managedContestIds(int $userId): array;

    /** @return array<int, string> */
    public function managerRoles(int $contestId): array;

    public function isManager(int $contestId, int $userId): bool;

    /** @return list<int> */
    public function participantUserIds(int $contestId, string $status = 'active'): array;

    public function isActiveParticipant(int $contestId, int $userId): bool;
    public function participantStatus(int $contestId, int $userId): ?string;


    public function setParticipantStatus(
        int $contestId,
        int $userId,
        string $status,
        int $actorUserId
    ): void;

    public function allowedIp(int $contestId, int $userId): ?string;

    public function setAllowedIp(
        int $contestId,
        int $userId,
        ?string $ipAddress
    ): void;

    /**
     * @param array<string, mixed> $metadata
     */
    public function logIp(
        int $contestId,
        int $userId,
        string $ipAddress,
        string $action,
        array $metadata = []
    ): void;

    public function pendingIpChangeRequest(
        int $contestId,
        int $userId
    ): ?ContestIpChangeRequest;

    public function ipChangeRequest(int $requestId): ?ContestIpChangeRequest;

    /** @return list<ContestIpChangeRequest> */
    public function pendingIpChangeRequests(
        int $contestId
    ): array;

    public function createIpChangeRequest(
        int $contestId,
        int $userId,
        string $requestedIp,
        ?string $previousIp,
        string $reason
    ): int;

    public function resolveIpChangeRequest(
        ContestIpChangeRequest $request,
        bool $approved,
        int $actorUserId
    ): void;

    public function storeDeletedSnapshot(
        int $contestId,
        string $title,
        string $snapshot,
        int $actorUserId
    ): void;

    public function removeDeletedSnapshot(int $contestId): void;
}
