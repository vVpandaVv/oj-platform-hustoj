<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use DomainException;

final class AccountErasureService
{
    public function __construct(
        private readonly AccountErasureParticipantRegistry $participants,
        private readonly WordPressUserGateway $users,
        private readonly JobDispatcher $jobs
    ) {
    }

    public function impact(int $userId): AccountErasureImpact
    {
        return new AccountErasureImpact($this->participants->blockers($userId));
    }

    public function request(
        int $userId,
        string $currentPassword,
        bool $irreversibleConfirmed,
        string $confirmationText
    ): JobEnqueueResult {
        if (! $irreversibleConfirmed || trim($confirmationText) !== '계정 삭제') {
            throw new DomainException('되돌릴 수 없는 계정 정리 절차를 확인해 주세요.');
        }

        $identity = $this->users->findById($userId);
        $verified = $identity === null
            ? null
            : $this->users->verifyCredentials(
                $identity->userLogin(),
                $currentPassword
            );
        if ($verified === null || $verified->userId() !== $userId) {
            throw new DomainException('현재 비밀번호가 올바르지 않습니다.');
        }

        $impact = $this->impact($userId);
        if (! $impact->canRequest()) {
            $first = $impact->blockers()[0];
            throw new DomainException(sprintf(
                '%s %d건을 먼저 정리해 주세요.',
                $first->label(),
                $first->count()
            ));
        }
        $this->participants->assertComplete();

        return $this->jobs->enqueue(
            'user_account_erasure',
            'apply',
            $userId,
            [new JobTarget('user', (string) $userId, ['user_id' => $userId])],
            ['request_type' => 'self_account_erasure', 'confirmed' => true],
            ['user_id' => $userId, 'target_count' => 1],
            'auth.user_account_erasure'
        );
    }
}
