<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthorizedPasswordResetter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAccountProvisioner;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupContextAccess;
use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Groups\Domain\GroupReport;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Clock\SiteDateBoundaryConverter;
use CodingPanda\OjPlatform\Shared\Clock\SystemClock;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Shared\Selection\GroupMembershipProvider;
use DomainException;
use InvalidArgumentException;

final class GroupService implements
    GroupSubmissionAccess,
    GroupContextAccess,
    ChoiceProvider,
    GroupMembershipProvider
{
    private const VISIBILITIES = ['public', 'code', 'private'];
    private const APPROVAL_MODES = ['manual', 'automatic'];
    private const EVALUATION_MODES = ['normal', 'performance'];
    private const BLOCKLY_PROFILES = ['intro', 'standard', 'extended'];
    private readonly SiteDateBoundaryConverter $dateBoundaries;

    public function __construct(
        private readonly GroupRepository $groups,
        private readonly Authorization $permissions,
        private readonly WordPressUserGateway $users,
        private readonly LearnerAccountProvisioner $accounts,
        private readonly AuthorizedPasswordResetter $passwords,
        private readonly SubmissionProjectionProvider $submissions,
        Clock $clock = new SystemClock()
    ) {
        $this->dateBoundaries = new SiteDateBoundaryConverter($clock);
    }

    /** @return list<LearningGroup> */
    public function visibleFor(int $userId): array
    {
        return $this->groups->visibleFor($userId);
    }

    public function homeFor(int $userId, int $limit, bool $managed): array
    {
        return $this->groups instanceof \CodingPanda\OjPlatform\Modules\Groups\Contract\GroupHomeRepository
            ? $this->groups->homeFor($userId, $managed, $this->isGlobalManager($userId), $limit)
            : array_slice(array_values(array_filter($managed ? $this->managedBy($userId) : $this->visibleFor($userId),
                static fn (LearningGroup $group): bool => !$group->isDeleted() && $group->isEnabled())), 0, $limit);
    }

    /** @return list<LearningGroup> */
    public function learningBy(int $userId): array
    {
        if ($this->groups instanceof \CodingPanda\OjPlatform\Modules\Groups\Contract\GroupLearningRepository) {
            return $this->groups->learningFor($userId);
        }
        $learning = [];
        foreach ($this->activeGroupIdsForUser($userId) as $groupId) {
            $group = $this->groups->find($groupId);
            if ($group !== null && ! $group->isDeleted() && $group->isEnabled()) {
                $learning[$groupId] = $group;
            }
        }
        ksort($learning, SORT_NUMERIC);

        return array_values($learning);
    }

    /** @return list<LearningGroup> */
    public function managedBy(int $userId): array
    {
        return $this->groups->managedBy($userId, $this->isGlobalManager($userId));
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function choicesFor(int $userId, array $includedIds = []): array
    {
        $groups = array_merge(
            $this->groups->visibleFor($userId),
            $this->groups->managedBy($userId, $this->isGlobalManager($userId))
        );
        foreach ($includedIds as $groupId) {
            $group = $this->groups->find((int) $groupId);
            if ($group !== null) {
                $groups[] = $group;
            }
        }
        $choices = [];
        foreach ($groups as $group) {
            if (! $group->isDeleted()) {
                $choices[$group->id()] = $group->name();
            }
        }
        natcasesort($choices);

        return $choices;
    }

    public function activeGroupIdsForUser(int $userId): array
    {
        if ($this->groups instanceof \CodingPanda\OjPlatform\Modules\Groups\Contract\GroupLearningRepository) {
            return array_map(static fn (LearningGroup $group): int => $group->id(), $this->groups->learningFor($userId));
        }
        $active = [];
        foreach ($this->groups->activeGroupIdsForUser($userId) as $groupId) {
            $group = $this->groups->find($groupId);
            if (
                $group !== null
                && ! $group->isDeleted()
                && $group->isEnabled()
            ) {
                $active[] = $groupId;
            }
        }

        return array_values(array_unique($active));
    }

    public function activeUserIdsForGroups(array $groupIds): array
    {
        $users = [];
        foreach (array_values(array_unique(array_filter(
            $groupIds,
            static fn (mixed $groupId): bool =>
                is_int($groupId) && $groupId > 0
        ))) as $groupId) {
            $group = $this->groups->find($groupId);
            if (
                $group === null
                || $group->isDeleted()
                || ! $group->isEnabled()
            ) {
                continue;
            }
            foreach ($this->groups->memberUserIds($groupId) as $userId) {
                if ($userId > 0) {
                    $users[$userId] = true;
                }
            }
        }

        $ids = array_keys($users);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public function userIdByLogin(string $userLogin): int
    {
        $identity = $this->users->findByLogin(trim($userLogin));
        if ($identity === null) {
            throw new DomainException('로그인 아이디와 일치하는 사용자를 찾을 수 없습니다.');
        }

        return $identity->userId();
    }

    public function findVisible(int $groupId, int $userId): LearningGroup
    {
        $group = $this->requireGroup($groupId);
        if (
            $group->isDeleted()
            || ! $group->isEnabled()
            || (
                ! in_array($group->visibility(), ['public', 'code'], true)
                && ! $this->isMemberOrStaff($group, $userId)
            )
        ) {
            throw new DomainException('접근할 수 있는 그룹이 아닙니다.');
        }

        return $group;
    }

    public function findManaged(int $groupId, int $userId): LearningGroup
    {
        $group = $this->requireGroup($groupId);
        if (! $this->canManage($userId, $group)) {
            throw new DomainException('이 그룹을 관리할 권한이 없습니다.');
        }

        return $group;
    }

    /** @return list<int> */
    public function memberUserIds(int $groupId, string $status = 'active'): array
    {
        return $this->groups->memberUserIds($groupId, $status);
    }

    /** @return list<int> */
    public function activeLearnerUserIds(int $groupId): array
    {
        $ids = array_values(array_unique(array_filter(
            $this->groups->memberUserIds($groupId, 'active'),
            static fn (mixed $userId): bool => is_int($userId) && $userId > 0
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return list<int> */
    public function managerUserIds(int $groupId): array
    {
        return $this->groups->managerUserIds($groupId);
    }

    public function membershipStatus(int $groupId, int $userId): ?string
    {
        return $this->groups->membershipStatus($groupId, $userId);
    }
    public function save(int $actorUserId, GroupSaveCommand $command): int
    {
        $this->validateCommand($command);
        if ($command->groupId() > 0) {
            $this->findManaged($command->groupId(), $actorUserId);
        } elseif (! $this->permissions->can($actorUserId, 'oj_create_groups')) {
            throw new DomainException('그룹을 만들 권한이 없습니다.');
        }
        $joinCodeHash = $command->joinCode() === null
            ? null
            : password_hash($command->joinCode(), PASSWORD_DEFAULT);

        return $this->groups->saveAggregate(
            $actorUserId,
            $command,
            $joinCodeHash
        );
    }

    public function membership(
        int $actorUserId,
        int $groupId,
        string $operation,
        int $targetUserId,
        string $joinCode = ''
    ): void {
        $group = $this->requireGroup($groupId);
        if (
            $targetUserId < 1
            || $this->users->findById($targetUserId) === null
        ) {
            throw new DomainException('대상 사용자를 찾을 수 없습니다.');
        }
        if (in_array($operation, ['join', 'cancel', 'leave'], true)) {
            if ($actorUserId !== $targetUserId) {
                throw new DomainException('본인의 그룹 참여 상태만 변경할 수 있습니다.');
            }
            if ($operation === 'join') {
                if (
                    ! in_array($group->visibility(), ['public', 'code'], true)
                    || ! $group->acceptsJoinCode($joinCode)
                ) {
                    throw new DomainException(
                        '이 그룹에 참여할 수 없거나 참여 코드가 올바르지 않습니다.'
                    );
                }
                $status = $group->approvalMode() === 'automatic'
                    ? 'active'
                    : 'pending';
            } else {
                $status = $operation === 'leave' ? 'left' : 'removed';
            }
        } else {
            $this->findManaged($groupId, $actorUserId);
            $status = match ($operation) {
                'add', 'approve' => 'active',
                'reject' => 'rejected',
                'remove' => 'removed',
                default => throw new DomainException(
                    '정의되지 않은 그룹 구성원 작업입니다.'
                ),
            };
        }
        $this->groups->setMembershipStatus(
            $groupId,
            $targetUserId,
            $status,
            $actorUserId
        );
    }

    public function manager(
        int $actorUserId,
        int $groupId,
        int $targetUserId,
        bool $enabled
    ): void {
        $group = $this->requireGroup($groupId);
        if (
            $targetUserId < 1
            || $this->users->findById($targetUserId) === null
        ) {
            throw new DomainException('대상 사용자를 찾을 수 없습니다.');
        }
        if (
            ! $this->isGlobalManager($actorUserId)
            && $group->ownerUserId() !== $actorUserId
        ) {
            throw new DomainException('그룹 운영자를 지정할 권한이 없습니다.');
        }
        if ($targetUserId === $group->ownerUserId()) {
            throw new DomainException('그룹 소유자의 운영 권한은 변경할 수 없습니다.');
        }
        $this->groups->setManager(
            $groupId,
            $targetUserId,
            $enabled,
            $actorUserId
        );
    }

    /**
     * @return array{
     *   row_number: int,
     *   user_id: int,
     *   user_login: string,
     *   created: bool
     * }
     */
    public function importMember(
        int $actorUserId,
        int $groupId,
        int $rowNumber,
        string $userLogin,
        string $email,
        string $displayName,
        string $password
    ): array {
        $group = $this->findManaged($groupId, $actorUserId);
        if ($group->isDeleted()) {
            throw new DomainException('보관된 그룹에는 구성원을 등록할 수 없습니다.');
        }
        $userLogin = trim($userLogin);
        if ($rowNumber < 1 || $userLogin === '') {
            throw new DomainException('구성원 행과 로그인 아이디를 확인해 주세요.');
        }
        $identity = $this->users->findByLogin($userLogin);
        $created = false;
        if ($identity === null) {
            $email = trim($email);
            $displayName = trim($displayName);
            if (
                $email === ''
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || $displayName === ''
                || $password === ''
            ) {
                throw new DomainException(
                    $rowNumber . '행의 새 계정 정보를 확인해 주세요.'
                );
            }
            $identity = $this->accounts->provisionLearner(
                $userLogin,
                $email,
                $password,
                $displayName
            );
            $created = true;
        }
        $this->groups->setMembershipStatus(
            $groupId,
            $identity->userId(),
            'active',
            $actorUserId
        );

        return [
            'row_number' => $rowNumber,
            'user_id' => $identity->userId(),
            'user_login' => $identity->userLogin(),
            'created' => $created,
        ];
    }

    /**
     * @param list<int> $targetUserIds
     * @return list<array{
     *   user_id: int,
     *   user_login: string,
     *   success: bool,
     *   temporary_password: ?string,
     *   reason: string
     * }>
     */
    public function resetMemberPasswords(
        int $actorUserId,
        int $groupId,
        array $targetUserIds,
        ?string $customPassword = null,
        bool $confirmWeakPassword = false
    ): array {
        $group = $this->findManaged($groupId, $actorUserId);
        $active = array_flip($this->groups->memberUserIds($groupId));
        $managers = array_flip($this->groups->managerUserIds($groupId));
        $targets = array_values(array_unique(array_filter(
            array_map('intval', $targetUserIds),
            static fn (int $userId): bool => $userId > 0
        )));
        foreach ($targets as $targetUserId) {
            if (
                ! isset($active[$targetUserId])
                || isset($managers[$targetUserId])
                || $targetUserId === $group->ownerUserId()
            ) {
                throw new DomainException(
                    '활성 일반 구성원만 비밀번호를 초기화할 수 있습니다.'
                );
            }
        }

        $logins = [];
        foreach ($this->users->findMany($targets) as $identity) {
            $logins[$identity->userId()] = $identity->userLogin();
        }
        $results = $this->passwords->resetAuthorized(
            $actorUserId,
            $targets,
            'group:' . $groupId,
            $customPassword,
            $confirmWeakPassword
        );

        return array_map(
            static fn (array $result): array => $result + [
                'user_login' => $logins[(int) $result['user_id']] ?? '',
            ],
            $results
        );
    }

    public function setArchived(
        int $actorUserId,
        int $groupId,
        bool $archived
    ): void {
        $group = $this->findManaged($groupId, $actorUserId);
        if (! $this->canArchive($actorUserId, $group)) {
            throw new DomainException(
                '그룹 소유자 또는 전역 관리자만 보관·복원할 수 있습니다.'
            );
        }
        $this->groups->setDeleted($groupId, $archived, $actorUserId);
    }

    public function report(
        int $actorUserId,
        int $groupId,
        bool $ownOnly = false,
        ?string $fromDate = null,
        ?string $toDate = null
    ): GroupReport {
        $group = $this->requireGroup($groupId);
        $canManage = $this->canManage($actorUserId, $group);
        $isMember = $this->groups->isActiveMember($groupId, $actorUserId);
        if (! $canManage && ! $isMember) {
            throw new DomainException('그룹 학습 현황을 볼 권한이 없습니다.');
        }
        $memberIds = $ownOnly
            ? [$actorUserId]
            : $this->groups->memberUserIds($groupId);
        $members = $this->users->findMany($memberIds);
        $logins = array_map(
            static fn (UserIdentity $user): string => $user->userLogin(),
            $members
        );
        try {
            $submittedFrom = $this->dateBoundaries->startUtc($fromDate);
            $submittedUntil = $this->dateBoundaries->endUtc($toDate);
        } catch (InvalidArgumentException) {
            throw new DomainException('조회 기간은 올바른 날짜로 입력해 주세요.');
        }
        if (
            $submittedFrom !== null
            && $submittedUntil !== null
            && $submittedFrom > $submittedUntil
        ) {
            throw new DomainException('조회 시작일은 종료일보다 늦을 수 없습니다.');
        }
        $submissions = $logins === []
            ? []
            : $this->submissions->reportAll(
                new SubmissionProjectionQuery(
                    $logins,
                    [],
                    null,
                    null,
                    null,
                    true,
                    1000,
                    0,
                    null,
                    $submittedFrom,
                    $submittedUntil
                )
            );

        return new GroupReport($group, $members, $submissions);
    }

    public function restrictsOwnSubmissionDetails(int $userId): bool
    {
        if ($this->isGlobalManager($userId)) {
            return false;
        }
        $performanceGroupIds =
            $this->groups->activePerformanceGroupIdsForUser($userId);
        if ($performanceGroupIds === []) {
            return false;
        }
        $managed = array_flip(
            $this->groups->managedGroupIdsForUser($userId)
        );
        foreach ($performanceGroupIds as $groupId) {
            if (! isset($managed[$groupId])) {
                return true;
            }
        }

        return false;
    }

    public function canViewMemberSubmission(
        int $actorUserId,
        string $targetUserLogin
    ): bool {
        if ($this->isGlobalManager($actorUserId)) {
            return true;
        }
        $target = $this->users->findByLogin($targetUserLogin);
        if ($target === null) {
            return false;
        }
        $managed = array_flip(
            $this->groups->managedGroupIdsForUser($actorUserId)
        );
        foreach ($this->groups->activeGroupIdsForUser($target->userId()) as $id) {
            if (isset($managed[$id])) {
                return true;
            }
        }

        return false;
    }

    public function canCreate(int $userId): bool
    {
        return $this->permissions->can($userId, 'oj_create_groups');
    }

    public function canArchive(int $userId, LearningGroup $group): bool
    {
        return $this->isGlobalManager($userId)
            || $group->ownerUserId() === $userId;
    }
    public function canManage(int $userId, LearningGroup $group): bool
    {
        return $this->isGlobalManager($userId)
            || $group->ownerUserId() === $userId
            || $this->groups->isActiveManager($group->id(), $userId);
    }

    public function canManageGroup(int $userId, int $groupId): bool
    {
        $group = $groupId > 0 ? $this->groups->find($groupId) : null;

        return $group instanceof LearningGroup
            && $this->canManage($userId, $group);
    }

    public function findById(int $groupId): ?LearningGroup
    {
        return $groupId > 0 ? $this->groups->find($groupId) : null;
    }

    public function isActiveMember(int $groupId, int $userId): bool
    {
        return $this->groups->isActiveMember($groupId, $userId);
    }

    /** @return 'global_manager'|'owner'|'manager'|'none' */
    public function relationOf(int $userId, LearningGroup $group): string
    {
        if ($this->isGlobalManager($userId)) {
            return 'global_manager';
        }
        if ($group->ownerUserId() === $userId) {
            return 'owner';
        }
        if ($this->groups->isActiveManager($group->id(), $userId)) {
            return 'manager';
        }

        return 'none';
    }

    private function isMemberOrStaff(LearningGroup $group, int $userId): bool
    {
        return $this->canManage($userId, $group)
            || $this->groups->isActiveMember($group->id(), $userId);
    }

    private function isGlobalManager(int $userId): bool
    {
        return $this->permissions->isSuperAdmin($userId)
            || $this->permissions->can($userId, 'oj_manage_groups');
    }

    private function requireGroup(int $groupId): LearningGroup
    {
        $group = $groupId > 0 ? $this->groups->find($groupId) : null;
        if ($group === null) {
            throw new DomainException('그룹을 찾을 수 없습니다.');
        }

        return $group;
    }

    private function validateCommand(GroupSaveCommand $command): void
    {
        if ($command->name() === '') {
            throw new DomainException('그룹 이름을 입력해 주세요.');
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $command->slug()) !== 1) {
            throw new DomainException(
                '그룹 주소는 영문 소문자, 숫자, 하이픈으로 입력해 주세요.'
            );
        }
        if (! in_array($command->visibility(), self::VISIBILITIES, true)) {
            throw new DomainException('그룹 공개 범위가 올바르지 않습니다.');
        }
        if (! in_array($command->approvalMode(), self::APPROVAL_MODES, true)) {
            throw new DomainException('그룹 승인 방식이 올바르지 않습니다.');
        }
        if (! in_array($command->evaluationMode(), self::EVALUATION_MODES, true)) {
            throw new DomainException('그룹 운영 모드가 올바르지 않습니다.');
        }
        if (
            $command->blocklyProfileKey() !== null
            && ! in_array(
                $command->blocklyProfileKey(),
                self::BLOCKLY_PROFILES,
                true
            )
        ) {
            throw new DomainException('Blockly 프로필이 올바르지 않습니다.');
        }
        if (
            $command->visibility() === 'code'
            && $command->groupId() < 1
            && $command->joinCode() === null
        ) {
            throw new DomainException('참여 코드 그룹은 참여 코드가 필요합니다.');
        }
    }

}
