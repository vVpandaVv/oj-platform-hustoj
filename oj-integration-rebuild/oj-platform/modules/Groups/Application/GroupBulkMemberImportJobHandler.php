<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Application;

use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandler;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobHandlerResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use DomainException;

final class GroupBulkMemberImportJobHandler implements JobHandler
{
    public function __construct(private readonly GroupService $groups)
    {
    }

    public function jobType(): string
    {
        return 'group_member_csv_import';
    }

    public function handle(JobWorkItem $item): JobHandlerResult
    {
        $payload = $item->payload();
        if (! is_array($payload)) {
            return JobHandlerResult::failed(
                'invalid_payload',
                '그룹 구성원 정보가 올바르지 않습니다.'
            );
        }
        $groupId = is_int($payload['group_id'] ?? null)
            ? $payload['group_id']
            : 0;
        $rowNumber = is_int($payload['row_number'] ?? null)
            ? $payload['row_number']
            : 0;
        $login = is_string($payload['user_login'] ?? null)
            ? $payload['user_login']
            : '';
        $expectedKey = $groupId . ':' . $rowNumber . ':' . $login;
        if (
            $groupId < 1
            || $rowNumber < 1
            || $login === ''
            || $item->claim()->targetType() !== 'group_member'
            || $item->claim()->targetKey() !== $expectedKey
        ) {
            return JobHandlerResult::failed(
                'invalid_payload',
                '그룹 구성원 작업 대상이 올바르지 않습니다.'
            );
        }

        $actorUserId = $item->claim()->createdByUserId();
        if (! $this->groups->canManageGroup($actorUserId, $groupId)) {
            return JobHandlerResult::failed(
                'permission_revoked',
                '그룹 구성원 관리 권한이 회수되었습니다.'
            );
        }

        try {
            $result = $this->groups->importMember(
                $actorUserId,
                $groupId,
                $rowNumber,
                $login,
                is_string($payload['email'] ?? null) ? $payload['email'] : '',
                is_string($payload['display_name'] ?? null)
                    ? $payload['display_name']
                    : '',
                is_string($payload['password'] ?? null)
                    ? $payload['password']
                    : ''
            );

            return JobHandlerResult::succeeded(
                $result['created'] ? 'account_created' : 'member_registered',
                $result['created']
                    ? '계정을 생성하고 그룹 구성원으로 등록했습니다.'
                    : '기존 계정을 그룹 구성원으로 등록했습니다.',
                $result
            );
        } catch (DomainException) {
            return JobHandlerResult::failed(
                'validation_failed',
                '구성원 정보를 검증하거나 등록하지 못했습니다.'
            );
        }
    }
}
