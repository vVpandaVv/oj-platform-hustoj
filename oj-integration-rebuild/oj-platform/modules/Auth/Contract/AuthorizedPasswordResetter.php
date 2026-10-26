<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Contract;

interface AuthorizedPasswordResetter
{
    /**
     * The caller must authorize the target scope before invoking this method.
     *
     * @param list<int> $targetUserIds
     * @return list<array{
     *   user_id: int,
     *   success: bool,
     *   temporary_password: ?string,
     *   reason: string
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
