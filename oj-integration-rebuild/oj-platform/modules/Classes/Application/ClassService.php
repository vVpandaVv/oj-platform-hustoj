<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassContextAccess;
use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassProblem;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassReport;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Shared\Selection\GroupMembershipProvider;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class ClassService implements ClassSubmissionAccess, ClassContextAccess
{
    private const VISIBILITIES = ['public', 'password', 'private', 'group_only'];
    private const PROGRESS_VISIBILITIES = ['private', 'participants_live'];
    private const BLOCKLY_PROFILES = ['intro', 'standard', 'extended'];

    public function __construct(
        private readonly ClassRepository $classes,
        private readonly ClassStaffPolicy $staff,
        private readonly WordPressUserGateway $users,
        private readonly SubmissionProblemProvider $problems,
        private readonly SubmissionProjectionProvider $submissions,
        private readonly ChoiceProvider $groupChoices,
        private readonly Clock $clock,
        private readonly ?\CodingPanda\OjPlatform\Modules\Classes\Contract\ClassProblemChangePublisher $problemChanges = null
    ) {
    }

    public function copyDraft(int $actorUserId, int $sourceClassId): ClassSaveCommand
    {
        if (! $this->canCreate($actorUserId)) {
            throw new DomainException('수업을 복사하려면 수업 생성 권한이 필요합니다.');
        }
        $source = $this->findManaged($sourceClassId, $actorUserId);
        $allowedGroups = $this->groupChoices->isAvailable()
            ? $this->groupChoices->choicesFor($actorUserId, [])
            : [];
        $groupIds = array_values(array_filter(
            $this->classes->groupIds($sourceClassId),
            static fn (int $id): bool => array_key_exists($id, $allowedGroups)
        ));
        $problems = array_map(
            static fn (ClassProblem $problem): ClassProblem => new ClassProblem(
                $problem->problemId(),
                $problem->sortOrder(),
                $problem->isVisible(),
                null,
                null
            ),
            $this->classes->problems($sourceClassId)
        );

        // A copy is a new, unpublished class; old access grants and dates must not carry over.
        return new ClassSaveCommand(
            0,
            $source->name() . ' (복사본)',
            '',
            $source->description(),
            'private',
            'private',
            false,
            null,
            null,
            null,
            $source->blocklyProfileKey(),
            [],
            $groupIds,
            array_map(static fn (ClassProblem $problem): int => $problem->problemId(), $problems),
            $problems,
            $source->requiresApproval()
        );
    }

    /** @return list<LearningClass> */
    public function visibleFor(int $userId): array
    {
        $visible = $this->classes->visibleFor($userId);
        if ($this->groupChoices instanceof GroupMembershipProvider) {
            $visible = array_merge(
                $visible,
                $this->classes->linkedToGroups(
                    $this->groupChoices->activeGroupIdsForUser($userId)
                )
            );
        }
        $byId = [];
        foreach ($visible as $class) {
            $byId[$class->id()] = $class;
        }

        return array_values($byId);
    }

    public function homeFor(int $userId, string $now, int $limit, bool $managed): array
    {
        if ($this->classes instanceof \CodingPanda\OjPlatform\Modules\Classes\Contract\ClassHomeRepository) {
            $groups = $this->groupChoices instanceof GroupMembershipProvider ? $this->groupChoices->activeGroupIdsForUser($userId) : [];
            return $this->classes->homeFor($userId, $groups, $managed, $this->staff->isGlobalManager($userId), $now, $limit);
        }
        return array_slice(array_values(array_filter($managed ? $this->managedBy($userId) : $this->visibleFor($userId),
            static fn (LearningClass $class): bool => !$class->isDeleted() && $class->isEnabled() && $class->isOpenAt($now))), 0, $limit);
    }

    /** @return list<LearningClass> */
    public function pendingInvitationsFor(int $userId): array
    {
        return array_values(array_filter(
            $this->classes->invitedFor($userId),
            fn (LearningClass $class): bool =>
                $this->invitationIsAvailable($class)
                && ! $this->isParticipantOrStaff($class, $userId)
        ));
    }

    public function pendingInvitation(
        int $classId,
        int $userId
    ): ?LearningClass {
        if ($this->classes->enrollmentStatus($classId, $userId) !== 'invited') {
            return null;
        }
        $class = $this->requireClass($classId);
        if ($this->isParticipantOrStaff($class, $userId)) {
            return null;
        }
        $this->assertInvitationAvailable($class);

        return $class;
    }

    /** @return list<LearningClass> */
    public function learningBy(int $userId): array
    {
        if ($this->classes instanceof \CodingPanda\OjPlatform\Modules\Classes\Contract\ClassLearningRepository) {
            $groups = $this->groupChoices instanceof GroupMembershipProvider ? $this->groupChoices->activeGroupIdsForUser($userId) : [];
            return $this->classes->learningFor($userId, $groups);
        }
        $learning = [];
        foreach ($this->visibleFor($userId) as $class) {
            if (
                ! $class->isDeleted()
                && $class->isEnabled()
                && $this->isActiveLearner($class->id(), $userId)
            ) {
                $learning[$class->id()] = $class;
            }
        }
        ksort($learning, SORT_NUMERIC);

        return array_values($learning);
    }

    /** @return list<LearningClass> */
    public function managedBy(int $userId): array
    {
        return $this->classes->managedBy(
            $userId,
            $this->staff->isGlobalManager($userId)
        );
    }

    /** @return list<string> */
    public function groupFeatureBlockers(): array
    {
        $blockers = [];
        foreach ($this->classes->managedBy(0, true) as $class) {
            if (
                $class->isDeleted()
                || (
                    $class->visibility() !== 'group_only'
                    && $this->classes->groupIds($class->id()) === []
                )
            ) {
                continue;
            }
            $blockers[] = sprintf('수업 #%d %s', $class->id(), $class->name());
        }

        return $blockers;
    }

    public function findVisible(int $classId, int $userId): LearningClass
    {
        $class = $this->requireClass($classId);
        if (
            $class->isDeleted()
            || ! $class->isEnabled()
            || (
                ! in_array($class->visibility(), ['public', 'password'], true)
                && ! $this->isParticipantOrStaff($class, $userId)
            )
        ) {
            throw new DomainException('접근할 수 있는 수업이 아닙니다.');
        }

        return $class;
    }

    public function findManaged(int $classId, int $userId): LearningClass
    {
        $class = $this->requireClass($classId);
        if (! $this->canManage($userId, $class)) {
            throw new DomainException('이 수업을 관리할 권한이 없습니다.');
        }

        return $class;
    }

    /** @return list<ClassProblem> */
    public function classProblems(int $classId): array
    {
        return $this->classes->problems($classId);
    }

    /** @return list<ClassProblem> */
    public function classProblemsForUser(int $classId, int $userId): array
    {
        $class = $this->requireClass($classId);
        $problems = $this->classes->problems($classId);
        if ($this->canManage($userId, $class)) {
            return $problems;
        }
        if (! $this->isParticipantOrStaff($class, $userId)) {
            return [];
        }

        return array_values(array_filter(
            $problems,
            static fn (ClassProblem $problem): bool => $problem->isVisible()
        ));
    }

    public function problemAvailability(ClassProblem $problem): string
    {
        return $problem->availabilityAt($this->now());
    }

    /** @return list<int> */
    public function participantUserIds(int $classId, string $status = 'active'): array
    {
        $ids = $this->classes->participantUserIds($classId, $status);
        $class = $this->classes->find($classId);
        if (
            $status === 'active'
            && $class !== null
            && $class->visibility() === 'group_only'
        ) {
            $ids = array_merge($ids, $this->linkedGroupUserIds($class));
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    public function activeLearnerUserIds(int $classId): array
    {
        $ids = array_values(array_unique(array_filter(
            $this->participantUserIds($classId, 'active'),
            static fn (mixed $userId): bool => is_int($userId) && $userId > 0
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return list<int> */
    public function instructorUserIds(int $classId): array
    {
        return $this->classes->instructorUserIds($classId);
    }

    /** @return list<string> */
    public function instructorLogins(int $classId): array
    {
        return array_map(
            static fn (UserIdentity $identity): string => $identity->userLogin(),
            $this->users->findMany($this->classes->instructorUserIds($classId))
        );
    }

    public function userIdByLogin(string $userLogin): int
    {
        $identity = $this->users->findByLogin(trim($userLogin));
        if ($identity === null) {
            throw new DomainException('로그인 아이디와 일치하는 사용자를 찾을 수 없습니다.');
        }

        return $identity->userId();
    }

    /** @return list<int> */
    public function groupIds(int $classId): array
    {
        return $this->classes->groupIds($classId);
    }
    public function participationStatus(int $classId, int $userId): ?string
    {
        $status = $this->classes->enrollmentStatus($classId, $userId);
        if ($status === 'active') {
            return $status;
        }
        $class = $this->classes->find($classId);
        if ($class !== null && $this->isLinkedGroupMember($class, $userId)) {
            return 'group';
        }

        return $status;
    }


    public function save(int $actorUserId, ClassSaveCommand $command): int
    {
        $this->validateCommand($command);
        if ($command->classId() > 0) {
            $class = $this->findManaged($command->classId(), $actorUserId);
            $this->assertSaveAllowed($actorUserId, $class, $command);
            $currentGroupIds = $this->classes->groupIds($class->id());
        } elseif (! $this->staff->canCreate($actorUserId)) {
            throw new DomainException('수업을 만들 권한이 없습니다.');
        } else {
            $currentGroupIds = [];
        }
        $this->validateGroupSelection($actorUserId, $command, $currentGroupIds);
        $passwordHash = $command->password() === null
            ? null
            : password_hash($command->password(), PASSWORD_DEFAULT);
        $this->validateProblemSelection(
            $actorUserId,
            $command->problemIds()
        );

        $savedId = $this->classes->saveAggregate(
            $actorUserId,
            $command,
            $passwordHash
        );
        $this->problemChanges?->saved($savedId,$actorUserId,$command->problemIds());
        return $savedId;
    }

    public function membership(
        int $actorUserId,
        int $classId,
        string $operation,
        int $targetUserId,
        string $password = ''
    ): void {
        $class = $this->requireClass($classId);
        if (
            $targetUserId < 1
            || $this->users->findById($targetUserId) === null
        ) {
            throw new DomainException('대상 사용자를 찾을 수 없습니다.');
        }
        $currentStatus = $this->classes->enrollmentStatus(
            $classId,
            $targetUserId
        );
        if (in_array($operation, ['request', 'cancel', 'leave', 'accept', 'decline'], true)) {
            if ($targetUserId !== $actorUserId) {
                throw new DomainException('본인의 수업 참여 상태만 변경할 수 있습니다.');
            }
            if ($operation === 'request') {
                if ($class->isDeleted() || ! $class->isEnabled()) {
                    throw new DomainException('현재 참여할 수 없는 수업입니다.');
                }
                if ($currentStatus === 'active') { return; }
                if (in_array($currentStatus, ['removed', 'rejected'], true)) {
                    throw new DomainException('참여가 제한된 수업입니다. 수업 담당자에게 문의해 주세요.');
                }
                if (
                    ! in_array($class->visibility(), ['public', 'password'], true)
                    || ! $class->acceptsPassword($password)
                ) {
                    throw new DomainException(
                        '이 수업에 참여할 수 없거나 비밀번호가 올바르지 않습니다.'
                    );
                }
                $status = $class->requiresApproval() ? 'pending' : 'active';
            } elseif ($operation === 'cancel') {
                if ($currentStatus !== 'pending') {
                    throw new DomainException('대기 중인 참여 요청이 없습니다.');
                }
                $status = 'left';
            } elseif ($operation === 'leave') {
                if ($currentStatus !== 'active') {
                    throw new DomainException('직접 참여 중인 수업이 아닙니다.');
                }
                $status = 'left';
            } elseif ($operation === 'accept') {
                if ($currentStatus !== 'invited') {
                    throw new DomainException('수락할 수 있는 초대가 없습니다.');
                }
                $this->assertInvitationAvailable($class);
                $status = 'active';
            } else {
                if ($currentStatus !== 'invited') {
                    throw new DomainException('거절할 수 있는 초대가 없습니다.');
                }
                $this->assertInvitationAvailable($class);
                $status = 'rejected';
            }
        } else {
            $this->findManaged($classId, $actorUserId);
            if ($operation === 'invite') {
                $this->assertInvitationAvailable($class);
                if ($this->isParticipantOrStaff($class, $targetUserId)) {
                    throw new DomainException('이미 참여 중인 사용자는 다시 초대할 수 없습니다.');
                }
                if ($currentStatus === 'invited') {
                    throw new DomainException('이미 수락 대기 중인 초대가 있습니다.');
                }
            }
            $status = match ($operation) {
                'approve', 'reactivate' => 'active',
                'reject' => 'rejected',
                'remove' => 'removed',
                'invite' => 'invited',
                default => throw new DomainException(
                    '정의되지 않은 수업 참여 작업입니다.'
                ),
            };
        }
        if (in_array($operation, ['accept', 'decline'], true)) {
            if (! $this->classes->transitionEnrollmentStatus(
                $classId,
                $targetUserId,
                'invited',
                $status,
                $actorUserId
            )) {
                throw new DomainException(
                    '초대 상태가 이미 변경되었습니다. 수업 목록을 다시 확인해 주세요.'
                );
            }

            return;
        }
        if (in_array($operation, ['invite', 'request'], true)) {
            if (! $this->classes->transitionEnrollmentStatus(
                $classId,
                $targetUserId,
                $currentStatus,
                $status,
                $actorUserId
            )) {
                throw new DomainException(
                    '참여 상태가 이미 변경되었습니다. 수업을 다시 확인해 주세요.'
                );
            }

            return;
        }
        $this->classes->setEnrollmentStatus(
            $classId,
            $targetUserId,
            $status,
            $actorUserId
        );
    }

    public function setArchived(
        int $actorUserId,
        int $classId,
        bool $archived
    ): void {
        $class = $this->findManaged($classId, $actorUserId);
        if (! $this->staff->canArchive($actorUserId, $class)) {
            throw new DomainException(
                '수업 소유자 또는 전역 관리자만 보관·복원할 수 있습니다.'
            );
        }
        $this->classes->setDeleted($classId, $archived, $actorUserId);
    }

    public function report(
        int $actorUserId,
        string $actorLogin,
        int $classId,
        bool $ownOnly = false
    ): ClassReport {
        $class = $this->requireClass($classId);
        $canManage = $this->canManage($actorUserId, $class);
        $participant = $this->isParticipantOrStaff($class, $actorUserId);
        if (
            ! $canManage
            && (
                ! $participant
                || (! $ownOnly && $class->progressVisibility() !== 'participants_live')
            )
        ) {
            throw new DomainException('수업 학습 현황을 볼 권한이 없습니다.');
        }
        $participantIds = $ownOnly
            ? [$actorUserId]
            : $this->participantUserIds($classId);
        $participants = $this->users->findMany($participantIds);
        $classProblems = $this->classes->problems($classId);
        if (! $canManage) {
            $now = $this->now();
            $classProblems = array_values(array_filter(
                $classProblems,
                static fn (ClassProblem $problem): bool => $problem->isAvailableAt($now)
            ));
        }
        $problemIds = array_map(
            static fn (ClassProblem $problem): int => $problem->problemId(),
            $classProblems
        );
        $visibleProblemMap = $this->problems->findVisibleMany(
            $actorUserId,
            $problemIds,
            ProblemAccessContext::class($classId)
        );
        $reportProblems = array_values(array_filter(array_map(
            static fn (int $problemId): ?SubmissionProblem =>
                $visibleProblemMap[$problemId] ?? null,
            $problemIds
        )));
        $logins = array_map(
            static fn (UserIdentity $identity): string => $identity->userLogin(),
            $participants
        );
        if ($ownOnly && $logins === []) {
            $logins = [$actorLogin];
        }
        $query = new SubmissionProjectionQuery(
            $logins,
            $problemIds,
            null,
            $classId,
            submittedFrom: $class->startsAt() === null ? null : (new DateTimeImmutable($class->startsAt(), $this->clock->now()->getTimezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            submittedUntil: $class->endsAt() === null ? null : (new DateTimeImmutable($class->endsAt(), $this->clock->now()->getTimezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
        $projections = $problemIds === [] || $logins === [] ? [] : (($canManage || ! $ownOnly)
            ? $this->submissions->reportAll($query)
            : $this->submissions->searchAll($actorUserId, $actorLogin, $query));

        $problemLinks = [];
        foreach ($classProblems as $problem) {
            $problemLinks[$problem->problemId()] = $problem;
        }
        $projections = array_values(array_filter(
            $projections,
            fn (SubmissionProjection $projection): bool => $this->submissionIsWithinWindow(
                $projection, $class, $problemLinks
            )
        ));

        return new ClassReport(
            $class,
            $participants,
            $reportProblems,
            $projections,
            $ownOnly ? [] : $this->classes->participantApprovedAtMap($classId)
        );
    }

    public function canViewProblem(
        int $userId,
        int $classId,
        int $problemId
    ): bool {
        $class = $this->classes->find($classId);
        if ($class === null || $class->isDeleted()) {
            return false;
        }
        $staff = $this->canManage($userId, $class);
        if (
            ! $staff
            && (
                ! $class->isEnabled()
                || ! $this->isParticipantOrStaff($class, $userId)
            )
        ) {
            return false;
        }
        $now = $this->now();
        foreach ($this->classes->problems($classId) as $problem) {
            if ($problem->problemId() === $problemId) {
                return $staff || $problem->isAvailableAt($now);
            }
        }

        return false;
    }

    /**
     * @param list<int> $problemIds
     * @return array<int, string> problemId => 'solved'|'attempted'|'unsolved'
     */
    public function solveStatusForUser(
        int $userId,
        string $userLogin,
        int $classId,
        array $problemIds
    ): array {
        if ($problemIds === [] || trim($userLogin) === '') {
            return [];
        }
        $status = array_fill_keys($problemIds, 'unsolved');
        $query = new SubmissionProjectionQuery(
            [$userLogin],
            $problemIds,
            null,
            $classId
        );
        foreach ($this->submissions->searchAll($userId, $userLogin, $query) as $projection) {
            $problemId = $projection->problemId();
            if (! isset($status[$problemId]) || $status[$problemId] === 'solved') {
                continue;
            }
            $status[$problemId] = $projection->resultCode() === 4 && $projection->isValid()
                ? 'solved'
                : 'attempted';
        }

        return $status;
    }

    public function validateSubmission(
        int $userId,
        int $classId,
        int $problemId
    ): void {
        $class = $this->requireClass($classId);
        $now = $this->now();
        if (! $class->isOpenAt($now)) {
            throw new DomainException('현재 제출할 수 있는 수업이 아닙니다.');
        }
        if (! $this->isParticipantOrStaff($class, $userId)) {
            throw new DomainException('수업 참여자만 이 수업으로 제출할 수 있습니다.');
        }
        foreach ($this->classes->problems($classId) as $problem) {
            if (
                $problem->problemId() === $problemId
                && $problem->isAvailableAt($now)
            ) {
                return;
            }
        }
        throw new DomainException('수업에 공개된 문제가 아닙니다.');
    }

    public function submissionBlocklyProfile(int $classId): ?string
    {
        return $this->requireClass($classId)->blocklyProfileKey();
    }
    public function canViewSubmission(int $actorUserId, int $classId): bool
    {
        $class = $this->classes->find($classId);

        return $class !== null && $this->canManage($actorUserId, $class);
    }

    public function canCreate(int $userId): bool
    {
        return $this->staff->canCreate($userId);
    }

    public function canManage(int $userId, LearningClass $class): bool
    {
        return $this->staff->canManage($userId, $class);
    }

    public function findById(int $classId): ?LearningClass
    {
        return $classId > 0 ? $this->classes->find($classId) : null;
    }

    public function isActiveParticipant(int $classId, int $userId): bool
    {
        return $this->classes->isActiveParticipant($classId, $userId);
    }

    public function isActiveLearner(int $classId, int $userId): bool
    {
        $class = $this->classes->find($classId);

        return $class !== null
            && ! $class->isDeleted()
            && $class->isEnabled()
            && (
                $this->classes->isActiveParticipant($classId, $userId)
                || $this->isLinkedGroupMember($class, $userId)
            );
    }

    /** @return 'global_manager'|'owner'|'instructor'|'none' */
    public function relationOf(int $userId, LearningClass $class): string
    {
        if ($this->staff->isGlobalManager($userId)) {
            return 'global_manager';
        }
        if ($class->ownerUserId() === $userId) {
            return 'owner';
        }
        if ($this->classes->isActiveInstructor($class->id(), $userId)) {
            return 'instructor';
        }

        return 'none';
    }

    public function canChangeSettings(int $userId, LearningClass $class): bool
    {
        return $this->staff->canChangeSettings($userId, $class);
    }

    public function canArchive(int $userId, LearningClass $class): bool
    {
        return $this->staff->canArchive($userId, $class);
    }

    private function isParticipantOrStaff(
        LearningClass $class,
        int $userId
    ): bool {
        return $this->canManage($userId, $class)
            || $this->classes->isActiveParticipant($class->id(), $userId)
            || $this->isLinkedGroupMember($class, $userId);
    }

    private function isLinkedGroupMember(
        LearningClass $class,
        int $userId
    ): bool {
        if (
            $class->visibility() !== 'group_only'
            || ! ($this->groupChoices instanceof GroupMembershipProvider)
        ) {
            return false;
        }
        $activeGroupIds = array_flip(
            $this->groupChoices->activeGroupIdsForUser($userId)
        );
        foreach ($this->classes->groupIds($class->id()) as $groupId) {
            if (isset($activeGroupIds[$groupId])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    private function linkedGroupUserIds(LearningClass $class): array
    {
        if (
            $class->visibility() !== 'group_only'
            || ! ($this->groupChoices instanceof GroupMembershipProvider)
        ) {
            return [];
        }

        return $this->groupChoices->activeUserIdsForGroups(
            $this->classes->groupIds($class->id())
        );
    }


    /** @param array<int, ClassProblem> $problemLinks */
    private function submissionIsWithinWindow(
        SubmissionProjection $submission,
        LearningClass $class,
        array $problemLinks
    ): bool {
        $problem = $problemLinks[$submission->problemId()] ?? null;
        if ($problem === null) {
            return false;
        }
        $starts = array_values(array_filter([
            $class->startsAt(),
            $problem->availableFrom(),
        ], static fn (?string $value): bool => $value !== null));
        $ends = array_values(array_filter([
            $class->endsAt(),
            $problem->availableUntil(),
        ], static fn (?string $value): bool => $value !== null));
        if ($starts === [] && $ends === []) {
            return true;
        }
        // Class schedules are site-local; Judge submission timestamps are UTC.
        $siteTimezone = $this->clock->now()->getTimezone();
        $submittedAt = new DateTimeImmutable(
            $submission->submittedAt(),
            new DateTimeZone('UTC')
        );

        return ($starts === [] || $submittedAt >= new DateTimeImmutable(max($starts), $siteTimezone))
            && ($ends === [] || $submittedAt <= new DateTimeImmutable(min($ends), $siteTimezone));
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function invitationIsAvailable(LearningClass $class): bool
    {
        return ! $class->isDeleted()
            && $class->isEnabled()
            && ($class->endsAt() === null || $class->endsAt() >= $this->now());
    }

    private function assertInvitationAvailable(LearningClass $class): void
    {
        if (! $this->invitationIsAvailable($class)) {
            throw new DomainException('더 이상 수락할 수 있는 수업 초대가 아닙니다.');
        }
    }

    private function requireClass(int $classId): LearningClass
    {

        $class = $classId > 0 ? $this->classes->find($classId) : null;
        if ($class === null) {
            throw new DomainException('수업을 찾을 수 없습니다.');
        }

        return $class;
    }

    private function validateCommand(ClassSaveCommand $command): void
    {
        if ($command->name() === '') {
            throw new DomainException('수업 이름을 입력해 주세요.');
        }
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $command->slug()) !== 1) {
            throw new DomainException(
                '수업 주소는 영문 소문자, 숫자, 하이픈으로 입력해 주세요.'
            );
        }
        if (! in_array($command->visibility(), self::VISIBILITIES, true)) {
            throw new DomainException('수업 공개 범위가 올바르지 않습니다.');
        }
        if (! in_array(
            $command->progressVisibility(),
            self::PROGRESS_VISIBILITIES,
            true
        )) {
            throw new DomainException('학습 현황 공개 설정이 올바르지 않습니다.');
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
            $command->startsAt() !== null
            && $command->endsAt() !== null
            && $command->startsAt() >= $command->endsAt()
        ) {
            throw new DomainException('수업 종료 시각은 시작 시각보다 늦어야 합니다.');
        }
        if (
            $command->visibility() === 'password'
            && $command->classId() < 1
            && $command->password() === null
        ) {
            throw new DomainException('비밀번호 수업은 참여 비밀번호가 필요합니다.');
        }
        foreach ($command->problemConfigurations() as $problem) {
            if (
                $problem->availableFrom() !== null
                && $problem->availableUntil() !== null
                && $problem->availableFrom() >= $problem->availableUntil()
            ) {
                throw new DomainException(
                    '문제 공개 종료 시각은 시작 시각보다 늦어야 합니다.'
                );
            }
        }
    }
    /** @param list<int> $currentGroupIds */
    private function validateGroupSelection(
        int $actorUserId,
        ClassSaveCommand $command,
        array $currentGroupIds
    ): void {
        $requested = $command->groupIds();
        sort($requested, SORT_NUMERIC);
        sort($currentGroupIds, SORT_NUMERIC);

        if (! $this->groupChoices->isAvailable()) {
            if ($requested !== $currentGroupIds) {
                throw new DomainException(
                    '그룹 기능이 꺼져 있어 그룹 연결을 변경할 수 없습니다.'
                );
            }
        } else {
            $allowed = $this->groupChoices->choicesFor(
                $actorUserId,
                $currentGroupIds
            );
            foreach ($requested as $groupId) {
                if (! array_key_exists($groupId, $allowed)) {
                    throw new DomainException('연결할 수 없는 그룹입니다.');
                }
            }
        }

        if ($command->visibility() === 'group_only' && $requested === []) {
            throw new DomainException('연결 그룹 공개에는 그룹을 지정해야 합니다.');
        }
    }

    /** @param list<int> $problemIds */
    private function validateProblemSelection(
        int $actorUserId,
        array $problemIds
    ): void {
        if ($problemIds === []) {
            return;
        }
        $visible = $this->problems->findVisibleMany(
            $actorUserId,
            $problemIds
        );
        $missing = array_values(array_diff(
            $problemIds,
            array_map('intval', array_keys($visible))
        ));
        if ($missing !== []) {
            throw new DomainException(sprintf(
                '존재하지 않거나 연결할 권한이 없는 문제 번호: %s',
                implode(', ', $missing)
            ));
        }
    }

    private function assertSaveAllowed(        int $actorUserId,
        LearningClass $class,
        ClassSaveCommand $command
    ): void {
        if ($this->staff->canChangeSettings($actorUserId, $class)) {
            return;
        }
        $currentInstructors = $this->classes->instructorUserIds($class->id());
        $requestedInstructors = $command->instructorUserIds();
        sort($currentInstructors, SORT_NUMERIC);
        sort($requestedInstructors, SORT_NUMERIC);
        if (
            $command->name() !== $class->name()
            || $command->slug() !== $class->slug()
            || $command->description() !== $class->description()
            || $command->visibility() !== $class->visibility()
            || $command->requiresApproval() !== $class->requiresApproval()
            || $command->progressVisibility() !== $class->progressVisibility()
            || $command->isEnabled() !== $class->isEnabled()
            || $command->startsAt() !== $class->startsAt()
            || $command->endsAt() !== $class->endsAt()
            || $command->password() !== null
            || $command->blocklyProfileKey() !== $class->blocklyProfileKey()
            || $requestedInstructors !== $currentInstructors
        ) {
            throw new DomainException(
                '공동 강사는 참여자·문제·그룹 구성만 변경할 수 있습니다.'
            );
        }
    }
}
