<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface AdminActionAuditLog
{
    /**
     * Records an administrative action taken against another account.
     * Only the actor, scope, target user_id, action key, timestamp, and a
     * success/safe-failure result code are recorded — never plaintext
     * secrets (temporary passwords, tokens) or other PII.
     */
    public function record(
        int $actorUserId,
        string $scope,
        int $targetUserId,
        string $action,
        string $result
    ): void;

    /**
     * @return list<array{
     *   actor_user_id: int,
     *   scope: string,
     *   target_user_id: int,
     *   action: string,
     *   result: string,
     *   recorded_at: string
     * }>
     */
    public function recent(int $limit = 100): array;
}
