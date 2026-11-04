<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface AuthorizedPasswordResetter
{
    /**
     * The caller must authorize the target scope before invoking this method.
     * Failure reasons distinguish self_reset_forbidden, account_not_found,
     * profile_missing, account_pending, account_suspended, target_protected,
     * and reset_failed. An optional diagnostic captures the protection decision
     * for authorized management views. Existing keys and authorization are unchanged.
     *
     * @param list<int> $targetUserIds
     * @return list<array{
     *   user_id: int,
     *   success: bool,
     *   temporary_password: ?string,
     *   reason: string,
     *   diagnostic?: AccountProtectionDiagnostic
     * }>
     */
    public function resetAuthorized(
        int $actorUserId,
        array $targetUserIds,
        string $scope = 'authorized',
        ?string $customPassword = null,
        bool $confirmWeakPassword = false
    ): array;
}
