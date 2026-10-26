<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use DomainException;

final class ActionGuard implements ActionAccessGuard
{
    public function __construct(
        private readonly ActionSecurity $security,
        private readonly CurrentAccountProvider $accounts,
        private readonly Authorization $permissions,
        private readonly AttemptLimiter $attempts
    ) {
    }

    public function verifyNonce(string $actionKey, HttpRequest $request): void
    {
        if (! $this->security->verify($actionKey, $request)) {
            throw new DomainException('요청 확인 정보가 만료되었습니다.');
        }
    }

    public function actor(
        string $actionKey,
        HttpRequest $request,
        string $capability
    ): UserIdentity {
        $this->verifyNonce($actionKey, $request);
        $account = $this->accounts->current();
        $identity = $account->identity();
        if (
            $identity === null
            || ! $account->isActive()
            || ! $this->permissions->can($identity->userId(), $capability)
        ) {
            throw new DomainException('이 작업을 수행할 권한이 없습니다.');
        }

        return $identity;
    }

    public function authenticatedActor(
        string $actionKey,
        HttpRequest $request
    ): UserIdentity {
        $this->verifyNonce($actionKey, $request);
        $account = $this->accounts->current();
        $identity = $account->identity();
        if ($identity === null || ! $account->isActive()) {
            throw new DomainException('이용 가능한 로그인 계정만 이 작업을 수행할 수 있습니다.');
        }

        return $identity;
    }

    public function throttle(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds
    ): void {
        if (! $this->attempts->consume($scope, $identifier, $limit, $windowSeconds)) {
            throw new DomainException('요청이 너무 많습니다. 잠시 뒤 다시 시도해 주세요.');
        }
    }
}
