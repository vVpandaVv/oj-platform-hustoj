<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ContestLearningHistoryEligibility;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\ContestSubmissionAiFeedbackPolicy;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\GroupsFeatureDisableGuard;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassRepository;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassSaveCommand;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassStaffPolicy;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassProblem;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassReport;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassRequestFactory;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestJudgeRepository;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestPlatformRepository;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestRankingCalculator;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestSaveCommand;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestStatisticsCalculator;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestStaffPolicy;
use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestIpChangeRequest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblem;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestScoringPolicy;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestSettings;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestHtml;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestRequestFactory;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResult;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionAiFeedbackPolicyRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Shared\Selection\GroupMembershipProvider;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$learningAccessAssertions = 0;

function learningAccessAssert(bool $condition, string $message): void
{
    global $learningAccessAssertions;
    ++$learningAccessAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function learningAccessThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DomainException) {
        learningAccessAssert(true, $message);

        return;
    }
    throw new RuntimeException($message);
}

final class LearningAccessGroups implements
    ChoiceProvider,
    GroupMembershipProvider
{
    /** @param array<int, string> $choices */
    public function __construct(
        private readonly bool $available,
        private readonly array $choices = [7 => '알고리즘 그룹']
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function choicesFor(int $userId, array $includedIds = []): array
    {
        unset($userId, $includedIds);

        return $this->available ? $this->choices : [];
    }

    public function activeGroupIdsForUser(int $userId): array
    {
        if (! $this->available) {
            return [];
        }

        return $userId === 60 ? [7] : [];
    }

    public function activeUserIdsForGroups(array $groupIds): array
    {
        if (! $this->available || ! in_array(7, $groupIds, true)) {
            return [];
        }

        return [60];
    }
}

final class LearningAccessAuthorization implements Authorization{
    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        unset($capability, $context);

        return $userId === 1;
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        unset($capabilities);

        return $userId === 1;
    }

    public function isSuperAdmin(int $userId): bool
    {
        return $userId === 1;
    }
}

final class LearningAccessUsers implements WordPressUserGateway
{
    /** @var array<int, UserIdentity> */
    private array $users = [];

    public function __construct()
    {
        foreach ([
            1 => ['root', '전역 관리자'],
            10 => ['owner', '소유자'],
            11 => ['other-owner', '다른 소유자'],
            20 => ['manager', '공동 운영자'],
            30 => ['participant', '참여자'],
            40 => ['reviewer', '결과 검토자'],
            50 => ['proctor', '시험 감독자'],
            60 => ['group-member', '그룹 구성원'],
        ] as $userId => [$login, $display]) {
            $this->users[$userId] = new UserIdentity(
                $userId,
                $login,
                $login . '@example.test',
                $display
            );
        }
    }

    public function currentUser(): ?UserIdentity { return $this->users[1]; }
    public function findById(int $userId): ?UserIdentity
    {
        return $this->users[$userId] ?? null;
    }
    public function findByLogin(string $userLogin): ?UserIdentity
    {
        foreach ($this->users as $user) {
            if ($user->userLogin() === $userLogin) {
                return $user;
            }
        }

        return null;
    }
    public function findByEmail(string $email): ?UserIdentity { return null; }
    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity
    {
        return null;
    }
    public function establishSession(int $userId, bool $remember): void {}
    public function createUser(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        string $role
    ): UserIdentity {
        throw new LogicException('사용하지 않는 테스트 경로입니다.');
    }
    public function updateUser(int $userId, string $email, string $displayName): UserIdentity
    {
        return $this->users[$userId];
    }
    public function setRoles(int $userId, array $roles): void {}
    public function setPassword(int $userId, string $password): void {}
    public function invalidateSessions(int $userId): void {}
    public function requestPasswordReset(string $userLogin): void {}
    public function resetPasswordWithKey(
        string $userLogin,
        string $resetKey,
        string $newPassword
    ): void {}
    public function deleteUser(int $userId): void {}
    public function findMany(array $userIds): array
    {
        return array_values(array_filter(array_map(
            fn (int $userId): ?UserIdentity => $this->users[$userId] ?? null,
            $userIds
        )));
    }
}

final class LearningAccessProblems implements SubmissionProblemProvider
{
    /** @var list<int> */
    public array $missingIds = [];

    public function findVisible(
        int $userId,
        int $problemId,
        ?\CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext $context = null
    ): ?SubmissionProblem
    {
        return null;
    }

    public function findVisibleMany(
        int $userId,
        array $problemIds,
        ?\CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext $context = null
    ): array
    {
        $problems = [];
        foreach ($problemIds as $problemId) {
            if (in_array($problemId, $this->missingIds, true)) {
                continue;
            }
            $problems[$problemId] = new SubmissionProblem(
                $problemId,
                '문제 ' . $problemId
            );
        }
        return $problems;
    }
    public function listVisible(string $keyword = '', int $limit = 100): array
    {
        return [];
    }

    public function codePolicy(
        int $userId,
        int $problemId,
        ?\CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext $context = null
    ): SubmissionCodePolicy
    {
        throw new LogicException('사용하지 않는 테스트 경로입니다.');
    }
}

final class LearningAccessSubmissions implements SubmissionProjectionProvider
{
    /** @var list<SubmissionProjection> */
    public array $items = [];

    public function byIds(
        int $actorUserId,
        string $actorUserLogin,
        array $submissionIds
    ): array {
        return [];
    }

    public function search(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionProjectionQuery $query
    ): array {
        return $this->items;
    }

    public function report(SubmissionProjectionQuery $query): array
    {
        return $this->items;
    }

    public function searchAll(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionProjectionQuery $query
    ): array {
        return $this->items;
    }

    public function reportAll(SubmissionProjectionQuery $query): array
    {
        return $this->items;
    }
}

final class LearningAccessClasses implements ClassRepository
{
    /** @var array<int, LearningClass> */
    public array $items;
    /** @var array<int, list<int>> */
    public array $instructors = [1 => [20]];
    /** @var array<int, list<int>> */
    public array $participants = [1 => [30]];
    /** @var list<array{int, bool}> */
    public array $lifecycle = [];
    /** @var array<int, list<ClassProblem>> */
    public array $problemItems = [];
    /** @var array<int, list<int>> */
    public array $groups = [1 => []];

    public function __construct()
    {
        $this->items = [
            1 => new LearningClass(
                1,
                '알고리즘 수업',
                'algorithm-class',
                '설명',
                'private',
                'private',
                true,
                null,
                null,
                null,
                null,
                10,
                null
            ),
            2 => new LearningClass(
                2,
                '중지 수업',
                'disabled-class',
                '',
                'public',
                'private',
                false,
                null,
                null,
                null,
                null,
                11,
                null
            ),
        ];
        $this->problemItems = [
            1 => [new ClassProblem(1000, 0, true, null, null)],
        ];
    }

    public function find(int $classId): ?LearningClass
    {
        return $this->items[$classId] ?? null;
    }
    public function visibleFor(int $userId): array { return array_values($this->items); }
    public function invitedFor(int $userId): array { return []; }
    public function linkedToGroups(array $groupIds): array
    {
        return array_values(array_filter(
            $this->items,
            fn (LearningClass $class): bool =>
                $class->visibility() === 'group_only'
                && array_intersect($groupIds, $this->groups[$class->id()] ?? []) !== []
        ));
    }
    public function enrollmentStatus(int $classId, int $userId): ?string
    {
        return in_array($userId, $this->participants[$classId] ?? [], true)
            ? 'active'
            : null;
    }
    public function managedBy(int $userId, bool $all): array
    {
        return array_values(array_filter(
            $this->items,
            fn (LearningClass $class): bool => $all
                || $class->ownerUserId() === $userId
                || in_array($userId, $this->instructors[$class->id()] ?? [], true)
        ));
    }
    public function problems(int $classId): array
    {
        return $this->problemItems[$classId] ?? [];
    }
    public function participantUserIds(int $classId, string $status = 'active'): array
    {
        return $this->participants[$classId] ?? [];
    }
    /** @var array<int, array<int, string>> */
    public array $participantApprovedAt = [];
    public function participantApprovedAtMap(int $classId): array
    {
        return $this->participantApprovedAt[$classId] ?? [];
    }
    public function instructorUserIds(int $classId): array
    {
        return $this->instructors[$classId] ?? [];
    }
    public function groupIds(int $classId): array { return $this->groups[$classId] ?? []; }
    public function isActiveParticipant(int $classId, int $userId): bool
    {
        return in_array($userId, $this->participants[$classId] ?? [], true);
    }
    public function isActiveInstructor(int $classId, int $userId): bool
    {
        return in_array($userId, $this->instructors[$classId] ?? [], true);
    }
    public function saveAggregate(
        int $actorUserId,
        ClassSaveCommand $command,
        ?string $passwordHash
    ): int {
        return $command->classId();
    }
    public function setEnrollmentStatus(
        int $classId,
        int $userId,
        string $status,
        int $actorUserId
    ): void {}
    public function transitionEnrollmentStatus(
        int $classId,
        int $userId,
        ?string $expectedStatus,
        string $status,
        int $actorUserId
    ): bool {
        return false;
    }
    public function setDeleted(int $classId, bool $deleted, int $actorUserId): void
    {
        $this->lifecycle[] = [$classId, $deleted];
    }
}

final class LearningAccessContestJudge implements ContestJudgeRepository
{
    /** @var array<int, Contest> */
    public array $items;
    /** @var list<ContestSaveCommand> */
    public array $saves = [];
    /** @var list<array{int, bool}> */
    public array $lifecycle = [];

    public function __construct()
    {
        $this->items = [
            100 => new Contest(
                100,
                '진행 대회',
                '설명',
                '2026-01-01 00:00:00',
                '2026-01-01 01:00:00',
                false,
                false
            ),
            101 => new Contest(
                101,
                '준비 대회',
                '설명',
                '2026-01-01 02:00:00',
                '2026-01-01 03:00:00',
                false,
                false
            ),
            102 => new Contest(
                102,
                '종료 대회',
                '설명',
                '2025-12-31 22:00:00',
                '2025-12-31 23:00:00',
                false,
                false
            ),
            103 => new Contest(
                103,
                '복구 대회',
                '설명',
                '2026-01-01 02:00:00',
                '2026-01-01 03:00:00',
                false,
                false
            ),
        ];
    }

    public function find(int $contestId): ?Contest
    {
        return $this->items[$contestId] ?? null;
    }
    public function all(): array { return array_values($this->items); }
    public function problems(int $contestId): array
    {
        return [new ContestProblem(1000, 0, 100.0)];
    }
    public function save(ContestSaveCommand $command, string $ownerLogin): int
    {
        $this->saves[] = $command;
        $this->items[$command->contestId()] = new Contest(
            $command->contestId(),
            $command->title(),
            $command->description(),
            $command->startsAt(),
            $command->endsAt(),
            false,
            $command->visibility() !== 'public'
        );

        return $command->contestId();
    }
    public function setArchived(int $contestId, bool $archived): void
    {
        $contest = $this->items[$contestId];
        $this->lifecycle[] = [$contestId, $archived];
        $this->items[$contestId] = new Contest(
            $contest->id(),
            $contest->title(),
            $contest->description(),
            $contest->startsAt(),
            $contest->endsAt(),
            $archived,
            $contest->isPrivate()
        );
    }
    public function remove(int $contestId): void
    {
        unset($this->items[$contestId]);
    }
}

final class LearningAccessContestPlatform implements ContestPlatformRepository, \CodingPanda\OjPlatform\Modules\Contests\Contract\ContestParticipantTransition
{
    public array $participantStatuses = [];
    public bool $failParticipantTransition = false;
    /** @var array<int, ContestSettings> */
    public array $settings = [];
    /** @var array<int, array<int, string>> */
    public array $roles = [];
    /** @var array<int, array<int, float>> */
    public array $scores = [];
    /** @var array<int, array<int, string>> */
    public array $allowedIps = [];
    /** @var array<int, ContestIpChangeRequest> */
    public array $ipRequests = [];
    /** @var list<array<string, mixed>> */
    public array $ipLogs = [];
    public bool $failNextSave = false;
    private int $nextIpRequestId = 1;

    public function __construct()
    {
        foreach ([100, 101, 102, 103] as $contestId) {
            $this->settings[$contestId] = learningAccessContestSettings($contestId);
            $this->roles[$contestId] = [
                20 => 'manager',
                40 => 'reviewer',
                50 => 'proctor',
            ];
        }
    }

    public function settings(int $contestId): ?ContestSettings
    {
        return $this->settings[$contestId] ?? null;
    }
    public function problemScores(int $contestId): array
    {
        return $this->scores[$contestId] ?? [1000 => 100.0];
    }
    public function saveSettings(
        int $contestId,
        int $ownerUserId,
        ContestSaveCommand $command
    ): void {
        if ($this->failNextSave) {
            $this->failNextSave = false;
            throw new RuntimeException('플랫폼 저장 실패');
        }
        $this->settings[$contestId] = ContestSettings::fromRow([
            'contest_id' => $contestId,
            'owner_user_id' => $ownerUserId,
            'visibility' => $command->visibility(),
            'approval_required' => $command->requiresApproval() ? 1 : 0,
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
            'allow_ai_feedback' => $command->allowAiFeedback() ? 1 : 0,
            'wrong_answer_policy' => $command->wrongAnswerPolicy(),
            'ip_policy' => $command->ipPolicy(),
        ]);
        $this->roles[$contestId] = $command->managerRoles();
        $this->scores[$contestId] = $command->problemScores();
    }
    public function managedContestIds(int $userId): array
    {
        $ids = [];
        foreach ($this->roles as $contestId => $roles) {
            if (isset($roles[$userId])) {
                $ids[] = $contestId;
            }
        }

        return $ids;
    }
    public function managerRoles(int $contestId): array
    {
        return $this->roles[$contestId] ?? [];
    }
    public function isManager(int $contestId, int $userId): bool
    {
        return isset($this->roles[$contestId][$userId]);
    }
    public function participantUserIds(int $contestId, string $status = 'active'): array
    {
        return [30];
    }
    public function isActiveParticipant(int $contestId, int $userId): bool
    {
        return $this->participantStatus($contestId, $userId) === 'active';
    }
    public function participantStatus(int $contestId, int $userId): ?string
    {
        return $this->participantStatuses[$contestId][$userId] ?? ($userId === 30 ? 'active' : null);
    }
    public function setParticipantStatus(
        int $contestId,
        int $userId,
        string $status,
        int $actorUserId
    ): void { $this->participantStatuses[$contestId][$userId] = $status; }
    public function transitionParticipantStatus(int $contestId, int $userId, ?string $expectedStatus, string $status, int $actorUserId): bool
    {
        if ($this->failParticipantTransition || $this->participantStatus($contestId, $userId) !== $expectedStatus) { return false; }
        $this->setParticipantStatus($contestId, $userId, $status, $actorUserId);
        return true;
    }
    public function allowedIp(int $contestId, int $userId): ?string
    {
        return $this->allowedIps[$contestId][$userId] ?? null;
    }
    public function setAllowedIp(
        int $contestId,
        int $userId,
        ?string $ipAddress
    ): void {
        if ($ipAddress === null) {
            unset($this->allowedIps[$contestId][$userId]);
            return;
        }
        $this->allowedIps[$contestId][$userId] = $ipAddress;
    }
    public function logIp(
        int $contestId,
        int $userId,
        string $ipAddress,
        string $action,
        array $metadata = []
    ): void {
        $this->ipLogs[] = compact(
            'contestId',
            'userId',
            'ipAddress',
            'action',
            'metadata'
        );
    }
    public function pendingIpChangeRequest(
        int $contestId,
        int $userId
    ): ?ContestIpChangeRequest {
        foreach ($this->ipRequests as $request) {
            if (
                $request->contestId() === $contestId
                && $request->userId() === $userId
            ) {
                return $request;
            }
        }
        return null;
    }
    public function ipChangeRequest(int $requestId): ?ContestIpChangeRequest
    {
        return $this->ipRequests[$requestId] ?? null;
    }
    public function pendingIpChangeRequests(int $contestId): array
    {
        return array_values(array_filter(
            $this->ipRequests,
            static fn (ContestIpChangeRequest $request): bool =>
                $request->contestId() === $contestId
        ));
    }
    public function createIpChangeRequest(
        int $contestId,
        int $userId,
        string $requestedIp,
        ?string $previousIp,
        string $reason
    ): int {
        $id = $this->nextIpRequestId++;
        $this->ipRequests[$id] = new ContestIpChangeRequest(
            $id,
            $contestId,
            $userId,
            $requestedIp,
            $previousIp,
            $reason,
            '2026-01-01 00:30:00'
        );
        return $id;
    }
    public function resolveIpChangeRequest(
        ContestIpChangeRequest $request,
        bool $approved,
        int $actorUserId
    ): void {
        unset($this->ipRequests[$request->id()]);
        if ($approved) {
            $this->setAllowedIp(
                $request->contestId(),
                $request->userId(),
                $request->requestedIp()
            );
        }
        $this->logIp(
            $request->contestId(),
            $request->userId(),
            $request->requestedIp(),
            $approved ? 'change_approved' : 'change_rejected',
            ['request_id' => $request->id(), 'actor_user_id' => $actorUserId]
        );
    }
    public function storeDeletedSnapshot(
        int $contestId,
        string $title,
        string $snapshot,
        int $actorUserId
    ): void {}
    public function removeDeletedSnapshot(int $contestId): void {}
}

final class LearningAccessClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-01-01 00:30:00',
            new DateTimeZone('Asia/Seoul')
        );
    }
}

/** @param array<string, mixed> $changes */
function learningAccessContestSettings(
    int $contestId,
    array $changes = []
): ContestSettings
{
    return new ContestSettings(
        $contestId,
        10,
        (string) ($changes['visibility'] ?? 'public'),
        array_key_exists('group_id', $changes)
            ? ($changes['group_id'] === null ? null : (int) $changes['group_id'])
            : null,
        (string) ($changes['result_visibility'] ?? 'participants'),
        (string) ($changes['ranking_visibility'] ?? 'private'),
        5,
        10,
        'none',
        'standard_score_v1',
        'score_solved_time_v1',
        (int) ($changes['freeze_minutes'] ?? 0),
        null,
        (bool) ($changes['allow_ai_feedback'] ?? true),
        'penalty_before_accept',
        (string) ($changes['ip_policy'] ?? 'none'),
        (bool) ($changes['approval_required'] ?? false)
    );
}

/**
 * @param array<string, mixed> $changes
 * @param array<int, string>|null $roles
 */
function learningAccessContestCommand(
    Contest $contest,
    ContestSettings $settings,
    array $changes = [],
    ?array $roles = null
): ContestSaveCommand {
    $value = static fn (string $key, mixed $default): mixed =>
        array_key_exists($key, $changes) ? $changes[$key] : $default;

    return new ContestSaveCommand(
        $contest->id(),
        (string) $value('title', $contest->title()),
        (string) $value('description', $contest->description()),
        (string) $value('starts_at', $contest->startsAt()),
        (string) $value('ends_at', $contest->endsAt()),
        (string) $value('visibility', $settings->visibility()),
        $value('group_id', $settings->groupId()),
        (string) $value('result_visibility', $settings->resultVisibility()),
        (string) $value('ranking_visibility', $settings->rankingVisibility()),
        (int) $value('wrong_penalty_points', $settings->wrongPenaltyPoints()),
        (int) $value('time_score_percent', $settings->timeScorePercent()),
        (string) $value('partial_score_policy', $settings->partialScorePolicy()),
        (string) $value('scoring_policy', $settings->scoringPolicy()),
        (string) $value('ranking_policy', $settings->rankingPolicy()),
        (int) $value('freeze_minutes', $settings->freezeMinutes()),
        $value('blockly_profile_key', $settings->blocklyProfileKey()),
        (bool) $value('allow_ai_feedback', $settings->allowsAiFeedback()),
        (string) $value('wrong_answer_policy', $settings->wrongAnswerPolicy()),
        (string) $value('ip_policy', $settings->ipPolicy()),
        $value('problem_ids', [1000]),
        $value('problem_scores', [1000 => 100.0]),
        $roles ?? $value(
            'manager_roles',
            [20 => 'manager', 40 => 'reviewer', 50 => 'proctor']
        ),
        (bool) $value('approval_required', $settings->requiresApproval())
    );
}

$authorization = new LearningAccessAuthorization();
$users = new LearningAccessUsers();
$classInput = (new ClassRequestFactory($users))->saveCommand(
    new HttpRequest('POST', '/', [], [
        'instructor_user_logins' => "manager\nreviewer",
    ])
);
learningAccessAssert(
    $classInput->instructorUserIds() === [20, 40],
    '공동 강사 입력은 로그인 아이디를 숫자 user_id로 변환해야 합니다.'
);
$classProblemInput = (new ClassRequestFactory($users))->saveCommand(
    new HttpRequest('POST', '/', [], [
        'class_problem_ids' => [1000, 1001],
        'problem_remove' => [1001 => '1'],
        'problem_ids' => '1002',
        'problem_configured' => [1000 => '1'],
        'problem_order' => [1000 => '2'],
        'problem_available_from' => [1000 => '2026-01-01T00:05'],
        'problem_available_until' => [1000 => '2026-01-01T01:00'],
    ])
);
$classProblemConfigurations = $classProblemInput->problemConfigurations();
learningAccessAssert(
    $classProblemInput->problemIds() === [1000, 1002]
        && isset($classProblemConfigurations[1000])
        && ! $classProblemConfigurations[1000]->isVisible()
        && $classProblemConfigurations[1000]->availableFrom()
            === '2026-01-01 00:05:00'
        && $classProblemConfigurations[1000]->availableUntil()
            === '2026-01-01 01:00:00',
    '수업 문제의 제외·순서·공개 여부·공개 기간을 하나의 저장 계약으로 변환해야 합니다.'
);
learningAccessThrows(
    static fn () => (new ClassRequestFactory($users))->saveCommand(
        new HttpRequest('POST', '/', [], [
            'starts_at' => '2026-02-30T09:15',
        ])
    ),
    '존재하지 않는 수업 날짜는 저장 전에 거부해야 합니다.'
);
$contestInput = (new ContestRequestFactory($users))->saveCommand(
    new HttpRequest('POST', '/', [], [
        'manager_role_logins' => "manager:공동 운영자\nproctor:시험 감독자",
    ])
);
learningAccessAssert(
    $contestInput->managerRoles() === [20 => 'manager', 50 => 'proctor'],
    '대회 담당자 입력은 로그인 아이디와 한글 역할을 내부 계약으로 변환해야 합니다.'
);
$icpcPolicyInput = (new ContestRequestFactory($users))->saveCommand(
    new HttpRequest('POST', '/', [], [
        'scoring_policy' => ContestScoringPolicy::ICPC,
        'ranking_policy' => ContestScoringPolicy::STANDARD_RANKING,
    ])
);
learningAccessAssert(
    $icpcPolicyInput->rankingPolicy()
        === ContestScoringPolicy::ICPC_RANKING,
    'ICPC 저장 요청은 외부 순위 정책 값을 무시하고 ICPC 순위 정책을 사용해야 합니다.'
);
$oiPolicyInput = (new ContestRequestFactory($users))->saveCommand(
    new HttpRequest('POST', '/', [], [
        'scoring_policy' => ContestScoringPolicy::OI,
        'ranking_policy' => ContestScoringPolicy::ICPC_RANKING,
    ])
);
learningAccessAssert(
    $oiPolicyInput->rankingPolicy() === ContestScoringPolicy::OI_RANKING,
    'OI 저장 요청은 외부 순위 정책 값을 무시하고 OI 순위 정책을 사용해야 합니다.'
);
$contestTimeInput = (new ContestRequestFactory($users))->saveCommand(
    new HttpRequest('POST', '/', [], [
        'starts_at' => '2026-08-10T09:15',
        'ends_at' => '2026-08-10T11:45',
    ])
);
learningAccessAssert(
    $contestTimeInput->startsAt() === '2026-08-10 09:15:00'
        && $contestTimeInput->endsAt() === '2026-08-10 11:45:00',
    '대회 입력 시각은 UTC로 변환하지 않고 사이트 시간대의 시각을 유지해야 합니다.'
);
learningAccessThrows(
    static fn () => (new ContestRequestFactory($users))->saveCommand(
        new HttpRequest('POST', '/', [], [
            'starts_at' => '2026-02-30T09:15',
            'ends_at' => '2026-08-10T11:45',
        ])
    ),
    '존재하지 않는 대회 날짜는 저장 전에 거부해야 합니다.'
);
learningAccessThrows(
    static fn () => (new ClassRequestFactory($users))->saveCommand(
        new HttpRequest('POST', '/', [], [
            'instructor_user_logins' => 'missing',
        ])
    ),
    '존재하지 않는 공동 강사 로그인 아이디는 거부해야 합니다.'
);
$submissions = new LearningAccessSubmissions();
$groupChoices = new LearningAccessGroups(true);
$classRepository = new LearningAccessClasses();
$learningProblems = new LearningAccessProblems();
$classService = new ClassService(
    $classRepository,
    new ClassStaffPolicy($classRepository, $authorization),
    $users,
    $learningProblems,
    $submissions,
    $groupChoices,
    new LearningAccessClock()
);
$learningProblems->missingIds = [9999];
learningAccessThrows(
    static fn () => $classService->save(
        10,
        new ClassSaveCommand(
            1,
            '알고리즘 수업',
            'algorithm-class',
            '설명',
            'private',
            'private',
            true,
            null,
            null,
            null,
            null,
            [20],
            [],
            [1000, 9999]
        )
    ),
    'Judge에 없거나 연결 권한이 없는 문제는 수업에 저장할 수 없어야 합니다.'
);
$learningProblems->missingIds = [];
learningAccessThrows(
    static fn () => $classService->membership(10, 1, 'invite', 999),
    '존재하지 않는 사용자를 수업에 초대할 수 없어야 합니다.'
);
$classRepository->problemItems[1] = [
    new ClassProblem(1000, 0, true, '2026-01-01 00:05:00', null),
    new ClassProblem(1001, 1, false, null, null),
    new ClassProblem(1002, 2, true, '2026-01-01 00:40:00', null),
];
$submissions->items = [
    new SubmissionProjection(
        11,
        1000,
        'participant',
        4,
        6,
        10,
        128,
        '2025-12-31 15:01:00',
        true,
        1.0
    ),
    new SubmissionProjection(
        12,
        1000,
        'participant',
        4,
        6,
        10,
        128,
        '2025-12-31 15:10:00',
        true,
        1.0
    ),
    new SubmissionProjection(
        13,
        1001,
        'participant',
        4,
        6,
        10,
        128,
        '2025-12-31 15:10:00',
        true,
        1.0
    ),
    new SubmissionProjection(
        14,
        1002,
        'participant',
        4,
        6,
        10,
        128,
        '2025-12-31 15:10:00',
        true,
        1.0
    ),
];
$participantProblems = $classService->classProblemsForUser(1, 30);
learningAccessAssert(
    array_map(
        static fn (ClassProblem $problem): int => $problem->problemId(),
        $participantProblems
    ) === [1000, 1002],
    '학습자 수업 화면은 숨김 문제를 제외하고 공개·예약 문제만 표시해야 합니다.'
);
learningAccessAssert(
    $classService->canViewProblem(30, 1, 1000)
        && ! $classService->canViewProblem(30, 1, 1001)
        && ! $classService->canViewProblem(30, 1, 1002)
        && $classService->problemAvailability($participantProblems[1])
            === 'scheduled',
    '학습자는 현재 공개된 문제만 열고 예약 문제는 대기 상태로 봐야 합니다.'
);
learningAccessAssert(
    count($classService->classProblemsForUser(1, 10)) === 3
        && $classService->canViewProblem(10, 1, 1001)
        && $classService->canViewProblem(10, 1, 1002),
    '수업 소유자는 숨김·예약 문제를 관리 목적으로 열 수 있어야 합니다.'
);
$personalProgress = $classService->report(
    30,
    'participant',
    1,
    true
);
learningAccessAssert(
    count($personalProgress->participants()) === 1
        && count($personalProgress->problems()) === 1
        && count($personalProgress->submissions()) === 1
        && $personalProgress->submissions()[0]->submissionId() === 12,
    '비공개 학습 현황은 본인의 현재 공개 문제와 공개 이후 제출만 집계해야 합니다.'
);
$enrollmentTimingReport = new ClassReport(
    $classRepository->items[1],
    [
        new UserIdentity(30, 'participant', 'participant@example.test', '참여자'),
    ],
    [new SubmissionProblem(1000, '문제 1000')],
    [
        new SubmissionProjection(
            90,
            1000,
            'participant',
            4,
            6,
            10,
            128,
            '2025-01-01 00:00:00',
            true,
            1.0
        ),
    ],
    [30 => '2026-01-01 00:00:00']
);
$enrollmentTimingHtml = (new ClassHtml(new class () implements JudgeResults {
    public function find(int $code): JudgeResult
    {
        return new JudgeResult($code, 'test', '테스트', 'neutral', false);
    }
    public function all(): array
    {
        return [];
    }
}))->reportTable($enrollmentTimingReport);
learningAccessAssert(
    str_contains($enrollmentTimingHtml, '시도 1')
        && ! str_contains($enrollmentTimingHtml, '첫 해결')
        && ! str_contains($enrollmentTimingHtml, '">해결<'),
    '참여 승인 이전에 제출한 정답은 최초 해결·해결 집계에 포함되면 안 됩니다.'
);
$approvedTimingReport = new ClassReport(
    $classRepository->items[1],
    [
        new UserIdentity(30, 'participant', 'participant@example.test', '참여자'),
    ],
    [new SubmissionProblem(1000, '문제 1000')],
    [
        new SubmissionProjection(
            91,
            1000,
            'participant',
            4,
            6,
            10,
            128,
            '2026-02-01 00:00:00',
            true,
            1.0
        ),
    ],
    [30 => '2026-01-01 00:00:00']
);
$approvedTimingHtml = (new ClassHtml(new class () implements JudgeResults {
    public function find(int $code): JudgeResult
    {
        return new JudgeResult($code, 'test', '테스트', 'neutral', false);
    }
    public function all(): array
    {
        return [];
    }
}))->reportTable($approvedTimingReport);
learningAccessAssert(
    str_contains($approvedTimingHtml, '첫 해결'),
    '참여 승인 이후에 제출한 정답은 정상적으로 최초 해결로 집계돼야 합니다.'
);
$submissions->items = [];
$classRepository->problemItems[1] = [
    new ClassProblem(1000, 0, true, null, null),
];
$class = $classRepository->items[1];
learningAccessAssert(
    $classService->canChangeSettings(10, $class),
    '수업 소유자는 기본 설정을 변경할 수 있어야 합니다.'
);
learningAccessAssert(
    $classService->canManage(20, $class)
        && ! $classService->canChangeSettings(20, $class),
    '공동 강사는 수업 운영은 가능하지만 기본 설정은 변경할 수 없어야 합니다.'
);
$unchangedClass = new ClassSaveCommand(
    1,
    '알고리즘 수업',
    'algorithm-class',
    '설명',
    'private',
    'private',
    true,
    null,
    null,
    null,
    null,
    [20],
    [],
    [1000, 1001]
);
learningAccessAssert(
    $classService->save(20, $unchangedClass) === 1,
    '공동 강사는 문제 구성을 저장할 수 있어야 합니다.'
);
$changedVisibility = new ClassSaveCommand(
    1,
    '알고리즘 수업',
    'algorithm-class',
    '설명',
    'public',
    'private',
    true,
    null,
    null,
    null,
    null,
    [20],
    [],
    [1000]
);
learningAccessThrows(
    static fn () => $classService->save(20, $changedVisibility),
    '공동 강사의 수업 접근 설정 변경은 거부해야 합니다.'
);
learningAccessThrows(
    static fn () => $classService->save(
        10,
        new ClassSaveCommand(
            1,
            '알고리즘 수업',
            'algorithm-class',
            '설명',
            'private',
            'private',
            true,
            null,
            null,
            null,
            null,
            [20],
            [999],
            [1000]
        )
    ),
    '선택 권한이 없는 그룹 번호를 수업에 연결할 수 없어야 합니다.'
);
learningAccessThrows(
    static fn () => $classService->save(
        10,
        new ClassSaveCommand(
            1,
            '알고리즘 수업',
            'algorithm-class',
            '설명',
            'group_only',
            'private',
            true,
            null,
            null,
            null,
            null,
            [20],
            [],
            [1000]
        )
    ),
    '그룹 공개 수업은 연결 그룹 없이 저장할 수 없어야 합니다.'
);
$classRepository->groups[1] = [7];
$classRepository->items[1] = new LearningClass(
    1,
    '알고리즘 수업',
    'algorithm-class',
    '설명',
    'group_only',
    'private',
    true,
    null,
    null,
    null,
    null,
    10,
    null
);
learningAccessAssert(
    $classService->participationStatus(1, 60) === 'group'
        && $classService->findVisible(1, 60)->id() === 1
        && $classService->canViewProblem(60, 1, 1000)
        && in_array(60, $classService->participantUserIds(1), true)
        && $classService->isActiveLearner(1, 60)
        && in_array(60, $classService->activeLearnerUserIds(1), true)
        && array_map(static fn (LearningClass $class): int => $class->id(), $classService->learningBy(60)) === [1],
    '활성 연결 그룹 구성원은 수업 참여자와 동일하게 접근·집계되어야 합니다.'
);
$disabledClassService = new ClassService(
    $classRepository,
    new ClassStaffPolicy($classRepository, $authorization),
    $users,
    new LearningAccessProblems(),
    $submissions,
    new LearningAccessGroups(false),
    new LearningAccessClock()
);
learningAccessThrows(
    static fn () => $disabledClassService->findVisible(1, 60),
    '그룹 기능이 꺼지면 연결 그룹에 의한 수업 접근도 중단되어야 합니다.'
);
learningAccessAssert(
    ! in_array(60, $disabledClassService->participantUserIds(1), true)
        && ! $disabledClassService->isActiveLearner(1, 60)
        && ! in_array(60, $disabledClassService->activeLearnerUserIds(1), true)
        && $disabledClassService->learningBy(60) === [],
    '그룹 기능이 꺼지면 연결 그룹 구성원을 수업 참여자 집계에 포함하지 않아야 합니다.'
);
$disabledClassService->save(
    10,
    new ClassSaveCommand(
        1,
        '알고리즘 수업',
        'algorithm-class',
        '설명',
        'private',
        'private',
        true,
        null,
        null,
        null,
        null,
        [20],
        [7],
        [1000]
    )
);
learningAccessAssert(
    true,
    '그룹 기능이 꺼져도 수업의 기존 그룹 연결을 유지한 저장은 가능해야 합니다.'
);
learningAccessThrows(
    static fn () => $disabledClassService->save(
        10,
        new ClassSaveCommand(
            1,
            '알고리즘 수업',
            'algorithm-class',
            '설명',
            'private',
            'private',
            true,
            null,
            null,
            null,
            null,
            [20],
            [],
            [1000]
        )
    ),
    '그룹 기능이 꺼진 동안 수업의 기존 그룹 연결을 변경할 수 없어야 합니다.'
);
$classRepository->groups[1] = [];
learningAccessThrows(
    static fn () => $classService->setArchived(20, 1, true),
    '공동 강사의 수업 보관은 거부해야 합니다.'
);
$classService->setArchived(10, 1, true);
$classService->setArchived(10, 1, false);
learningAccessAssert(
    $classRepository->lifecycle === [[1, true], [1, false]]
        && $classRepository->problemItems[1] !== []
        && $classRepository->groups[1] === [],
    '수업 보관·복원은 문제와 그룹 관계를 지우지 않아야 합니다.'
);
learningAccessThrows(
    static fn () => $classService->findVisible(2, 30),
    '중지된 공개 수업의 직접 접근은 거부해야 합니다.'
);

$contestJudge = new LearningAccessContestJudge();
$contestPlatform = new LearningAccessContestPlatform();
$contestClock = new LearningAccessClock();
$contestService = new ContestService(
    $contestJudge,
    $contestPlatform,
    new ContestStaffPolicy($contestPlatform, $authorization),
    $users,
    $learningProblems,
    $submissions,
    new ContestRankingCalculator(),
    new ContestStatisticsCalculator(),
    $contestClock,
    $groupChoices
);
$contestPlatform->settings[100] = learningAccessContestSettings(100, [
    'ip_policy' => 'managed',
]);
learningAccessAssert(
    $contestService->submitAllowedIpChange(
        30,
        100,
        '198.51.100.17',
        '접속 장소 이동'
    ) === 'requested'
        && $contestPlatform->allowedIp(100, 30) === null
        && $contestService->pendingIpChangeRequest(30, 100)?->requestedIp()
            === '198.51.100.17',
    '관리형 IP 정책은 참가자 요청만 기록하고 승인 전 허용 IP를 바꾸면 안 됩니다.'
);
learningAccessThrows(
    static fn () => $contestService->submitAllowedIpChange(
        30,
        100,
        '203.0.113.20',
        '중복 요청'
    ),
    '처리 중인 IP 변경 요청이 있으면 중복 등록을 거부해야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->resolveAllowedIpChange(
        40,
        100,
        1,
        true
    ),
    '결과 검토자는 진행 중 IP 변경 요청을 승인할 수 없어야 합니다.'
);
$contestService->resolveAllowedIpChange(50, 100, 1, true);
learningAccessAssert(
    $contestPlatform->allowedIp(100, 30) === '198.51.100.17'
        && $contestService->pendingIpChangeRequest(30, 100) === null,
    '시험 감독자의 승인은 요청 IP 반영과 대기 상태 해제를 함께 처리해야 합니다.'
);
$contestService->submitAllowedIpChange(
    30,
    100,
    '203.0.113.20',
    '다른 네트워크 사용'
);
$contestService->resolveAllowedIpChange(50, 100, 2, false);
learningAccessAssert(
    $contestPlatform->allowedIp(100, 30) === '198.51.100.17',
    'IP 변경 요청을 거절하면 기존 허용 IP를 유지해야 합니다.'
);
$contestPlatform->settings[101] = learningAccessContestSettings(101, [
    'ip_policy' => 'first_access',
]);
learningAccessAssert(
    $contestService->submitAllowedIpChange(
        30,
        101,
        '192.0.2.44',
        '첫 접속 등록'
    ) === 'registered'
        && $contestPlatform->allowedIp(101, 30) === '192.0.2.44'
        && $contestPlatform->pendingIpChangeRequest(101, 30) === null,
    '최초 접속 정책의 첫 IP는 승인 대기 없이 즉시 등록해야 합니다.'
);
$aiFeedbackPolicies = new SubmissionAiFeedbackPolicyRegistry();
$aiFeedbackPolicies->register(
    new ContestSubmissionAiFeedbackPolicy($contestService)
);
$contestSubmission = new SubmissionSummary(
    new SubmissionId(1),
    1000,
    'participant',
    '참여자',
    6,
    4,
    10,
    128,
    '2026-01-01 00:10:00',
    '2026-01-01 00:10:01',
    100,
    0,
    null,
    null,
    true,
    1.0
);
learningAccessAssert(
    $aiFeedbackPolicies->allows($contestSubmission),
    'AI 피드백이 허용된 대회 제출에는 피드백을 제공해야 합니다.'
);
$contestPlatform->settings[100] = learningAccessContestSettings(100, [
    'allow_ai_feedback' => false,
]);
learningAccessAssert(
    ! $aiFeedbackPolicies->allows($contestSubmission),
    'AI 피드백이 금지된 대회 제출에는 피드백을 제공하면 안 됩니다.'
);
$generalSubmission = new SubmissionSummary(
    new SubmissionId(2),
    1000,
    'participant',
    '참여자',
    6,
    4,
    10,
    128,
    '2026-01-01 00:10:00',
    '2026-01-01 00:10:01',
    0,
    0,
    null,
    null,
    true
);
learningAccessAssert(
    $aiFeedbackPolicies->allows($generalSubmission),
    '대회 밖 일반 제출은 대회 AI 정책의 영향을 받지 않아야 합니다.'
);
$contestPlatform->settings[100] = learningAccessContestSettings(100, [
    'ranking_visibility' => 'live',
    'freeze_minutes' => 40,
]);
$submissions->items = [
    new SubmissionProjection(
        1,
        1000,
        'participant',
        4,
        6,
        10,
        128,
        '2025-12-31 15:10:00',
        true,
        1.0,
        100
    ),
    new SubmissionProjection(
        2,
        1000,
        'participant',
        4,
        6,
        10,
        128,
        '2025-12-31 15:25:00',
        true,
        1.0,
        100
    ),
];
$participantFrozenReport = $contestService->report(
    30,
    'participant',
    100,
    false,
    true,
    false
);
learningAccessAssert(
    $participantFrozenReport->isRankingFrozen()
        && $participantFrozenReport->rankingFreezeCutoff()
            === '2025-12-31 15:20:00'
        && count($participantFrozenReport->submissions()) === 1,
    '참가자 순위에는 동결 시각 이전 제출만 반영해야 합니다.'
);
$staffLiveReport = $contestService->report(
    20,
    'manager',
    100,
    false,
    true,
    false
);
learningAccessAssert(
    ! $staffLiveReport->isRankingFrozen()
        && count($staffLiveReport->submissions()) === 2,
    '대회 운영자 보고서는 동결 중에도 전체 제출을 실시간으로 반영해야 합니다.'
);
$contestPlatform->settings[100] = learningAccessContestSettings(100, [
    'result_visibility' => 'private',
]);
learningAccessThrows(
    static fn () => $contestService->report(30, 'participant', 100, false, false, true),
    '결과가 비공개인 대회는 참가자도 전체 통계를 볼 수 없어야 합니다.'
);
$contestPlatform->settings[100] = learningAccessContestSettings(100, [
    'result_visibility' => 'participants',
]);
learningAccessAssert(
    $contestService->report(30, 'participant', 100, false, false, true)
        ->contest()->id() === 100,
    '참가자 공개 결과 통계는 활성 참가자에게 제공해야 합니다.'
);
$contestPlatform->settings[100] = learningAccessContestSettings(100, [
    'visibility' => 'public',
    'result_visibility' => 'public',
]);
learningAccessAssert(
    $contestService->report(60, 'group-member', 100, false, false, true)
        ->contest()->id() === 100,
    '공개 대회의 공개 결과 통계는 비참가자도 볼 수 있어야 합니다.'
);
$submissions->items = [];
$contestHtml = new ContestHtml(new class () implements JudgeResults {
    public function find(int $code): JudgeResult
    {
        return new JudgeResult($code, 'test', '테스트', 'neutral', false);
    }
    public function all(): array
    {
        return [];
    }
}, $contestClock);
$ownerContestTable = $contestHtml->contestTable(
    [$contestJudge->items[101]],
    false,
    [101]
);
learningAccessAssert(
    str_contains($ownerContestTable, '/oj-console/contests/101/edit')
        && str_contains($ownerContestTable, '>수정</a>'),
    '대회 소유자에게 공개 대회 목록의 수정 동선을 제공해야 합니다.'
);
learningAccessAssert(
    ! str_contains(
        $contestHtml->contestTable([$contestJudge->items[101]], false),
        '/oj-console/contests/101/edit'
    ),
    '수정 권한이 없는 사용자에게 대회 수정 주소를 노출하면 안 됩니다.'
);
$contestTimeSummary = $contestHtml->timeSummary($contestJudge->items[100]);
learningAccessAssert(
    str_contains($contestTimeSummary, '현재 서버 시각')
        && str_contains($contestTimeSummary, '2026-01-01 00:30:00')
        && str_contains($contestTimeSummary, 'data-ojp-server-clock')
        && str_contains($contestTimeSummary, 'data-ojp-server-clock-value')
        && str_contains(
            $contestTimeSummary,
            'data-server-time="2026-01-01T00:30:00+09:00"'
        )
        && str_contains($contestTimeSummary, 'data-server-timezone="Asia/Seoul"')
        && str_contains($contestTimeSummary, 'data-server-offset="32400"')
        && str_contains($contestTimeSummary, '2026-01-01T01:00:01+09:00')
        && str_contains($contestTimeSummary, 'Asia/Seoul (UTC+09:00)')
        && str_contains($contestTimeSummary, '현재 단계')
        && str_contains($contestTimeSummary, '진행 중'),
    '대회 화면은 사이트 시간대의 서버 시각을 실시간 시계 기준으로 제공해야 합니다.'
);
learningAccessAssert(
    str_contains(
        $contestHtml->contestTable([$contestJudge->items[100]], false),
        '<td>진행 중</td>'
    ),
    '대회 목록 단계도 공통 사이트 시계를 사용해야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->participation(10, 101, 'add', 999),
    '존재하지 않는 사용자를 대회 참가자로 추가할 수 없어야 합니다.'
);
learningAccessAssert(
    $contestService->canAccessConsoleRoute(20, 101, 'console.contests.edit'),
    '공동 운영자는 준비 대회를 편집할 수 있어야 합니다.'
);
learningAccessAssert(
    ! $contestService->canAccessConsoleRoute(50, 100, 'console.contests.edit')
        && $contestService->canAccessConsoleRoute(
            50,
            100,
            'console.contests.ip_check'
        ),
    '시험 감독자는 진행 중 참가·IP만 운영할 수 있어야 합니다.'
);
learningAccessAssert(
    ! $contestService->canAccessConsoleRoute(
        40,
        100,
        'console.contests.ranking'
    )
        && $contestService->canAccessConsoleRoute(
            40,
            102,
            'console.contests.ranking'
        ),
    '결과 검토자는 종료 대회의 결과 화면만 볼 수 있어야 합니다.'
);

$preparation = $contestJudge->items[101];
$preparationSettings = $contestPlatform->settings[101];
$learningProblems->missingIds = [9999];
learningAccessThrows(
    static fn () => $contestService->save(
        10,
        'owner',
        learningAccessContestCommand(
            $preparation,
            $preparationSettings,
            [
                'problem_ids' => [1000, 9999],
                'problem_scores' => [1000 => 100.0, 9999 => 100.0],
            ]
        )
    ),
    'Judge에 없거나 연결 권한이 없는 문제는 대회에 저장할 수 없어야 합니다.'
);
$learningProblems->missingIds = [];
$contestService->save(
    20,
    'manager',
    learningAccessContestCommand(
        $preparation,
        $preparationSettings,
        ['title' => '준비 대회 수정']
    )
);
learningAccessThrows(
    static fn () => $contestService->save(
        20,
        'manager',
        learningAccessContestCommand(
            $contestJudge->items[101],
            $contestPlatform->settings[101],
            [],
            [20 => 'manager']
        )
    ),
    '공동 운영자의 담당자 변경은 거부해야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->save(
        10,
        'owner',
        learningAccessContestCommand(
            $contestJudge->items[101],
            $contestPlatform->settings[101],
            ['visibility' => 'group_only', 'group_id' => null]
        )
    ),
    '그룹 공개 대회는 연결 그룹 없이 저장할 수 없어야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->save(
        10,
        'owner',
        learningAccessContestCommand(
            $contestJudge->items[101],
            $contestPlatform->settings[101],
            ['group_id' => 999]
        )
    ),
    '선택 권한이 없는 그룹 번호를 대회에 연결할 수 없어야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->save(
        10,
        'owner',
        learningAccessContestCommand(
            $contestJudge->items[101],
            $contestPlatform->settings[101],
            ['blockly_profile_key' => 'unknown']
        )
    ),
    '등록되지 않은 Blockly 설정은 거부해야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->save(
        10,
        'owner',
        learningAccessContestCommand(
            $contestJudge->items[101],
            $contestPlatform->settings[101],
            ['problem_scores' => [1000 => -1.0]]
        )
    ),
    '음수 문제 배점은 거부해야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->startNow(40, 'reviewer', 101),
    '결과 검토자는 준비 대회를 시작할 수 없어야 합니다.'
);
$contestService->startNow(20, 'manager', 101);
learningAccessAssert(
    $contestJudge->items[101]->startsAt() === '2026-01-01 00:30:00'
        && $contestService->currentStage($contestJudge->items[101]) === 'running',
    '대회 소유자와 공동 운영자는 준비 대회를 즉시 시작할 수 있어야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->startNow(20, 'manager', 101),
    '이미 진행 중인 대회는 다시 시작할 수 없어야 합니다.'
);

$running = $contestJudge->items[100];
$runningSettings = $contestPlatform->settings[100];
$contestService->save(
    20,
    'manager',
    learningAccessContestCommand(
        $running,
        $runningSettings,
        ['ends_at' => '2026-01-01 01:30:00']
    )
);
learningAccessThrows(
    static fn () => $contestService->save(
        20,
        'manager',
        learningAccessContestCommand(
            $contestJudge->items[100],
            $contestPlatform->settings[100],
            ['scoring_policy' => 'icpc_v1']
        )
    ),
    '진행 중 대회의 채점 정책 변경은 거부해야 합니다.'
);

$ended = $contestJudge->items[102];
$endedSettings = $contestPlatform->settings[102];
$contestService->save(
    20,
    'manager',
    learningAccessContestCommand(
        $ended,
        $endedSettings,
        ['ranking_visibility' => 'final']
    )
);
learningAccessThrows(
    static fn () => $contestService->save(
        40,
        'reviewer',
        learningAccessContestCommand(
            $contestJudge->items[102],
            $contestPlatform->settings[102],
            ['result_visibility' => 'public']
        )
    ),
    '결과 검토자의 결과 공개 범위 변경은 거부해야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->save(
        40,
        'reviewer',
        learningAccessContestCommand(
            $contestJudge->items[102],
            $contestPlatform->settings[102],
            ['title' => '종료 대회 제목 변경']
        )
    ),
    '결과 검토자의 종료 대회 본문 변경은 거부해야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->exportReport(30, 'participant', 102),
    '일반 참가자의 전체 대회 CSV 내보내기는 거부해야 합니다.'
);
learningAccessAssert(
    $contestService->exportReport(40, 'reviewer', 102)->contest()->id() === 102,
    '결과 검토자는 종료 대회 결과를 내보낼 수 있어야 합니다.'
);
learningAccessThrows(
    static fn () => $contestService->setArchived(20, 102, true),
    '공동 운영자의 대회 보관은 거부해야 합니다.'
);
$rolesBeforeArchive = $contestPlatform->managerRoles(102);
$scoresBeforeArchive = $contestPlatform->problemScores(102);
$contestService->setArchived(10, 102, true);
$contestService->setArchived(10, 102, false);
learningAccessAssert(
    $contestJudge->lifecycle === [[102, true], [102, false]]
        && ! $contestJudge->items[102]->isArchived()
        && $contestPlatform->managerRoles(102) === $rolesBeforeArchive
        && $contestPlatform->problemScores(102) === $scoresBeforeArchive,
    '대회 보관·복원은 담당자와 문제 배점 관계를 지우지 않아야 합니다.'
);

$beforeRestoreCount = count($contestJudge->saves);
$contestPlatform->failNextSave = true;
try {
    $contestService->save(
        10,
        'owner',
        learningAccessContestCommand(
            $contestJudge->items[103],
            $contestPlatform->settings[103],
            ['title' => '저장 실패 대회']
        )
    );
    throw new RuntimeException('플랫폼 저장 실패가 전달되어야 합니다.');
} catch (RuntimeException $error) {
    learningAccessAssert(
        $error->getMessage() === '플랫폼 저장 실패',
        '원래 플랫폼 저장 오류를 유지해야 합니다.'
    );
}
learningAccessAssert(
    count($contestJudge->saves) === $beforeRestoreCount + 2
        && $contestJudge->items[103]->title() === '복구 대회',
    '플랫폼 저장 실패 시 Judge 대회 내용을 이전 상태로 되돌려야 합니다.'
);

$groupJudge = new LearningAccessContestJudge();
$groupPlatform = new LearningAccessContestPlatform();
$groupPlatform->settings[100] = learningAccessContestSettings(
    100,
    ['visibility' => 'group_only', 'group_id' => 7]
);
$groupContestService = new ContestService(
    $groupJudge,
    $groupPlatform,
    new ContestStaffPolicy($groupPlatform, $authorization),
    $users,
    new LearningAccessProblems(),
    $submissions,
    new ContestRankingCalculator(),
    new ContestStatisticsCalculator(),
    new LearningAccessClock(),
    $groupChoices
);
learningAccessAssert(
    $groupContestService->participationStatus(100, 60) === 'group'
        && $groupContestService->findVisible(100, 60)->id() === 100
        && $groupContestService->canViewProblem(60, 100, 1000, 0)
        && in_array(60, $groupContestService->participantUserIds(100), true),
    '활성 연결 그룹 구성원은 대회 참여자와 동일하게 접근·집계되어야 합니다.'
);
$groupOffContestService = new ContestService(
    $groupJudge,
    $groupPlatform,
    new ContestStaffPolicy($groupPlatform, $authorization),
    $users,
    new LearningAccessProblems(),
    $submissions,
    new ContestRankingCalculator(),
    new ContestStatisticsCalculator(),
    new LearningAccessClock(),
    new LearningAccessGroups(false)
);
learningAccessThrows(
    static fn () => $groupOffContestService->findVisible(100, 60),
    '그룹 기능이 꺼지면 연결 그룹에 의한 대회 접근도 중단되어야 합니다.'
);
$groupContestService->save(
    10,
    'owner',
    learningAccessContestCommand(
        $groupJudge->items[101],
        $groupPlatform->settings[101],
        ['visibility' => 'group_only', 'group_id' => 7]
    )
);
$disabledContestService = new ContestService(
    $groupJudge,
    $groupPlatform,
    new ContestStaffPolicy($groupPlatform, $authorization),
    $users,
    new LearningAccessProblems(),
    $submissions,
    new ContestRankingCalculator(),
    new ContestStatisticsCalculator(),
    new LearningAccessClock(),
    new LearningAccessGroups(false)
);
$disabledContestService->save(
    10,
    'owner',
    learningAccessContestCommand(
        $groupJudge->items[101],
        $groupPlatform->settings[101],
        ['title' => '기존 그룹 연결 유지']
    )
);
learningAccessAssert(
    true,
    '그룹 기능이 꺼져도 대회의 기존 그룹 연결을 유지한 저장은 가능해야 합니다.'
);
$groupDisableGuard = new GroupsFeatureDisableGuard(
    $classService,
    $groupContestService
);
$groupBlockers = $groupDisableGuard->blockers();
learningAccessAssert(
    in_array('수업 #1 알고리즘 수업', $groupBlockers, true)
        && in_array('대회 #100 진행 대회', $groupBlockers, true),
    'Groups OFF 사전 검사는 연결된 수업과 대회를 구체적으로 알려야 합니다.'
);
learningAccessThrows(
    static fn () => $disabledContestService->save(
        10,
        'owner',
        learningAccessContestCommand(
            $groupJudge->items[101],
            $groupPlatform->settings[101],
            ['visibility' => 'public', 'group_id' => null]
        )
    ),
    '그룹 기능이 꺼진 동안 대회의 기존 그룹 연결을 변경할 수 없어야 합니다.'
);

final class LearningAccessEligibilityFeatures implements FeatureFlags
{
    public function __construct(private readonly bool $contestsEnabled = true)
    {
    }

    public function isEnabled(string $featureKey): bool
    {
        return $featureKey === 'contests' ? $this->contestsEnabled : true;
    }
}

$eligibilityJudge = new LearningAccessContestJudge();
$eligibilityPlatform = new LearningAccessContestPlatform();
$eligibilityPlatform->settings[100] = learningAccessContestSettings(100);
$eligibilityPlatform->settings[102] = learningAccessContestSettings(102);
$eligibilityContestService = new ContestService(
    $eligibilityJudge,
    $eligibilityPlatform,
    new ContestStaffPolicy($eligibilityPlatform, $authorization),
    $users,
    $learningProblems,
    $submissions,
    new ContestRankingCalculator(),
    new ContestStatisticsCalculator(),
    $contestClock,
    $groupChoices
);
$eligibility = new ContestLearningHistoryEligibility(
    $eligibilityContestService,
    new LearningAccessEligibilityFeatures(true)
);

$practiceSubmission = new SubmissionProjection(1, 1000, 'learner', 4, 0, 100, 1000, '2026-01-01 00:10:00', true, 1.0, 0);
learningAccessAssert(
    $eligibility->includes(30, $practiceSubmission),
    '대회와 무관한 제출은 항상 학습 이력에 포함해야 합니다.'
);

$runningContestSubmission = new SubmissionProjection(2, 1000, 'learner', 4, 0, 100, 1000, '2026-01-01 00:20:00', true, 1.0, 100);
learningAccessAssert(
    ! $eligibility->includes(30, $runningContestSubmission),
    '진행 중인 대회 제출은 종료 전까지 학습 이력에서 제외해야 합니다.'
);

$endedContestSubmission = new SubmissionProjection(3, 1000, 'learner', 4, 0, 100, 1000, '2025-12-31 22:30:00', true, 1.0, 102);
learningAccessAssert(
    $eligibility->includes(30, $endedContestSubmission),
    '종료된 대회 제출은 학습 이력에 포함해야 합니다.'
);

$deletedContestSubmission = new SubmissionProjection(4, 1000, 'learner', 4, 0, 100, 1000, '2026-01-01 00:25:00', true, 1.0, 999);
learningAccessAssert(
    $eligibility->includes(30, $deletedContestSubmission),
    '삭제되어 더 이상 조회할 수 없는 대회의 제출을 학습 이력에서 영영 제외하면 안 됩니다.'
);

$eligibilityJudge->setArchived(100, true);
$archivedContestSubmission = new SubmissionProjection(5, 1000, 'learner', 4, 0, 100, 1000, '2026-01-01 00:20:00', true, 1.0, 100);
learningAccessAssert(
    $eligibility->includes(30, $archivedContestSubmission),
    '보관 처리된 대회의 제출을 학습 이력에서 영영 제외하면 안 됩니다.'
);

$disabledEligibility = new ContestLearningHistoryEligibility(
    $eligibilityContestService,
    new LearningAccessEligibilityFeatures(false)
);
learningAccessAssert(
    $disabledEligibility->includes(30, $runningContestSubmission),
    '대회 기능 자체가 꺼져 있으면 대회 제출도 학습 이력에서 가릴 이유가 없어야 합니다.'
);

$soloSubmissions = new LearningAccessSubmissions();
$soloClassService = new ClassService(
    $classRepository,
    new ClassStaffPolicy($classRepository, $authorization),
    $users,
    $learningProblems,
    $soloSubmissions,
    $groupChoices,
    new LearningAccessClock()
);
$soloSubmissions->items = [
    new SubmissionProjection(1, 501, 'learner', 4, 1, 10, 1024, '2026-01-01 00:00:00', true),
    new SubmissionProjection(2, 502, 'learner', 6, 1, 10, 1024, '2026-01-01 00:01:00', true),
];
$soloStatus = $soloClassService->solveStatusForUser(30, 'learner', 1, [501, 502, 503]);
learningAccessAssert(
    ($soloStatus[501] ?? null) === 'solved',
    '정답 제출이 있는 문제는 해결로 분류해야 합니다.'
);
learningAccessAssert(
    ($soloStatus[502] ?? null) === 'attempted',
    '오답만 있는 문제는 시도로 분류해야 합니다.'
);
learningAccessAssert(
    ($soloStatus[503] ?? null) === 'unsolved',
    '제출 기록이 없는 문제는 미시도로 분류해야 합니다.'
);
learningAccessAssert(
    $soloClassService->solveStatusForUser(30, 'learner', 1, []) === [],
    '조회할 문제가 없으면 빈 배열을 반환해야 합니다.'
);

fwrite(
    STDOUT,
    sprintf(
        "R-07 담당 범위·단계 잠금 단위 검사 통과: assertion %d개\n",
        $learningAccessAssertions
    )
);
