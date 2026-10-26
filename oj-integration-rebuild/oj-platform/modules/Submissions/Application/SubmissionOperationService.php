<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionContextStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionEventStore;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionGateway;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use DomainException;

final class SubmissionOperationService
{
    public function __construct(
        private readonly SubmissionGateway $submissions,
        private readonly SubmissionEventStore $events,
        private readonly SubmissionContextStore $contexts,
        private readonly SubmissionStaffScopeRegistry $staffScopes,
        private readonly Authorization $permissions
    ) {
    }

    /**
     * @param list<SubmissionId> $submissionIds
     */
    public function rejudge(
        int $actorUserId,
        array $submissionIds,
        string $reason,
        ?string $requestKey = null
    ): int {
        $reason = $this->reason($reason);
        $requestKey = $this->requestKey($requestKey);
        $submissionIds = $this->authorized(
            $actorUserId,
            $submissionIds,
            'oj_rejudge_submissions',
            true
        );
        $count = 0;
        foreach ($submissionIds as $submissionId) {
            $previousResult = $this->submissions->rejudge($submissionId);
            $this->events->record(
                'submission_rejudge_requested',
                $actorUserId,
                $submissionId,
                $this->itemRequestKey($requestKey, $submissionId),
                $reason,
                ['previous_result' => $previousResult]
            );
            ++$count;
        }

        return $count;
    }

    /**
     * @param list<SubmissionId> $submissionIds
     */
    public function setValidity(
        int $actorUserId,
        array $submissionIds,
        bool $valid,
        string $reason,
        ?string $requestKey = null
    ): int {
        $reason = $this->reason($reason);
        $requestKey = $this->requestKey($requestKey);
        $submissionIds = $this->authorized(
            $actorUserId,
            $submissionIds,
            'oj_delete_submissions',
            false
        );
        $count = 0;
        foreach ($submissionIds as $submissionId) {
            $changed = $this->submissions->setValid($submissionId, $valid);
            $this->events->record(
                $valid ? 'submission_restored' : 'submission_invalidated',
                $actorUserId,
                $submissionId,
                $this->itemRequestKey($requestKey, $submissionId),
                $reason,
                ['changed' => $changed]
            );
            if ($changed) {
                ++$count;
            }
        }

        return $count;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (strlen($reason) < 3 || strlen($reason) > 500) {
            throw new DomainException('처리 사유를 3자 이상 500자 이하로 입력해 주세요.');
        }

        return $reason;
    }

    /**
     * @param list<SubmissionId> $submissionIds
     * @return list<SubmissionId>
     */
    private function authorized(
        int $actorUserId,
        array $submissionIds,
        string $globalCapability,
        bool $allowStaffScope
    ): array {
        $submissionIds = $this->unique($submissionIds);
        $summaries = $this->submissions->findSummaries($submissionIds);
        foreach ($submissionIds as $submissionId) {
            if (! isset($summaries[$submissionId->value()])) {
                throw new DomainException(
                    '선택한 제출 중 찾을 수 없는 항목이 있습니다.'
                );
            }
        }

        $hasGlobalPermission = $this->permissions->isSuperAdmin($actorUserId)
            || $this->permissions->can($actorUserId, $globalCapability);
        if ($hasGlobalPermission) {
            return $submissionIds;
        }
        if (! $allowStaffScope) {
            throw new DomainException('제출 관리 권한이 없습니다.');
        }

        $contexts = $this->contexts->findMany($submissionIds);
        foreach ($submissionIds as $submissionId) {
            $summary = $summaries[$submissionId->value()];
            $context = $contexts[$submissionId->value()] ?? null;
            if ($context !== null) {
                $summary = $summary->withContext(
                    $context->classId(),
                    $context->groupId()
                );
            }
            if (! $this->staffScopes->canView($actorUserId, $summary)) {
                throw new DomainException(
                    '선택한 제출 중 담당 범위를 벗어난 항목이 있습니다.'
                );
            }
        }

        return $submissionIds;
    }

    /**
     * @param list<SubmissionId> $submissionIds
     * @return list<SubmissionId>
     */
    private function unique(array $submissionIds): array
    {
        $unique = [];
        foreach ($submissionIds as $submissionId) {
            $unique[$submissionId->value()] = $submissionId;
        }
        if ($unique === []) {
            throw new DomainException('처리할 제출을 선택해 주세요.');
        }

        return array_values($unique);
    }

    private function itemRequestKey(
        ?string $requestKey,
        SubmissionId $submissionId
    ): ?string {
        if ($requestKey === null) {
            return null;
        }

        return hash('sha256', $requestKey . ':' . $submissionId->value());
    }

    private function requestKey(?string $requestKey): ?string
    {
        if ($requestKey === null || $requestKey === '') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $requestKey) !== 1) {
            throw new DomainException('요청 식별자가 올바르지 않습니다.');
        }

        return $requestKey;
    }
}
