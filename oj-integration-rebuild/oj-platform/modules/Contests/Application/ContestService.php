<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Application;

use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSubmissionAccess;
use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestIpChangeRequest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblem;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestReport;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestScoringPolicy;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestSettings;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Shared\Selection\GroupMembershipProvider;
use DateTimeZone;
use DomainException;
use RuntimeException;
use Throwable;

final class ContestService implements ContestSubmissionAccess
{
    private const VISIBILITIES = ['public', 'private', 'group_only'];
    private const RESULT_VISIBILITIES = ['private', 'participants', 'public'];
    private const RANKING_VISIBILITIES = ['private', 'live', 'final'];
    private const PARTIAL_POLICIES = ['none', 'pass_rate_correctness_base_v1'];
    private const BLOCKLY_PROFILES = ['intro', 'standard', 'extended'];
    private const WRONG_ANSWER_POLICIES = ['penalty_before_accept'];
    private const IP_POLICIES = ['none', 'first_access', 'managed'];
    private const MANAGER_ROLES = ['manager', 'proctor', 'reviewer'];

    public function __construct(
        private readonly ContestJudgeRepository $judge,
        private readonly ContestPlatformRepository $platform,
        private readonly ContestStaffPolicy $staff,
        private readonly WordPressUserGateway $users,
        private readonly SubmissionProblemProvider $problems,
        private readonly SubmissionProjectionProvider $submissions,
        private readonly ContestRankingCalculator $rankings,
        private readonly ContestStatisticsCalculator $statistics,
        private readonly Clock $clock,
        private readonly ChoiceProvider $groupChoices
    ) {
    }

    /** @return list<Contest> */
    public function visibleFor(int $userId): array
    {
        return array_values(array_filter(
            $this->judge->all(),
            function (Contest $contest) use ($userId): bool {
                if ($contest->isArchived()) {
                    return false;
                }
                $settings = $this->platform->settings($contest->id());
                return $settings !== null
                    && (
                        $settings->visibility() === 'public'
                        || $this->isParticipant($contest->id(), $userId, $settings)
                        || $this->canManage($userId, $contest->id(), $settings)
                    );
            }
        ));
    }

    public function homeFor(int $userId, string $now, int $limit, bool $managed): array
    {
        if (! $this->judge instanceof \CodingPanda\OjPlatform\Modules\Contests\Contract\ContestHomeRepository) {
            return array_slice(array_values(array_filter($managed ? $this->managedBy($userId) : $this->visibleFor($userId),
                static fn (Contest $contest): bool => !in_array($contest->stage($now), ['ended', 'archived'], true))), 0, $limit);
        }
        $items = []; $cursor = null;
        $all = $managed && $this->staff->isGlobalManager($userId);
        $managedIds = $managed && !$all ? array_flip($this->platform->managedContestIds($userId)) : [];
        do {
            $batch = $this->judge->homeCandidates($now, $cursor, 50);
            $ids = array_map(static fn (Contest $contest): int => $contest->id(), $batch);
            $settingsMap = $this->platform instanceof \CodingPanda\OjPlatform\Modules\Contests\Contract\ContestSettingsBatchRepository
                ? $this->platform->settingsMany($ids) : null;
            foreach ($batch as $contest) {
                $cursor = $contest->id();
                $settings = $settingsMap === null ? $this->platform->settings($contest->id()) : ($settingsMap[$contest->id()] ?? null);
                $allowed = $managed ? ($all || isset($managedIds[$contest->id()]))
                    : ($settings !== null && ($settings->visibility() === 'public'
                        || $this->isParticipant($contest->id(), $userId, $settings) || $this->canManage($userId, $contest->id(), $settings)));
                if ($allowed) { $items[] = $contest; }
                if (count($items) >= $limit) { return $items; }
            }
        } while (count($batch) === 50);
        return $items;
    }

    /** @return list<Contest> */
    public function managedBy(int $userId): array
    {
        $all = $this->staff->isGlobalManager($userId);
        $managedIds = array_flip($this->platform->managedContestIds($userId));

        return array_values(array_filter(
            $this->judge->all(),
            static fn (Contest $contest): bool =>
                $all || isset($managedIds[$contest->id()])
        ));
    }

    /** @return list<string> */
    public function groupFeatureBlockers(): array
    {
        $blockers = [];
        foreach ($this->judge->all() as $contest) {
            if ($contest->isArchived()) {
                continue;
            }
            $settings = $this->platform->settings($contest->id());
            if ($settings === null || (
                $settings->visibility() !== 'group_only'
                && $settings->groupId() === null
            )) {
                continue;
            }
            $blockers[] = sprintf('대회 #%d %s', $contest->id(), $contest->title());
        }

        return $blockers;
    }

    public function findVisible(int $contestId, int $userId): Contest
    {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        if (
            $contest->isArchived()
            || (
                $settings->visibility() !== 'public'
                && ! $this->isParticipant($contestId, $userId, $settings)
                && ! $this->staff->canConfigure($userId, $contestId, $settings)
            )
        ) {
            throw new DomainException('접근할 수 있는 대회가 아닙니다.');
        }

        return $contest;
    }

    public function findManaged(int $contestId, int $userId): Contest
    {
        $contest = $this->requireContest($contestId);
        if (! $this->canManage(
            $userId,
            $contestId,
            $this->requireSettings($contestId)
        )) {
            throw new DomainException('이 대회를 관리할 권한이 없습니다.');
        }

        return $contest;
    }

    public function settings(int $contestId): ContestSettings
    {
        return $this->requireSettings($contestId);
    }

    /** @return list<ContestProblem> */
    public function problems(int $contestId): array
    {
        return $this->contestProblems($contestId);
    }

    /** @return list<int> */
    public function participantUserIds(
        int $contestId,
        string $status = 'active'
    ): array {
        $ids = $this->platform->participantUserIds($contestId, $status);
        $settings = $this->platform->settings($contestId);
        if ($status === 'active' && $settings !== null) {
            $ids = array_merge($ids, $this->linkedGroupUserIds($settings));
        }

        return array_values(array_unique($ids));
    }

    public function participationStatus(int $contestId, int $userId): ?string
    {
        $status = $this->platform->participantStatus($contestId, $userId);
        if ($status === 'active') {
            return $status;
        }
        $settings = $this->platform->settings($contestId);
        if (
            $settings !== null
            && $this->isLinkedGroupMember($settings, $userId)
        ) {
            return 'group';
        }

        return $status;
    }

    /** @return array<int, string> */
    public function managerRoles(int $contestId): array
    {
        return $this->platform->managerRoles($contestId);
    }

    /** @return array<string, string> */
    public function managerRoleLogins(int $contestId): array
    {
        $roles = $this->platform->managerRoles($contestId);
        $identities = $this->users->findMany(array_keys($roles));
        $result = [];
        foreach ($identities as $identity) {
            $role = $roles[$identity->userId()] ?? null;
            if ($role !== null) {
                $result[$identity->userLogin()] = $role;
            }
        }

        return $result;
    }

    public function userIdByLogin(string $userLogin): int
    {
        $identity = $this->users->findByLogin(trim($userLogin));
        if ($identity === null) {
            throw new DomainException('로그인 아이디와 일치하는 사용자를 찾을 수 없습니다.');
        }

        return $identity->userId();
    }

    /** @return list<UserIdentity> */
    public function participantIdentities(int $contestId, string $status = 'active'): array
    {
        return $this->users->findMany(
            $this->participantUserIds($contestId, $status)
        );
    }

    public function managedAllowedIp(
        int $actorUserId,
        int $contestId,
        int $targetUserId
    ): ?string {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        if (! $this->staff->canManageParticipants(
            $actorUserId,
            $contestId,
            $contest,
            $settings,
            $this->now()
        )) {
            throw new DomainException('허용 IP를 확인할 권한이 없습니다.');
        }

        return $this->platform->allowedIp($contestId, $targetUserId);
    }
    public function save(
        int $actorUserId,
        string $actorLogin,
        ContestSaveCommand $command
    ): int {
        $this->validateCommand($command);
        $new = $command->contestId() < 1;
        $beforeContest = null;
        $beforeSettings = null;
        $beforeProblems = [];
        $beforeManagers = [];
        if ($new) {
            if (! $this->staff->canCreate($actorUserId)) {
                throw new DomainException('대회를 만들 권한이 없습니다.');
            }
            $ownerUserId = $actorUserId;
            $currentGroupId = null;
        } else {
            $beforeContest = $this->findManaged(
                $command->contestId(),
                $actorUserId
            );
            $beforeSettings = $this->requireSettings($command->contestId());
            $beforeProblems = $this->contestProblems($command->contestId());
            $beforeManagers = $this->platform->managerRoles(
                $command->contestId()
            );
            $this->assertSaveAllowed(
                $actorUserId,
                $beforeContest,
                $beforeSettings,
                $beforeProblems,
                $beforeManagers,
                $command
            );
            $ownerUserId = $beforeSettings->ownerUserId();
            $currentGroupId = $beforeSettings->groupId();
        }
        $this->validateGroupSelection(
            $actorUserId,
            $command,
            $currentGroupId
        );
        $owner = $this->users->findById($ownerUserId);
        $ownerLogin = $owner?->userLogin() ?? ($new ? $actorLogin : '');
        if ($ownerLogin === '') {
            throw new DomainException('대회 소유자 계정을 찾을 수 없습니다.');
        }
        $this->validateProblemSelection(
            $actorUserId,
            $command->problemIds()
        );
        $contestId = $this->judge->save($command, $ownerLogin);
        try {
            $this->platform->saveSettings(
                $contestId,
                $ownerUserId,
                $command
            );
        } catch (Throwable $error) {
            try {
                if ($new) {
                    $this->judge->remove($contestId);
                } elseif (
                    $beforeContest instanceof Contest
                    && $beforeSettings instanceof ContestSettings
                ) {
                    $this->judge->save(
                        $this->snapshotCommand(
                            $beforeContest,
                            $beforeSettings,
                            $beforeProblems,
                            $beforeManagers
                        ),
                        $ownerLogin
                    );
                }
            } catch (Throwable $rollbackError) {
                throw new RuntimeException(
                    '대회 저장을 되돌리지 못했습니다. 운영 확인이 필요합니다.',
                    0,
                    $rollbackError
                );
            }
            throw $error;
        }

        return $contestId;
    }

    public function startNow(
        int $actorUserId,
        string $actorLogin,
        int $contestId
    ): void {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        if (! $this->staff->canConfigure($actorUserId, $contestId, $settings)) {
            throw new DomainException('대회를 시작할 권한이 없습니다.');
        }
        $now = $this->now();
        if ($contest->stage($now) !== 'preparation') {
            throw new DomainException('준비 단계의 대회만 즉시 시작할 수 있습니다.');
        }
        $this->save(
            $actorUserId,
            $actorLogin,
            $this->snapshotCommand(
                $contest,
                $settings,
                $this->contestProblems($contestId),
                $this->platform->managerRoles($contestId),
                $now
            )
        );
    }

    public function participation(
        int $actorUserId,
        int $contestId,
        string $operation,
        int $targetUserId
    ): void {
        $contest = $this->requireContest($contestId);
        if (
            $targetUserId < 1
            || $this->users->findById($targetUserId) === null
        ) {
            throw new DomainException('대상 사용자를 찾을 수 없습니다.');
        }
        if (in_array($operation, ['request', 'withdraw'], true)) {
            if ($actorUserId !== $targetUserId) {
                throw new DomainException('본인의 대회 참여 상태만 변경할 수 있습니다.');
            }
            $currentStatus = $this->platform->participantStatus($contestId, $targetUserId);
            if ($currentStatus === 'removed') {
                throw new DomainException('참가가 제한된 대회입니다. 대회 담당자에게 문의해 주세요.');
            }
            if ($operation === 'request') {
                $settings = $this->requireSettings($contestId);
                if ($contest->isArchived() || $settings->visibility() !== 'public') {
                    throw new DomainException('이 대회에는 직접 참여할 수 없습니다.');
                }
                if ($currentStatus === 'active') { return; }
                $status = $settings->requiresApproval() ? 'pending' : 'active';
            } else {
                $status = 'withdrawn';
            }
        } else {
            $settings = $this->requireSettings($contestId);
            if (! $this->staff->canManageParticipants(
                $actorUserId,
                $contestId,
                $contest,
                $settings,
                $this->now()
            )) {
                throw new DomainException('대회 참가자를 관리할 권한이 없습니다.');
            }
            $status = match ($operation) {
                'add', 'approve', 'reactivate' => 'active',
                'remove' => 'removed',
                default => throw new DomainException(
                    '정의되지 않은 대회 참여 작업입니다.'
                ),
            };
        }
        if (in_array($operation, ['request', 'withdraw'], true)
            && $this->platform instanceof \CodingPanda\OjPlatform\Modules\Contests\Contract\ContestParticipantTransition) {
            if (! $this->platform->transitionParticipantStatus($contestId, $targetUserId, $currentStatus, $status, $actorUserId)) {
                throw new DomainException('참가 상태가 이미 변경되었습니다. 대회를 다시 확인해 주세요.');
            }
            return;
        }
        $this->platform->setParticipantStatus(
            $contestId,
            $targetUserId,
            $status,
            $actorUserId
        );
    }

    public function setArchived(
        int $actorUserId,
        int $contestId,
        bool $archived
    ): void {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        if (! $this->staff->canArchive($actorUserId, $settings)) {
            throw new DomainException(
                '대회 소유자 또는 전역 관리자만 보관·복원할 수 있습니다.'
            );
        }
        if ($archived) {
            $snapshot = json_encode(
                [
                    'settings' => $settings->snapshot(),
                    'managers' => $this->platform->managerRoles($contestId),
                    'problems' => array_map(
                        static fn (ContestProblem $problem): array => [
                            'problem_id' => $problem->problemId(),
                            'number' => $problem->number(),
                            'score' => $problem->score(),
                        ],
                        $this->contestProblems($contestId)
                    ),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: '{}';
            $this->platform->storeDeletedSnapshot(
                $contestId,
                $contest->title(),
                $snapshot,
                $actorUserId
            );
            try {
                $this->judge->setArchived($contestId, true);
            } catch (Throwable $error) {
                $this->platform->removeDeletedSnapshot($contestId);
                throw $error;
            }

            return;
        }
        $this->judge->setArchived($contestId, false);
        try {
            $this->platform->removeDeletedSnapshot($contestId);
        } catch (Throwable $error) {
            $this->judge->setArchived($contestId, true);
            throw $error;
        }
    }
    public function report(
        int $actorUserId,
        string $actorLogin,
        int $contestId,
        bool $ownOnly = false,
        bool $ranking = false,
        bool $statistics = false
    ): ContestReport {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        $staffView = $this->staff->canViewReports(
            $actorUserId,
            $contestId,
            $contest,
            $settings,
            $this->now()
        );
        $participant = $this->isParticipant($contestId, $actorUserId, $settings);
        if (! $staffView) {
            if (
                $statistics
                && ! $this->resultReportVisible($contest, $settings, $participant)
            ) {
                throw new DomainException('대회 결과 통계가 아직 공개되지 않았습니다.');
            }
            if (! $statistics && ! $participant) {
                throw new DomainException('대회 보고서를 볼 권한이 없습니다.');
            }
            if ($ranking && ! $this->rankingVisible($contest, $settings)) {
                throw new DomainException('대회 순위가 아직 공개되지 않았습니다.');
            }
        }
        $participantIds = $ownOnly
            ? [$actorUserId]
            : $this->participantUserIds($contestId);
        $participants = $this->users->findMany($participantIds);
        $logins = array_map(
            static fn (UserIdentity $identity): string => $identity->userLogin(),
            $participants
        );
        if ($ownOnly && $logins === []) {
            $logins = [$actorLogin];
        }
        $problems = $this->contestProblems($contestId);
        $query = new SubmissionProjectionQuery(
            $logins,
            array_map(
                static fn (ContestProblem $problem): int => $problem->problemId(),
                $problems
            ),
            $contestId
        );
        $projections = ($staffView || ! $ownOnly)
            ? $this->submissions->reportAll($query)
            : $this->submissions->searchAll($actorUserId, $actorLogin, $query);
        $freezeCutoff = null;
        if (! $staffView && ($ranking || $statistics)) {
            $freezeCutoff = $this->rankingFreezeCutoff($contest, $settings);
            if ($freezeCutoff !== null) {
                $projections = array_values(array_filter(
                    $projections,
                    static fn (SubmissionProjection $projection): bool =>
                        $projection->submittedAt() <= $freezeCutoff
                ));
            }
        }
        $rankingRows = $this->rankings->calculate(
            $contest,
            $settings,
            $participants,
            $problems,
            $projections
        );

        return new ContestReport(
            $contest,
            $settings,
            $participants,
            $problems,
            $projections,
            $rankingRows,
            $this->statistics->calculate(
                $participants,
                $problems,
                $projections,
                $rankingRows
            ),
            $freezeCutoff
        );
    }

    public function exportReport(
        int $actorUserId,
        string $actorLogin,
        int $contestId
    ): ContestReport {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        if (! $this->staff->canExport(
            $actorUserId,
            $contestId,
            $contest,
            $settings,
            $this->now()
        )) {
            throw new DomainException('대회 결과를 내보낼 권한이 없습니다.');
        }

        return $this->report(
            $actorUserId,
            $actorLogin,
            $contestId,
            false,
            true
        );
    }
    public function allowedIp(int $actorUserId, int $contestId): ?string
    {
        $settings = $this->requireSettings($contestId);
        if (! $this->isParticipant($contestId, $actorUserId, $settings)) {
            throw new DomainException('대회 참여자만 허용 IP를 확인할 수 있습니다.');
        }

        return $this->platform->allowedIp($contestId, $actorUserId);
    }

    public function pendingIpChangeRequest(
        int $actorUserId,
        int $contestId
    ): ?ContestIpChangeRequest {
        $settings = $this->requireSettings($contestId);
        if (! $this->isParticipant($contestId, $actorUserId, $settings)) {
            throw new DomainException('대회 참여자만 IP 변경 요청을 확인할 수 있습니다.');
        }

        return $this->platform->pendingIpChangeRequest(
            $contestId,
            $actorUserId
        );
    }

    /** @return list<ContestIpChangeRequest> */
    public function managedIpChangeRequests(
        int $actorUserId,
        int $contestId
    ): array {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        if (! $this->staff->canManageParticipants(
            $actorUserId,
            $contestId,
            $contest,
            $settings,
            $this->now()
        )) {
            throw new DomainException('IP 변경 요청을 확인할 권한이 없습니다.');
        }

        return $this->platform->pendingIpChangeRequests($contestId);
    }

    public function submitAllowedIpChange(
        int $actorUserId,
        int $contestId,
        string $ipAddress,
        string $reason
    ): string {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        if (! $this->isParticipant($contestId, $actorUserId, $settings)) {
            throw new DomainException('대회 참여자만 IP 변경을 요청할 수 있습니다.');
        }
        if (
            $contest->isArchived()
            || $contest->stage($this->now()) === 'ended'
            || $settings->ipPolicy() === 'none'
        ) {
            throw new DomainException('현재 대회에서는 IP 변경을 요청할 수 없습니다.');
        }
        $ipAddress = trim($ipAddress);
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new DomainException('현재 접속 IP 주소를 확인할 수 없습니다.');
        }
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 500) {
            throw new DomainException('IP 변경 사유를 500자 이내로 입력해 주세요.');
        }
        $previousIp = $this->platform->allowedIp($contestId, $actorUserId);
        if ($previousIp !== null && hash_equals($previousIp, $ipAddress)) {
            throw new DomainException('현재 접속 IP는 이미 허용되어 있습니다.');
        }
        if (
            $settings->ipPolicy() === 'first_access'
            && $previousIp === null
        ) {
            $this->platform->setAllowedIp(
                $contestId,
                $actorUserId,
                $ipAddress
            );
            $this->platform->logIp(
                $contestId,
                $actorUserId,
                $ipAddress,
                'self_register',
                ['reason' => $reason]
            );

            return 'registered';
        }
        if (
            $this->platform->pendingIpChangeRequest(
                $contestId,
                $actorUserId
            ) !== null
        ) {
            throw new DomainException('처리 중인 IP 변경 요청이 이미 있습니다.');
        }
        $this->platform->createIpChangeRequest(
            $contestId,
            $actorUserId,
            $ipAddress,
            $previousIp,
            $reason
        );

        return 'requested';
    }

    public function resolveAllowedIpChange(
        int $actorUserId,
        int $contestId,
        int $requestId,
        bool $approved
    ): void {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        if (! $this->staff->canManageParticipants(
            $actorUserId,
            $contestId,
            $contest,
            $settings,
            $this->now()
        )) {
            throw new DomainException('IP 변경 요청을 처리할 권한이 없습니다.');
        }
        $request = $this->platform->ipChangeRequest($requestId);
        if (
            $request === null
            || $request->contestId() !== $contestId
            || ! $this->platform->isActiveParticipant(
                $contestId,
                $request->userId()
            )
        ) {
            throw new DomainException('처리할 IP 변경 요청을 찾을 수 없습니다.');
        }
        $this->platform->resolveIpChangeRequest(
            $request,
            $approved,
            $actorUserId
        );
    }

    public function changeAllowedIp(
        int $actorUserId,
        int $contestId,
        int $targetUserId,
        ?string $ipAddress,
        bool $admin
    ): void {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        if ($admin) {
            if (! $this->staff->canManageParticipants(
                $actorUserId,
                $contestId,
                $contest,
                $settings,
                $this->now()
            )) {
                throw new DomainException('허용 IP를 관리할 권한이 없습니다.');
            }
        } elseif (
            $actorUserId !== $targetUserId
            || $settings->ipPolicy() !== 'first_access'
            || $this->platform->allowedIp($contestId, $targetUserId) !== null
        ) {
            throw new DomainException('허용 IP를 직접 변경할 수 없습니다.');
        }
        if (
            $ipAddress !== null
            && filter_var($ipAddress, FILTER_VALIDATE_IP) === false
        ) {
            throw new DomainException('IP 주소 형식이 올바르지 않습니다.');
        }
        $this->platform->setAllowedIp($contestId, $targetUserId, $ipAddress);
        $this->platform->logIp(
            $contestId,
            $targetUserId,
            $ipAddress ?? '0.0.0.0',
            $admin ? 'admin_change' : 'self_register',
            ['actor_user_id' => $actorUserId]
        );
    }

    public function canViewProblem(
        int $userId,
        int $contestId,
        int $problemId,
        ?int $problemNumber
    ): bool {
        $contest = $this->judge->find($contestId);
        $settings = $this->platform->settings($contestId);
        if ($contest === null || $settings === null || $contest->isArchived()) {
            return false;
        }
        $staff = $this->canManage($userId, $contestId, $settings);
        if (! $staff) {
            if (! $this->isParticipant($contestId, $userId, $settings)) {
                return false;
            }
            if ($contest->stage($this->now()) === 'preparation') {
                return false;
            }
        }
        foreach ($this->contestProblems($contestId) as $problem) {
            if (
                $problem->problemId() === $problemId
                && (
                    $problemNumber === null
                    || $problem->number() === $problemNumber
                )
            ) {
                return true;
            }
        }

        return false;
    }

    public function validateSubmission(
        int $userId,
        int $contestId,
        int $problemId,
        int $problemNumber,
        string $ipAddress
    ): int {
        $contest = $this->requireContest($contestId);
        $settings = $this->requireSettings($contestId);
        if ($contest->stage($this->now()) !== 'running') {
            throw new DomainException('현재 제출할 수 있는 대회가 아닙니다.');
        }
        if (
            ! $this->isParticipant($contestId, $userId, $settings)
            && ! $this->staff->canConfigure($userId, $contestId, $settings)
        ) {
            throw new DomainException('대회 참여자만 제출할 수 있습니다.');
        }
        $resolvedNumber = null;
        foreach ($this->contestProblems($contestId) as $problem) {
            if (
                $problem->problemId() === $problemId
                && ($problemNumber < 0 || $problem->number() === $problemNumber)
            ) {
                $resolvedNumber = $problem->number();
                break;
            }
        }
        if ($resolvedNumber === null) {
            throw new DomainException('대회에 포함된 문제가 아닙니다.');
        }
        $this->validateIp($userId, $contestId, $ipAddress, $settings);

        return $resolvedNumber;
    }

    public function submissionBlocklyProfile(int $contestId): ?string
    {
        return $this->requireSettings($contestId)->blocklyProfileKey();
    }
    public function allowsAiFeedback(int $contestId): bool
    {
        $contest = $this->judge->find($contestId);
        $settings = $this->platform->settings($contestId);

        return $contest !== null
            && $settings !== null
            && ! $contest->isArchived()
            && $settings->allowsAiFeedback();
    }
    public function canViewSubmission(int $actorUserId, int $contestId): bool
    {
        $contest = $this->judge->find($contestId);
        $settings = $this->platform->settings($contestId);

        return $contest !== null
            && $settings !== null
            && $this->staff->canViewReports(
                $actorUserId,
                $contestId,
                $contest,
                $settings,
                $this->now()
            );
    }

    public function canCreate(int $userId): bool
    {
        return $this->staff->canCreate($userId);
    }

    public function canConfigure(
        int $userId,
        int $contestId,
        ContestSettings $settings
    ): bool {
        return $this->staff->canConfigure($userId, $contestId, $settings);
    }

    public function canChangeManagers(
        int $userId,
        ContestSettings $settings
    ): bool {
        return $this->staff->canChangeManagers($userId, $settings);
    }

    public function canArchive(int $userId, ContestSettings $settings): bool
    {
        return $this->staff->canArchive($userId, $settings);
    }

    public function currentStage(Contest $contest): string
    {
        return $contest->stage($this->now());
    }

    public function canAccessConsoleRoute(
        int $userId,
        int $contestId,
        string $routeName
    ): bool {
        $contest = $this->judge->find($contestId);
        $settings = $this->platform->settings($contestId);
        if ($contest === null || $settings === null) {
            return false;
        }
        if ($routeName === 'console.contests.edit') {
            return $this->staff->canConfigure($userId, $contestId, $settings);
        }
        if ($routeName === 'console.contests.delete') {
            return $this->staff->canArchive($userId, $settings);
        }
        if ($routeName === 'console.contests.ip_check') {
            return $this->staff->canManageParticipants(
                $userId,
                $contestId,
                $contest,
                $settings,
                $this->now()
            );
        }
        if (in_array($routeName, [
            'console.contests.submissions',
            'console.contests.ranking',
            'console.contests.statistics',
        ], true)) {
            return $this->staff->canViewReports(
                $userId,
                $contestId,
                $contest,
                $settings,
                $this->now()
            );
        }

        return $this->staff->canOpenConsole($userId, $contestId, $settings);
    }
    private function canManage(
        int $userId,
        int $contestId,
        ContestSettings $settings
    ): bool {
        return $this->staff->canOpenConsole($userId, $contestId, $settings);
    }

    private function isParticipant(
        int $contestId,
        int $userId,
        ContestSettings $settings
    ): bool {
        return $this->platform->isActiveParticipant($contestId, $userId)
            || $this->isLinkedGroupMember($settings, $userId);
    }

    private function isLinkedGroupMember(
        ContestSettings $settings,
        int $userId
    ): bool {
        $groupId = $settings->groupId();
        if (
            $settings->visibility() !== 'group_only'
            || $groupId === null
            || ! ($this->groupChoices instanceof GroupMembershipProvider)
        ) {
            return false;
        }

        return in_array(
            $groupId,
            $this->groupChoices->activeGroupIdsForUser($userId),
            true
        );
    }

    /** @return list<int> */
    private function linkedGroupUserIds(ContestSettings $settings): array
    {
        $groupId = $settings->groupId();
        if (
            $settings->visibility() !== 'group_only'
            || $groupId === null
            || ! ($this->groupChoices instanceof GroupMembershipProvider)
        ) {
            return [];
        }

        return $this->groupChoices->activeUserIdsForGroups([$groupId]);
    }


    private function rankingVisible(
        Contest $contest,
        ContestSettings $settings
    ): bool {
        return $settings->rankingVisibility() === 'live'
            || (
                $settings->rankingVisibility() === 'final'
                && $contest->stage($this->now()) === 'ended'
            );
    }
    private function resultReportVisible(
        Contest $contest,
        ContestSettings $settings,
        bool $participant
    ): bool {
        return match ($settings->resultVisibility()) {
            'participants' => $participant,
            'public' => $participant || $settings->visibility() === 'public',
            default => false,
        };
    }

    private function rankingFreezeCutoff(
        Contest $contest,
        ContestSettings $settings
    ): ?string {
        if (
            $settings->freezeMinutes() < 1
            || $contest->stage($this->now()) !== 'running'
        ) {
            return null;
        }
        $now = $this->clock->now();
        $endsAt = new \DateTimeImmutable(
            $contest->endsAt(),
            $now->getTimezone()
        );
        $cutoff = $endsAt->modify('-' . $settings->freezeMinutes() . ' minutes');
        if ($now < $cutoff) {
            return null;
        }

        return $cutoff
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }


    private function validateIp(
        int $userId,
        int $contestId,
        string $ipAddress,
        ContestSettings $settings
    ): void {
        if ($settings->ipPolicy() === 'none') {
            return;
        }
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new DomainException('제출 IP 주소를 확인할 수 없습니다.');
        }
        $allowed = $this->platform->allowedIp($contestId, $userId);
        if ($settings->ipPolicy() === 'first_access' && $allowed === null) {
            $this->platform->setAllowedIp($contestId, $userId, $ipAddress);
            $this->platform->logIp(
                $contestId,
                $userId,
                $ipAddress,
                'first_submission'
            );
            return;
        }
        if ($allowed === null || ! hash_equals($allowed, $ipAddress)) {
            $this->platform->logIp(
                $contestId,
                $userId,
                $ipAddress,
                'submission_denied'
            );
            throw new DomainException('허용된 IP 주소에서만 제출할 수 있습니다.');
        }
    }

    private function requireContest(int $contestId): Contest
    {
        $contest = $contestId > 0 ? $this->judge->find($contestId) : null;
        if ($contest === null) {
            throw new DomainException('대회를 찾을 수 없습니다.');
        }

        return $contest;
    }

    private function requireSettings(int $contestId): ContestSettings
    {
        $settings = $this->platform->settings($contestId);
        if ($settings === null) {
            throw new DomainException('대회 운영 설정을 찾을 수 없습니다.');
        }

        return $settings;
    }

    /**
     * @param list<ContestProblem> $problems
     * @param array<int, string> $managers
     */
    private function assertSaveAllowed(
        int $actorUserId,
        Contest $contest,
        ContestSettings $settings,
        array $problems,
        array $managers,
        ContestSaveCommand $command
    ): void {
        $stage = $contest->stage($this->now());
        if ($stage === 'archived') {
            throw new DomainException('보관된 대회는 복원한 뒤 수정해 주세요.');
        }
        $current = $this->currentState(
            $contest,
            $settings,
            $problems,
            $managers
        );
        $requested = $this->requestedState($command);
        if ($stage === 'preparation') {
            if (! $this->staff->canConfigure(
                $actorUserId,
                $contest->id(),
                $settings
            )) {
                throw new DomainException('대회 설정을 변경할 권한이 없습니다.');
            }
            if (
                ! $this->staff->canChangeManagers($actorUserId, $settings)
                && $requested['manager_roles'] !== $current['manager_roles']
            ) {
                throw new DomainException(
                    '대회 소유자 또는 전역 관리자만 담당자를 변경할 수 있습니다.'
                );
            }

            return;
        }
        if ($stage === 'running') {
            if (! $this->staff->canConfigure(
                $actorUserId,
                $contest->id(),
                $settings
            )) {
                throw new DomainException('진행 중 대회 일정을 변경할 권한이 없습니다.');
            }
            $this->assertOnlyChanged(
                $current,
                $requested,
                ['ends_at'],
                '진행 중에는 종료 시각 연장만 가능합니다.'
            );
            if ($command->endsAt() <= $contest->endsAt()) {
                throw new DomainException(
                    '진행 중 대회의 종료 시각은 현재보다 늦게만 연장할 수 있습니다.'
                );
            }

            return;
        }
        if (! $this->staff->canConfigure(
            $actorUserId,
            $contest->id(),
            $settings
        )) {
            throw new DomainException('종료 대회의 공개 범위를 변경할 권한이 없습니다.');
        }
        $this->assertOnlyChanged(
            $current,
            $requested,
            ['result_visibility', 'ranking_visibility'],
            '종료 뒤에는 결과와 순위 공개 범위만 변경할 수 있습니다.'
        );
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $requested
     * @param list<string> $allowedKeys
     */
    private function assertOnlyChanged(
        array $current,
        array $requested,
        array $allowedKeys,
        string $message
    ): void {
        foreach ($current as $key => $value) {
            if (in_array($key, $allowedKeys, true)) {
                continue;
            }
            if (($requested[$key] ?? null) !== $value) {
                throw new DomainException($message);
            }
        }
    }

    /**
     * @param list<ContestProblem> $problems
     * @param array<int, string> $managers
     * @return array<string, mixed>
     */
    private function currentState(
        Contest $contest,
        ContestSettings $settings,
        array $problems,
        array $managers
    ): array {
        $managerRoles = $managers;
        ksort($managerRoles, SORT_NUMERIC);
        $problemIds = [];
        $problemScores = [];
        foreach ($problems as $problem) {
            $problemIds[] = $problem->problemId();
            $problemScores[$problem->problemId()] = $problem->score();
        }

        return [
            'title' => $contest->title(),
            'description' => $contest->description(),
            'starts_at' => $contest->startsAt(),
            'ends_at' => $contest->endsAt(),
            'visibility' => $settings->visibility(),
            'approval_required' => $settings->requiresApproval(),
            'group_id' => $settings->groupId(),
            'result_visibility' => $settings->resultVisibility(),
            'ranking_visibility' => $settings->rankingVisibility(),
            'wrong_penalty_points' => $settings->wrongPenaltyPoints(),
            'time_score_percent' => $settings->timeScorePercent(),
            'partial_score_policy' => $settings->partialScorePolicy(),
            'scoring_policy' => $settings->scoringPolicy(),
            'ranking_policy' => $settings->rankingPolicy(),
            'freeze_minutes' => $settings->freezeMinutes(),
            'blockly_profile_key' => $settings->blocklyProfileKey(),
            'allow_ai_feedback' => $settings->allowsAiFeedback(),
            'wrong_answer_policy' => $settings->wrongAnswerPolicy(),
            'ip_policy' => $settings->ipPolicy(),
            'problem_ids' => $problemIds,
            'problem_scores' => $problemScores,
            'manager_roles' => $managerRoles,
        ];
    }

    /** @return array<string, mixed> */
    private function requestedState(ContestSaveCommand $command): array
    {
        $managerRoles = $command->managerRoles();
        ksort($managerRoles, SORT_NUMERIC);
        $problemScores = [];
        foreach ($command->problemIds() as $problemId) {
            $problemScores[$problemId] = (float) (
                $command->problemScores()[$problemId] ?? 100
            );
        }

        return [
            'title' => $command->title(),
            'description' => $command->description(),
            'starts_at' => $command->startsAt(),
            'ends_at' => $command->endsAt(),
            'visibility' => $command->visibility(),
            'approval_required' => $command->requiresApproval(),
            'group_id' => $command->groupId(),
            'result_visibility' => $command->resultVisibility(),
            'ranking_visibility' => $command->rankingVisibility(),
            'wrong_penalty_points' => $command->wrongPenaltyPoints(),
            'time_score_percent' => $command->timeScorePercent(),
            'partial_score_policy' => $command->partialScorePolicy(),
            'scoring_policy' => $command->scoringPolicy(),
            'ranking_policy' => $command->rankingPolicy(),
            'freeze_minutes' => $command->freezeMinutes(),
            'blockly_profile_key' => $command->blocklyProfileKey(),
            'allow_ai_feedback' => $command->allowAiFeedback(),
            'wrong_answer_policy' => $command->wrongAnswerPolicy(),
            'ip_policy' => $command->ipPolicy(),
            'problem_ids' => $command->problemIds(),
            'problem_scores' => $problemScores,
            'manager_roles' => $managerRoles,
        ];
    }

    /**
     * @param list<ContestProblem> $problems
     * @param array<int, string> $managers
     */
    private function snapshotCommand(
        Contest $contest,
        ContestSettings $settings,
        array $problems,
        array $managers,
        ?string $startsAt = null,
        ?string $endsAt = null
    ): ContestSaveCommand {
        $problemIds = [];
        $problemScores = [];
        foreach ($problems as $problem) {
            $problemIds[] = $problem->problemId();
            $problemScores[$problem->problemId()] = $problem->score();
        }

        return new ContestSaveCommand(
            $contest->id(),
            $contest->title(),
            $contest->description(),
            $startsAt ?? $contest->startsAt(),
            $endsAt ?? $contest->endsAt(),
            $settings->visibility(),
            $settings->groupId(),
            $settings->resultVisibility(),
            $settings->rankingVisibility(),
            $settings->wrongPenaltyPoints(),
            $settings->timeScorePercent(),
            $settings->partialScorePolicy(),
            $settings->scoringPolicy(),
            $settings->rankingPolicy(),
            $settings->freezeMinutes(),
            $settings->blocklyProfileKey(),
            $settings->allowsAiFeedback(),
            $settings->wrongAnswerPolicy(),
            $settings->ipPolicy(),
            $problemIds,
            $problemScores,
            $managers,
            $settings->requiresApproval()
        );
    }

    /** @return list<ContestProblem> */
    private function contestProblems(int $contestId): array
    {
        $scores = $this->platform->problemScores($contestId);

        return array_map(
            static fn (ContestProblem $problem): ContestProblem =>
                new ContestProblem(
                    $problem->problemId(),
                    $problem->number(),
                    $scores[$problem->problemId()] ?? $problem->score()
                ),
            $this->judge->problems($contestId)
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
    private function validateGroupSelection(
        int $actorUserId,
        ContestSaveCommand $command,
        ?int $currentGroupId
    ): void {
        $requested = $command->groupId();
        if (! $this->groupChoices->isAvailable()) {
            if ($requested !== $currentGroupId) {
                throw new DomainException(
                    '그룹 기능이 꺼져 있어 그룹 연결을 변경할 수 없습니다.'
                );
            }

            return;
        }
        if ($requested === null) {
            return;
        }
        $included = $currentGroupId === null ? [] : [$currentGroupId];
        if (! array_key_exists(
            $requested,
            $this->groupChoices->choicesFor($actorUserId, $included)
        )) {
            throw new DomainException('연결할 수 없는 그룹입니다.');
        }
    }

    /** @param list<int> $problemIds */
    private function validateProblemSelection(
        int $actorUserId,
        array $problemIds
    ): void {
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

    private function validateCommand(ContestSaveCommand $command): void    {
        if ($command->title() === '') {
            throw new DomainException('대회 이름을 입력해 주세요.');
        }
        if (
            strtotime($command->startsAt()) === false
            || strtotime($command->endsAt()) === false
            || $command->startsAt() >= $command->endsAt()
        ) {
            throw new DomainException('대회 시작·종료 시각이 올바르지 않습니다.');
        }
        if (! in_array($command->visibility(), self::VISIBILITIES, true)) {
            throw new DomainException('대회 공개 범위가 올바르지 않습니다.');
        }
        if ($command->visibility() === 'group_only' && $command->groupId() === null) {
            throw new DomainException('연결 그룹 공개에는 그룹을 지정해야 합니다.');
        }
        if (! in_array(
            $command->resultVisibility(),
            self::RESULT_VISIBILITIES,
            true
        )) {
            throw new DomainException('결과 공개 설정이 올바르지 않습니다.');
        }
        if (! in_array(
            $command->rankingVisibility(),
            self::RANKING_VISIBILITIES,
            true
        )) {
            throw new DomainException('순위 공개 설정이 올바르지 않습니다.');
        }
        if (! in_array(
            $command->scoringPolicy(),
            ContestScoringPolicy::scoringPolicies(),
            true
        )) {
            throw new DomainException('대회 채점 방식이 올바르지 않습니다.');
        }
        if (! in_array(
            $command->partialScorePolicy(),
            self::PARTIAL_POLICIES,
            true
        )) {
            throw new DomainException('부분 점수 방식이 올바르지 않습니다.');
        }
        if (! ContestScoringPolicy::isValidPair(
            $command->scoringPolicy(),
            $command->rankingPolicy(),
        )) {
            throw new DomainException(
                '채점 방식과 순위 계산 정책이 일치하지 않습니다.'
            );
        }
        if (
            $command->blocklyProfileKey() !== null
            && ! in_array(
                $command->blocklyProfileKey(),
                self::BLOCKLY_PROFILES,
                true
            )
        ) {
            throw new DomainException('Blockly 도구 모음 설정이 올바르지 않습니다.');
        }
        if (! in_array(
            $command->wrongAnswerPolicy(),
            self::WRONG_ANSWER_POLICIES,
            true
        )) {
            throw new DomainException('오답 처리 정책이 올바르지 않습니다.');
        }
        if ($command->wrongPenaltyPoints() < 0) {
            throw new DomainException('오답 감점은 0 이상이어야 합니다.');
        }
        if ($command->freezeMinutes() < 0) {
            throw new DomainException('순위 동결 시간은 0 이상이어야 합니다.');
        }
        if (
            $command->timeScorePercent() < 1
            || $command->timeScorePercent() > 30
        ) {
            throw new DomainException('시간 점수 비율은 1~30%여야 합니다.');
        }
        if (! in_array($command->ipPolicy(), self::IP_POLICIES, true)) {
            throw new DomainException('IP 확인 방식이 올바르지 않습니다.');
        }
        if ($command->problemIds() === []) {
            throw new DomainException('대회 문제를 한 개 이상 추가해 주세요.');
        }
        foreach ($command->problemScores() as $problemId => $score) {
            if (
                ! is_int($problemId)
                || $problemId <= 0
                || ! is_finite((float) $score)
                || (float) $score < 0
            ) {
                throw new DomainException('문제별 배점이 올바르지 않습니다.');
            }
        }
        foreach ($command->managerRoles() as $userId => $role) {
            if (! is_int($userId) || $userId <= 0) {
                throw new DomainException('대회 담당자 번호가 올바르지 않습니다.');
            }
            if (! in_array($role, self::MANAGER_ROLES, true)) {
                throw new DomainException('대회 담당자 역할이 올바르지 않습니다.');
            }
        }
    }
}
