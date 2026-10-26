<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAssignmentRepository;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemGateway;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CollaboratorRole;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemAssignment;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use DomainException;

final class ProblemAssignmentService
{
    public function __construct(
        private readonly ProblemGateway $problems,
        private readonly ProblemAssignmentRepository $assignments,
        private readonly ProblemAccessPolicy $access,
        private readonly WordPressUserGateway $users
    ) {
    }

    /**
     * @return array{
     *     assignment:ProblemAssignment,
     *     users:array<int, UserIdentity>,
     *     can_manage:bool
     * }
     */
    public function view(int $actorUserId, ProblemId $problemId): array
    {
        if (
            $this->problems->find($problemId) === null
            || ! $this->access->canEdit($actorUserId, $problemId)
        ) {
            throw new DomainException('이 문제의 담당자 정보를 볼 수 없습니다.');
        }
        $assignment = $this->assignments->find($problemId);
        $userIds = array_keys($assignment->collaborators());
        if ($assignment->ownerUserId() !== null) {
            $userIds[] = $assignment->ownerUserId();
        }
        $identities = [];
        foreach ($this->users->findMany($userIds) as $identity) {
            $identities[$identity->userId()] = $identity;
        }

        return [
            'assignment' => $assignment,
            'users' => $identities,
            'can_manage' => $this->access->canManageAssignments(
                $actorUserId,
                $problemId
            ),
        ];
    }

    public function assignOwner(
        int $actorUserId,
        ProblemId $problemId,
        string $userLogin,
        bool $confirmed
    ): UserIdentity {
        $assignment = $this->assertCanManage($actorUserId, $problemId);
        $target = $this->requireUser($userLogin);
        if ($assignment->isOwner($target->userId())) {
            return $target;
        }
        if (! $confirmed) {
            throw new DomainException('소유권 이전 확인을 선택해 주세요.');
        }
        $this->assignments->assignOwner(
            $problemId,
            $target->userId(),
            $actorUserId
        );
        $this->assignments->revoke($problemId, $target->userId());

        return $target;
    }

    public function grant(
        int $actorUserId,
        ProblemId $problemId,
        string $userLogin,
        CollaboratorRole $role
    ): UserIdentity {
        $assignment = $this->assertCanManage($actorUserId, $problemId);
        $target = $this->requireUser($userLogin);
        if ($assignment->isOwner($target->userId())) {
            throw new DomainException('문제 소유자는 공동 담당자로 중복 등록할 수 없습니다.');
        }
        $this->assignments->grant(
            $problemId,
            $target->userId(),
            $role,
            $actorUserId
        );

        return $target;
    }

    public function revoke(
        int $actorUserId,
        ProblemId $problemId,
        int $targetUserId
    ): void {
        $assignment = $this->assertCanManage($actorUserId, $problemId);
        if ($targetUserId < 1) {
            throw new DomainException('해제할 담당 사용자를 찾을 수 없습니다.');
        }
        if ($assignment->isOwner($targetUserId)) {
            throw new DomainException('문제 소유자는 담당 해제로 제거할 수 없습니다.');
        }
        if ($assignment->collaboratorRole($targetUserId) === null) {
            throw new DomainException('현재 활성 상태인 공동 담당자가 아닙니다.');
        }
        $this->assignments->revoke($problemId, $targetUserId);
    }

    private function assertCanManage(
        int $actorUserId,
        ProblemId $problemId
    ): ProblemAssignment {
        if (
            $this->problems->find($problemId) === null
            || ! $this->access->canManageAssignments($actorUserId, $problemId)
        ) {
            throw new DomainException('이 문제의 담당자를 변경할 권한이 없습니다.');
        }

        return $this->assignments->find($problemId);
    }

    private function requireUser(string $userLogin): UserIdentity
    {
        $userLogin = trim($userLogin);
        if (
            $userLogin === ''
            || strlen($userLogin) > 60
            || preg_match('/[\x00-\x1F\x7F]/', $userLogin) === 1
        ) {
            throw new DomainException('WordPress 로그인 아이디를 확인해 주세요.');
        }
        $identity = $this->users->findByLogin($userLogin);
        if ($identity === null) {
            throw new DomainException('해당 로그인 아이디의 사용자를 찾을 수 없습니다.');
        }

        return $identity;
    }
}
