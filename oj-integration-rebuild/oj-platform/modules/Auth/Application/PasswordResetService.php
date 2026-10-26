<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AdminActionAuditLog;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthorizedPasswordResetter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\PasswordResetTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use InvalidArgumentException;
use Throwable;

final class PasswordResetService implements AuthorizedPasswordResetter
{
    public function __construct(
        private readonly WordPressUserGateway $users,
        private readonly UserProfileRepository $profiles,
        private readonly PasswordResetTargetPolicy $targetPolicy,
        private readonly AdminActionAuditLog $audit
    ) {
    }

    /**
     * @param list<int> $targetUserIds
     * @return list<PasswordResetItemResult>
     */
    public function reset(
        int $actorUserId,
        array $targetUserIds,
        ?string $customPassword = null,
        int $passwordLength = 18,
        bool $confirmWeakPassword = false
    ): array {
        $targetUserIds = array_values(array_unique(array_filter(
            array_map('intval', $targetUserIds),
            static fn (int $userId): bool => $userId > 0
        )));
        if ($targetUserIds === [] || count($targetUserIds) > 100) {
            throw new InvalidArgumentException('비밀번호 초기화 대상은 1~100명이어야 합니다.');
        }
        if ($customPassword !== null && ($customPassword === '' || strlen($customPassword) > 100)) {
            throw new InvalidArgumentException('직접 입력한 비밀번호는 1~100자여야 합니다.');
        }
        if (
            $customPassword !== null
            && ! $confirmWeakPassword
            && $this->isWeakPassword($customPassword)
        ) {
            throw new InvalidArgumentException(
                '약한 비밀번호입니다. 그대로 사용하려면 확인 체크박스를 선택해 주세요.'
            );
        }
        $passwordLength = $passwordLength > 0 ? max(6, min(64, $passwordLength)) : 18;

        $results = [];
        foreach ($targetUserIds as $targetUserId) {
            if ($targetUserId === $actorUserId) {
                $results[] = new PasswordResetItemResult(
                    $targetUserId,
                    false,
                    null,
                    'self_reset_forbidden'
                );
                $this->recordAudit($actorUserId, 'global', $targetUserId, 'self_reset_forbidden');
                continue;
            }

            $identity = $this->users->findById($targetUserId);
            $profile = $this->profiles->find($targetUserId);
            if (
                $identity === null
                || $profile === null
                || $profile->accessStatus() !== AccessStatus::Active
                || ! $this->targetPolicy->allows($actorUserId, $identity, $profile)
            ) {
                $results[] = new PasswordResetItemResult(
                    $targetUserId,
                    false,
                    null,
                    'target_not_allowed'
                );
                $this->recordAudit($actorUserId, 'global', $targetUserId, 'target_not_allowed');
                continue;
            }

            try {
                $temporaryPassword = $customPassword ?? $this->temporaryPassword($passwordLength);
                $this->users->setPassword($targetUserId, $temporaryPassword);
                $this->users->invalidateSessions($targetUserId);
                $this->profiles->save($profile->withPasswordChangeRequired(true));
                $results[] = new PasswordResetItemResult(
                    $targetUserId,
                    true,
                    $temporaryPassword,
                    'password_reset'
                );
                $this->recordAudit($actorUserId, 'global', $targetUserId, 'password_reset');
            } catch (Throwable) {
                $results[] = new PasswordResetItemResult(
                    $targetUserId,
                    false,
                    null,
                    'reset_failed'
                );
                $this->recordAudit($actorUserId, 'global', $targetUserId, 'reset_failed');
            }
        }

        return $results;
    }

    public function resetAuthorized(
        int $actorUserId,
        array $targetUserIds,
        string $scope = 'authorized',
        ?string $customPassword = null,
        bool $confirmWeakPassword = false
    ): array {
        $targetUserIds = $this->normalizedTargetIds($targetUserIds);
        if ($customPassword !== null && ($customPassword === '' || strlen($customPassword) > 100)) {
            throw new InvalidArgumentException('직접 입력한 비밀번호는 1~100자여야 합니다.');
        }
        if (
            $customPassword !== null
            && ! $confirmWeakPassword
            && $this->isWeakPassword($customPassword)
        ) {
            throw new InvalidArgumentException(
                '약한 비밀번호입니다. 그대로 사용하려면 확인 체크박스를 선택해 주세요.'
            );
        }
        $results = [];
        foreach ($targetUserIds as $targetUserId) {
            $identity = $this->users->findById($targetUserId);
            $profile = $this->profiles->find($targetUserId);
            if (
                $targetUserId === $actorUserId
                || $identity === null
                || $profile === null
                || $profile->accessStatus() !== AccessStatus::Active
                || $this->targetPolicy->protectsTarget($identity)
            ) {
                $results[] = [
                    'user_id' => $targetUserId,
                    'success' => false,
                    'temporary_password' => null,
                    'reason' => 'target_not_allowed',
                ];
                $this->recordAudit($actorUserId, $scope, $targetUserId, 'target_not_allowed');
                continue;
            }

            try {
                $temporaryPassword = $customPassword ?? $this->temporaryPassword();
                $this->users->setPassword($targetUserId, $temporaryPassword);
                $this->users->invalidateSessions($targetUserId);
                $this->profiles->save(
                    $profile->withPasswordChangeRequired(true)
                );
                $results[] = [
                    'user_id' => $targetUserId,
                    'success' => true,
                    'temporary_password' => $temporaryPassword,
                    'reason' => 'password_reset',
                ];
                $this->recordAudit($actorUserId, $scope, $targetUserId, 'password_reset');
            } catch (Throwable) {
                $results[] = [
                    'user_id' => $targetUserId,
                    'success' => false,
                    'temporary_password' => null,
                    'reason' => 'reset_failed',
                ];
                $this->recordAudit($actorUserId, $scope, $targetUserId, 'reset_failed');
            }
        }

        return $results;
    }

    private function recordAudit(
        int $actorUserId,
        string $scope,
        int $targetUserId,
        string $result
    ): void {
        try {
            $this->audit->record(
                $actorUserId,
                $scope,
                $targetUserId,
                'password_reset',
                $result
            );
        } catch (Throwable) {
            // Audit logging must never block or fail the underlying action.
        }
    }

    /**
     * @param list<int> $targetUserIds
     * @return list<int>
     */
    private function normalizedTargetIds(array $targetUserIds): array
    {
        $targetUserIds = array_values(array_unique(array_filter(
            array_map('intval', $targetUserIds),
            static fn (int $userId): bool => $userId > 0
        )));
        if ($targetUserIds === [] || count($targetUserIds) > 100) {
            throw new InvalidArgumentException(
                '비밀번호 초기화 대상은 1~100명이어야 합니다.'
            );
        }

        return $targetUserIds;
    }

    private function isWeakPassword(string $password): bool
    {
        $categories = 0;
        foreach (['/[A-Z]/', '/[a-z]/', '/[0-9]/', '/[^A-Za-z0-9]/'] as $pattern) {
            if (preg_match($pattern, $password) === 1) {
                ++$categories;
            }
        }

        return strlen($password) < 8 || $categories < 2;
    }

    private function temporaryPassword(int $length = 18): string
    {
        $groups = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!@#$%*+-_',
        ];
        $length = max($length, count($groups));
        $characters = [];
        foreach ($groups as $group) {
            $characters[] = $group[random_int(0, strlen($group) - 1)];
        }

        $pool = implode('', $groups);
        while (count($characters) < $length) {
            $characters[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; --$index) {
            $swap = random_int(0, $index);
            [$characters[$index], $characters[$swap]] = [$characters[$swap], $characters[$index]];
        }

        return implode('', $characters);
    }
}
