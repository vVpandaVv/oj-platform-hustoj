<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Application;

use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserImportCandidate;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use DomainException;

final class UserCsvImportJobHandler implements JobHandler
{
    /**
     * @param DefinitionRegistry<RoleDefinition> $roles
     */
    public function __construct(
        private readonly AdminUserCreationService $users,
        private readonly Authorization $permissions,
        private readonly DefinitionRegistry $roles
    ) {
    }

    public function jobType(): string
    {
        return 'user_csv_import';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        $actorUserId = $item->claim()->createdByUserId();
        if (! $this->permissions->can($actorUserId, 'oj_create_users')) {
            return JobHandlerResult::failed(
                'permission_revoked',
                '사용자 생성 권한이 회수되었습니다.'
            );
        }
        if (! is_array($item->payload())) {
            return JobHandlerResult::failed(
                'invalid_payload',
                '사용자 후보 정보가 올바르지 않습니다.'
            );
        }

        try {
            $candidate = UserImportCandidate::fromPayload($item->payload());
            if ($candidate->userLogin() !== $item->claim()->targetKey()) {
                throw new DomainException('작업 대상과 사용자 후보가 다릅니다.');
            }
            if (
                $candidate->role() !== 'subscriber'
                && (
                    ! $this->permissions->can(
                        $actorUserId,
                        'oj_manage_roles'
                    )
                    || ! $this->roles->find(
                        $candidate->role()
                    ) instanceof RoleDefinition
                )
            ) {
                throw new DomainException('운영 역할을 배정할 권한이 없습니다.');
            }

            $identity = $this->users->createIdempotently(
                $candidate->userLogin(),
                $candidate->email(),
                $candidate->password(),
                $candidate->displayName(),
                $candidate->affiliation(),
                $candidate->birthDate(),
                $candidate->role()
            );

            return JobHandlerResult::succeeded(
                'created',
                '사용자 계정을 생성했습니다.',
                [
                    'row_number' => $candidate->rowNumber(),
                    'user_login' => $candidate->userLogin(),
                    'user_id' => $identity->userId(),
                ]
            );
        } catch (DomainException) {
            return JobHandlerResult::failed(
                'validation_failed',
                '실행 시 사용자 후보 검증을 통과하지 못했습니다.'
            );
        }
    }
}
