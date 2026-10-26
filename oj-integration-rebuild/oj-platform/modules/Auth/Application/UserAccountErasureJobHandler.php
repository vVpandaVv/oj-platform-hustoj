<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthAccountErasureStore;
use CodingPanda\OjPlatform\Modules\Auth\Contract\DeletedUserRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentityAnonymizer;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use DomainException;
use RuntimeException;
use Throwable;

final class UserAccountErasureJobHandler implements JobHandler
{
    public function __construct(
        private readonly AccountErasureParticipantRegistry $participants,
        private readonly WordPressUserGateway $users,
        private readonly AuthAccountErasureStore $authData,
        private readonly UserIdentityAnonymizer $identities,
        private readonly DeletedUserRepository $deletedUsers,
        private readonly JobStepJournal $steps,
        private readonly ErrorReporter $errors
    ) {
    }

    public function jobType(): string
    {
        return 'user_account_erasure';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        $claim = $item->claim();
        $locked = false;

        try {
            $userId = $this->userId($item);
            if ($this->deletedUsers->hasCompleted($userId)) {
                return JobHandlerResult::succeeded(
                    'auth.erasure_already_completed',
                    '계정 익명화가 이미 완료되었습니다.',
                    ['user_id' => $userId, 'job_id' => $claim->jobId()]
                );
            }

            $this->participants->assertComplete();
            $blockers = $this->participants->blockers($userId);
            if ($blockers !== []) {
                return JobHandlerResult::needsAttention(
                    'auth.erasure_ownership_changed',
                    '소유 관계가 변경되어 계정 익명화를 시작할 수 없습니다.',
                    ['user_id' => $userId, 'blocker_count' => count($blockers)]
                );
            }

            $identity = $this->users->findById($userId);
            if ($identity === null) {
                throw new DomainException('익명화할 WordPress 사용자를 찾을 수 없습니다.');
            }
            $currentLogin = $identity->userLogin();
            $anonymousLogin = $this->identities->anonymousLogin($userId);

            $this->step($claim, 'lock_account', 1, function () use ($userId, &$locked): array {
                $this->authData->lock($userId);
                $locked = true;
                $this->users->invalidateSessions($userId);

                return ['locked' => true];
            });

            $order = 10;
            foreach ($this->participants->all() as $participant) {
                $moduleKey = $participant->moduleKey();
                $this->step(
                    $claim,
                    'module_' . $moduleKey,
                    $order++,
                    function () use (
                        $participant,
                        $userId,
                        $currentLogin,
                        $anonymousLogin,
                        $moduleKey
                    ): array {
                        $participant->anonymize($userId, $currentLogin, $anonymousLogin);
                        if (! $participant->verify($userId, $anonymousLogin)) {
                            throw new RuntimeException('모듈 계정 정리 검증에 실패했습니다.');
                        }

                        return ['module_key' => $moduleKey, 'verified' => true];
                    }
                );
            }

            $this->step($claim, 'auth_private_data', 80, function () use ($userId): array {
                $this->authData->erasePrivateData($userId);

                return ['verified' => $this->authData->verify($userId)];
            });
            $this->step($claim, 'wordpress_identity', 90, function () use ($userId): array {
                $this->identities->anonymize($userId);

                return ['verified' => $this->identities->isAnonymized($userId)];
            });
            $this->step(
                $claim,
                'final_verification',
                100,
                function () use ($userId, $anonymousLogin): array {
                    if (! $this->authData->verify($userId)
                        || ! $this->identities->isAnonymized($userId)) {
                        throw new RuntimeException('계정 익명화 최종 검증에 실패했습니다.');
                    }
                    foreach ($this->participants->all() as $participant) {
                        if (! $participant->verify($userId, $anonymousLogin)) {
                            throw new RuntimeException('모듈 익명화 최종 검증에 실패했습니다.');
                        }
                    }

                    return ['verified' => true];
                }
            );
            $this->step($claim, 'completion_record', 110, function () use ($userId, $claim): array {
                $this->deletedUsers->recordCompleted(
                    $userId,
                    $claim->createdByUserId(),
                    $claim->jobId(),
                    'self_requested'
                );

                return ['recorded' => true];
            });

            return JobHandlerResult::succeeded(
                'auth.erasure_completed',
                '계정 익명화를 완료했습니다.',
                ['user_id' => $userId, 'job_id' => $claim->jobId()]
            );
        } catch (Throwable $error) {
            if (! $error instanceof DomainException) {
                $this->errors->report($error);
            }
            return $locked
                ? JobHandlerResult::needsAttention(
                    'auth.erasure_forward_attention',
                    '계정이 잠긴 뒤 중단되었습니다. 관리자가 완료되지 않은 단계부터 다시 실행해야 합니다.'
                )
                : JobHandlerResult::needsAttention(
                    'auth.erasure_preflight_failed',
                    '계정 익명화 사전 검증을 완료하지 못했습니다.'
                );
        }
    }

    private function userId(JobWorkItem $item): int
    {
        $payload = $item->payload();
        $claim = $item->claim();
        $userId = is_array($payload) && is_numeric($payload['user_id'] ?? null)
            ? (int) $payload['user_id']
            : 0;
        if (
            $userId < 1
            || $claim->targetType() !== 'user'
            || $claim->targetKey() !== (string) $userId
            || $claim->createdByUserId() !== $userId
        ) {
            throw new DomainException('계정 익명화 작업 대상이 올바르지 않습니다.');
        }

        return $userId;
    }

    /** @param callable(): array<string, mixed> $operation */
    private function step(
        ClaimedJobItem $claim,
        string $stepKey,
        int $stepOrder,
        callable $operation
    ): void {
        $this->steps->start(
            $claim,
            $stepKey,
            $stepOrder,
            hash('sha256', $claim->inputHash() . ':' . $stepKey),
            ['step_key' => $stepKey]
        );
        try {
            $result = $operation();
            if (($result['verified'] ?? true) !== true) {
                throw new RuntimeException('계정 익명화 단계 검증에 실패했습니다.');
            }
            $this->steps->complete($claim, $stepKey, $result);
        } catch (Throwable $error) {
            $this->steps->fail($claim, $stepKey, ['result_code' => 'step_failed']);

            throw $error;
        }
    }
}
