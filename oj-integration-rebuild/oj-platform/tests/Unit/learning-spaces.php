<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\GroupPerformanceModePolicy;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\GroupSubmissionStaffScope;
use CodingPanda\OjPlatform\Kernel\Integration\Learning\LearningGroupChoiceBridge;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthorizedPasswordResetter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAccountProvisioner;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassReport;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestRankingCalculator;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestProblemResultCalculator;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestStatisticsCalculator;
use CodingPanda\OjPlatform\Modules\Contests\Domain\Contest;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestProblem;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestReport;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestScoringPolicy;
use CodingPanda\OjPlatform\Modules\Contests\Domain\ContestSettings;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestHtml;
use CodingPanda\OjPlatform\Modules\Contests\Presentation\Action\ExportContestActionHandler;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupRepository;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupBulkMemberImportJobAccessRule;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupBulkMemberImportJobHandler;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupBulkMemberImportService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupSaveCommand;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\GroupHtml;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\ClaimedJobItem;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobAccessContext;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobEnqueueResult;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobTarget;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWorkItem;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Submissions\Application\PerformanceModePolicyRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionStaffScopeRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionVisibilityPolicy;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionAccessLevel;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionId;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\SubmissionSummary;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$learningAssertions = 0;

function learningAssert(bool $condition, string $message): void
{
    global $learningAssertions;
    ++$learningAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function learningSame(mixed $expected, mixed $actual, string $message): void
{
    learningAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

function learningThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DomainException) {
        learningAssert(true, $message);

        return;
    }
    throw new RuntimeException($message);
}

final class LearningAuthorization implements Authorization
{
    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        unset($context);

        return $userId === 1 && in_array(
            $capability,
            ['oj_manage_groups', 'oj_create_groups'],
            true
        );
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->can($userId, $capability)) {
                return true;
            }
        }

        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        return $userId === 1;
    }
}

final class LearningFeatures implements FeatureFlags
{
    public function __construct(private readonly bool $groupsEnabled)
    {
    }

    public function isEnabled(string $featureKey): bool
    {
        return $featureKey !== 'groups' || $this->groupsEnabled;
    }
}
final class LearningUsers implements WordPressUserGateway
{
    /** @var array<int, UserIdentity> */
    public array $items;

    public function __construct()
    {
        $this->items = [
            1 => new UserIdentity(1, 'root', 'root@example.test', '관리자', ['administrator']),
            2 => new UserIdentity(2, 'student', 'student@example.test', '학습자'),
            3 => new UserIdentity(3, 'delegate', 'delegate@example.test', '위임 운영자'),
        ];
    }

    public function currentUser(): ?UserIdentity { return $this->items[1]; }
    public function findById(int $userId): ?UserIdentity { return $this->items[$userId] ?? null; }
    public function findByLogin(string $userLogin): ?UserIdentity
    {
        foreach ($this->items as $item) {
            if ($item->userLogin() === $userLogin) {
                return $item;
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
        $identity = new UserIdentity(
            count($this->items) + 1,
            $userLogin,
            $email,
            $displayName,
            [$role]
        );
        $this->items[$identity->userId()] = $identity;

        return $identity;
    }
    public function updateUser(int $userId, string $email, string $displayName): UserIdentity
    {
        return $this->items[$userId];
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
    public function deleteUser(int $userId): void { unset($this->items[$userId]); }
    public function findMany(array $userIds): array
    {
        return array_values(array_filter(array_map(
            fn (int $userId): ?UserIdentity => $this->items[$userId] ?? null,
            $userIds
        )));
    }
}

final class LearningProvisioner implements LearnerAccountProvisioner
{
    public function __construct(private readonly LearningUsers $users)
    {
    }

    public function provisionLearner(
        string $userLogin,
        string $email,
        string $password,
        string $displayName
    ): UserIdentity {
        return $this->users->createUser(
            $userLogin,
            $email,
            $password,
            $displayName,
            'subscriber'
        );
    }
}

final class LearningPasswordResetter implements AuthorizedPasswordResetter
{
    /** @var list<int> */
    public array $targets = [];
    public ?string $customPassword = null;
    public bool $confirmWeakPassword = false;

    public function resetAuthorized(
        int $actorUserId,
        array $targetUserIds,
        string $scope = 'authorized',
        ?string $customPassword = null,
        bool $confirmWeakPassword = false
    ): array {
        unset($actorUserId, $scope);
        $this->targets = $targetUserIds;
        $this->customPassword = $customPassword;
        $this->confirmWeakPassword = $confirmWeakPassword;

        return array_map(
            static fn (int $userId): array => [
                'user_id' => $userId,
                'success' => true,
                'temporary_password' => $customPassword ?? 'Temporary!123',
                'reason' => 'password_reset',
            ],
            $targetUserIds
        );
    }
}

final class LearningSubmissions implements SubmissionProjectionProvider
{
    public ?SubmissionProjectionQuery $query = null;

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
        $this->query = $query;

        return [];
    }

    public function report(SubmissionProjectionQuery $query): array
    {
        $this->query = $query;

        return [
            new SubmissionProjection(
                10,
                1000,
                'student',
                4,
                6,
                10,
                1024,
                '2026-01-01 00:00:00',
                true
            ),
        ];
    }
    public function searchAll(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionProjectionQuery $query
    ): array {
        return $this->search($actorUserId, $actorUserLogin, $query);
    }

    public function reportAll(SubmissionProjectionQuery $query): array
    {
        return $this->report($query);
    }
}

final class LearningJobs implements JobDispatcher
{
    public string $jobType = '';
    public string $executionMode = '';
    public int $actorUserId = 0;
    /** @var list<JobTarget> */
    public array $targets = [];
    /** @var array<string, mixed> */
    public array $inputSummary = [];
    /** @var array<string, mixed> */
    public array $targetSummary = [];
    public string $accessPolicyKey = '';

    public function enqueue(
        string $jobType,
        string $executionMode,
        int $actorUserId,
        array $targets,
        array $inputSummary,
        array $targetSummary,
        string $accessPolicyKey,
        string $accessPolicyVersion = '1',
        string $inputVersion = '1'
    ): JobEnqueueResult {
        unset($accessPolicyVersion, $inputVersion);
        $this->jobType = $jobType;
        $this->executionMode = $executionMode;
        $this->actorUserId = $actorUserId;
        $this->targets = $targets;
        $this->inputSummary = $inputSummary;
        $this->targetSummary = $targetSummary;
        $this->accessPolicyKey = $accessPolicyKey;

        return new JobEnqueueResult(77, true);
    }
}

final class LearningJobContext implements JobAccessContext
{
    /** @param array<string, mixed> $targetSummary */
    public function __construct(private readonly array $targetSummary)
    {
    }

    public function id(): int { return 77; }
    public function jobType(): string { return 'group_member_csv_import'; }
    public function ownerModule(): string { return 'groups'; }
    public function accessPolicyKey(): string { return 'groups.member_csv_import'; }
    public function accessPolicyVersion(): string { return '1'; }
    public function createdByUserId(): int { return 3; }
    public function targetSummary(): array { return $this->targetSummary; }
}

final class LearningGroups implements GroupRepository
{
    /** @var array<int, LearningGroup> */
    public array $groups = [];
    /** @var array<int, array<int, string>> */
    public array $members = [];
    /** @var array<int, list<int>> */
    public array $managers = [];

    public function find(int $groupId): ?LearningGroup
    {
        return $this->groups[$groupId] ?? null;
    }
    public function visibleFor(int $userId): array { return array_values($this->groups); }
    public function managedBy(int $userId, bool $all): array
    {
        return array_values(array_filter(
            $this->groups,
            fn (LearningGroup $group): bool => $all
                || $group->ownerUserId() === $userId
                || in_array($userId, $this->managers[$group->id()] ?? [], true)
        ));
    }
    public function memberUserIds(int $groupId, string $status = 'active'): array
    {
        return array_keys(array_filter(
            $this->members[$groupId] ?? [],
            static fn (string $value): bool => $value === $status
        ));
    }
    public function managerUserIds(int $groupId): array
    {
        return $this->managers[$groupId] ?? [];
    }
    public function activeGroupIdsForUser(int $userId): array
    {
        $ids = [];
        foreach ($this->members as $groupId => $members) {
            if (($members[$userId] ?? null) === 'active') {
                $ids[] = $groupId;
            }
        }

        return $ids;
    }
    public function managedGroupIdsForUser(int $userId): array
    {
        return array_map(
            static fn (LearningGroup $group): int => $group->id(),
            $this->managedBy($userId, false)
        );
    }
    public function isActiveMember(int $groupId, int $userId): bool
    {
        return ($this->members[$groupId][$userId] ?? null) === 'active';
    }
    public function membershipStatus(int $groupId, int $userId): ?string
    {
        return $this->members[$groupId][$userId] ?? null;
    }
    public function isActiveManager(int $groupId, int $userId): bool
    {
        return in_array($userId, $this->managers[$groupId] ?? [], true);
    }
    public function hasActivePerformanceGroup(int $userId): bool
    {
        return $this->activePerformanceGroupIdsForUser($userId) !== [];
    }
    public function activePerformanceGroupIdsForUser(int $userId): array
    {
        $ids = [];
        foreach ($this->activeGroupIdsForUser($userId) as $groupId) {
            $group = $this->groups[$groupId] ?? null;
            if (
                $group?->isPerformanceMode()
                && $group->isEnabled()
                && ! $group->isDeleted()
            ) {
                $ids[] = $groupId;
            }
        }

        return $ids;
    }
    public function saveAggregate(
        int $actorUserId,
        GroupSaveCommand $command,
        ?string $joinCodeHash
    ): int {
        return 1;
    }
    public function setMembershipStatus(
        int $groupId,
        int $userId,
        string $status,
        int $actorUserId
    ): void {
        $this->members[$groupId][$userId] = $status;
    }
    public function setManager(
        int $groupId,
        int $userId,
        bool $enabled,
        int $actorUserId
    ): void {
        if ($enabled) {
            $this->managers[$groupId][] = $userId;
        }
    }
    public function setDeleted(int $groupId, bool $deleted, int $actorUserId): void {}
}

$repository = new LearningGroups();
$repository->groups[1] = new LearningGroup(
    1,
    '알고리즘 학습',
    'algorithm-study',
    '',
    'private',
    'manual',
    true,
    null,
    'performance',
    null,
    1,
    null
);
$repository->members[1] = [2 => 'active'];
$repository->managers[1] = [3];
$users = new LearningUsers();
$passwords = new LearningPasswordResetter();
$submissions = new LearningSubmissions();
$service = new GroupService(
    $repository,
    new LearningAuthorization(),
    $users,
    new LearningProvisioner($users),
    $passwords,
    $submissions
);
learningThrows(
    static fn () => $service->membership(1, 1, 'add', 999),
    '존재하지 않는 사용자를 그룹 구성원으로 추가할 수 없어야 합니다.'
);
learningThrows(
    static fn () => $service->manager(1, 1, 999, true),
    '존재하지 않는 사용자를 그룹 운영자로 지정할 수 없어야 합니다.'
);
$enabledChoices = new LearningGroupChoiceBridge(new LearningFeatures(true));
$enabledChoices->attach($service);
learningSame(
    [1 => '알고리즘 학습'],
    $enabledChoices->choicesFor(3),
    'Groups ON에서는 권한 범위의 그룹 이름 선택지를 제공해야 합니다.'
);
$disabledChoices = new LearningGroupChoiceBridge(new LearningFeatures(false));
$disabledChoices->attach($service);
learningAssert(
    ! $disabledChoices->isAvailable()
        && $disabledChoices->choicesFor(3) === [],
    'Groups OFF에서는 수업·대회 그룹 선택지를 중립값으로 반환해야 합니다.'
);
learningSame(
    2,
    $service->userIdByLogin('student'),
    '사용자 선택은 로그인 아이디를 WordPress 숫자 user_id로 해석해야 합니다.'
);
learningThrows(
    static fn () => $service->userIdByLogin('missing'),
    '존재하지 않는 로그인 아이디 선택은 거부해야 합니다.'
);

learningAssert(
    $service->restrictsOwnSubmissionDetails(2),
    '수행모드 활성 구성원은 본인 제출 상세가 제한되어야 합니다.'
);
$repository->members[1][3] = 'active';
learningAssert(
    ! $service->restrictsOwnSubmissionDetails(3),
    '위임 운영자는 자신이 담당하는 수행 그룹의 구성원이어도 상세를 볼 수 있어야 합니다.'
);
$repository->groups[2] = new LearningGroup(
    2,
    '다른 수행 그룹',
    'other-performance-group',
    '',
    'private',
    'manual',
    true,
    null,
    'performance',
    null,
    4,
    null
);
$repository->members[2] = [3 => 'active'];
learningAssert(
    $service->restrictsOwnSubmissionDetails(3),
    '담당하지 않는 수행 그룹에도 속한 운영자는 전체 상세 제한을 우회하면 안 됩니다.'
);
unset($repository->members[2], $repository->groups[2]);
unset($repository->members[1][3]);
learningAssert(
    $service->canViewMemberSubmission(3, 'student'),
    '위임 운영자는 담당 그룹 구성원의 제출을 볼 수 있어야 합니다.'
);
learningAssert(
    ! $service->canViewMemberSubmission(2, 'delegate'),
    '일반 구성원은 다른 사용자의 제출을 관리 범위로 볼 수 없어야 합니다.'
);

$service->report(3, 1, false, '2026-01-02', '2026-01-31');
learningSame(
    '2026-01-02 00:00:00',
    $submissions->query?->submittedFrom(),
    '그룹 보고서 시작일은 해당 날짜의 시작 시각으로 조회해야 합니다.'
);
learningSame(
    '2026-01-31 23:59:59',
    $submissions->query?->submittedUntil(),
    '그룹 보고서 종료일은 해당 날짜의 마지막 시각까지 포함해야 합니다.'
);
$groupSiteClock = new class () implements Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-01-15 12:00:00',
            new DateTimeZone('Asia/Seoul')
        );
    }
};
$groupSiteTimeService = new GroupService(
    $repository,
    new LearningAuthorization(),
    $users,
    new LearningProvisioner($users),
    $passwords,
    $submissions,
    $groupSiteClock
);
$groupSiteTimeService->report(3, 1, false, '2026-01-02', '2026-01-31');
learningSame(
    '2026-01-01 15:00:00',
    $submissions->query?->submittedFrom(),
    '그룹 보고서 시작일은 WordPress 지역 날짜를 Judge UTC 조회 경계로 변환해야 합니다.'
);
learningSame(
    '2026-01-31 14:59:59',
    $submissions->query?->submittedUntil(),
    '그룹 보고서 종료일은 WordPress 지역 날짜의 끝까지 포함해 UTC로 변환해야 합니다.'
);
learningThrows(
    static fn () => $service->report(
        3,
        1,
        false,
        '2026-02-01',
        '2026-01-31'
    ),
    '그룹 보고서는 역전된 기간을 거부해야 합니다.'
);

$jobs = new LearningJobs();
$bulkImports = new GroupBulkMemberImportService($service, $users, $jobs);
$queued = $bulkImports->enqueue(
    3,
    1,
    "student\nnew-user,new@example.test,새 학습자,Initial!123"
);
learningSame(77, $queued->jobId(), '그룹 일괄 등록은 Jobs 작업을 반환해야 합니다.');
learningAssert(
    $jobs->jobType === 'group_member_csv_import'
        && $jobs->executionMode === 'selected'
        && $jobs->actorUserId === 3
        && count($jobs->targets) === 2
        && $jobs->targetSummary === ['group_id' => 1, 'candidate_count' => 2]
        && $jobs->accessPolicyKey === 'groups.member_csv_import',
    '그룹 일괄 등록은 그룹 범위와 항목을 Jobs에 등록해야 합니다.'
);
learningAssert(
    ! $repository->isActiveMember(1, 4),
    '작업 실행 전에는 새 계정이나 그룹 구성원을 동기 생성하면 안 됩니다.'
);
$newMemberTarget = $jobs->targets[1];
$handler = new GroupBulkMemberImportJobHandler($service);
$handled = $handler->handle(new JobWorkItem(
    new ClaimedJobItem(
        77,
        2,
        'group_member_csv_import',
        $newMemberTarget->targetType(),
        $newMemberTarget->targetKey(),
        null,
        null,
        str_repeat('a', 64),
        new FormDraftReference(
            'draft:v1:00000000-0000-4000-8000-000000000001'
        ),
        1,
        3,
        3,
        str_repeat('b', 64)
    ),
    $newMemberTarget->payload()
));
learningAssert(
    $handled->outcome() === 'succeeded'
        && $handled->resultCode() === 'account_created'
        && $repository->isActiveMember(1, 4),
    'Jobs 행 실행은 새 계정을 생성하고 그룹 활성 구성원으로 등록해야 합니다.'
);
learningAssert(
    ! array_key_exists('password', $handled->safeResult())
        && ! array_key_exists('temporary_password', $handled->safeResult()),
    '그룹 일괄 등록 안전 결과에는 비밀번호를 기록하면 안 됩니다.'
);
learningThrows(
    static fn () => $bulkImports->enqueue(
        3,
        1,
        'missing-password,new2@example.test,새 학습자'
    ),
    '새 계정의 초기 비밀번호가 없으면 작업 등록을 거부해야 합니다.'
);
$jobAccess = new GroupBulkMemberImportJobAccessRule($service);
learningAssert(
    $jobAccess->canView(3, new LearningJobContext(['group_id' => 1])),
    '그룹 위임 운영자는 담당 그룹의 Jobs 결과를 확인할 수 있어야 합니다.'
);
$repository->managers[1] = [];
learningAssert(
    ! $jobAccess->canControl(3, new LearningJobContext(['group_id' => 1])),
    '그룹 관리 권한이 회수되면 Jobs 제어 권한도 즉시 회수되어야 합니다.'
);
$revoked = $handler->handle(new JobWorkItem(
    new ClaimedJobItem(
        77,
        1,
        'group_member_csv_import',
        $jobs->targets[0]->targetType(),
        $jobs->targets[0]->targetKey(),
        null,
        null,
        str_repeat('c', 64),
        new FormDraftReference(
            'draft:v1:00000000-0000-4000-8000-000000000002'
        ),
        1,
        3,
        3,
        str_repeat('d', 64)
    ),
    $jobs->targets[0]->payload()
));
learningSame(
    'permission_revoked',
    $revoked->resultCode(),
    'Jobs 실행 시점에도 그룹 관리 권한을 다시 확인해야 합니다.'
);
$repository->managers[1] = [3];

$reset = $service->resetMemberPasswords(3, 1, [2]);
learningSame([2], $passwords->targets, '선택한 일반 구성원만 초기화해야 합니다.');
learningAssert($reset[0]['success'], '승인된 초기화는 성공 결과를 반환해야 합니다.');
learningSame(
    'student',
    $reset[0]['user_login'],
    '비밀번호 초기화 결과는 내부 번호 대신 로그인 아이디를 제공해야 합니다.'
);
learningThrows(
    static fn () => $service->resetMemberPasswords(1, 1, [3]),
    '그룹 운영자 계정은 그룹 비밀번호 초기화 대상에서 제외해야 합니다.'
);

$customReset = $service->resetMemberPasswords(3, 1, [2], 'Str0ng!Pass_word', false);
learningSame(
    'Str0ng!Pass_word',
    $passwords->customPassword,
    '직접 입력한 비밀번호를 초기화 서비스로 그대로 전달해야 합니다.'
);
learningSame(
    'Str0ng!Pass_word',
    $customReset[0]['temporary_password'],
    '직접 입력한 비밀번호가 초기화 결과에 반영되어야 합니다.'
);
$service->resetMemberPasswords(3, 1, [2], null, true);
learningAssert(
    $passwords->confirmWeakPassword,
    '약한 비밀번호 확인 여부를 초기화 서비스로 그대로 전달해야 합니다.'
);

$report = $service->report(3, 1);
learningSame(1, count($report->submissions()), '그룹 보고서는 제출 요약을 조회해야 합니다.');
learningSame(
    ['student', 'new-user'],
    $submissions->query?->userLogins(),
    '그룹 보고서는 활성 구성원의 모든 제출을 로그인 아이디로 조회해야 합니다.'
);
learningSame(
    null,
    $submissions->query?->groupId(),
    '그룹 밖 제출까지 포함하기 위해 그룹 문맥 필터를 사용하지 않아야 합니다.'
);

$groupHtml = new GroupHtml(new JudgeResultCatalog());
$normalGroupSubmissions = $groupHtml->submissionTable(
    $report,
    true,
    'student',
    false
);
learningAssert(
    str_contains($normalGroupSubmissions, 'href="/submissions/10?group_id=1"'),
    '일반 모드 구성원은 본인 제출 상세 링크를 볼 수 있어야 합니다.'
);
learningAssert(
    str_contains($normalGroupSubmissions, '<a class="ojp-row-link" href="/users/2">student</a>'),
    '그룹 제출 현황의 사용자 이름은 프로필로 이동하는 링크가 되어야 합니다.'
);
$restrictedGroupSubmissions = $groupHtml->submissionTable(
    $report,
    false,
    'student',
    false
);
learningAssert(
    ! str_contains($restrictedGroupSubmissions, 'href="/submissions/10"')
        && str_contains($restrictedGroupSubmissions, '<strong>10</strong>'),
    '수행 모드 구성원 목록은 상세 링크 없이 채점 결과를 표시해야 합니다.'
);
learningAssert(
    str_contains($groupHtml->submissionTable($report, true, 'delegate', true),
        'href="/submissions/10?group_id=1"'),
    '그룹 위임 운영자는 담당 구성원의 제출 상세 링크를 볼 수 있어야 합니다.'
);
$periodFilter = $groupHtml->periodFilter(
    '/groups/1/submissions',
    '2026-01-02',
    '2026-01-31'
);
learningAssert(
    str_contains($periodFilter, 'name="from" value="2026-01-02"')
        && str_contains($periodFilter, 'name="to" value="2026-01-31"')
        && str_contains($periodFilter, 'action="/groups/1/submissions"'),
    '그룹 보고서 기간 필터는 현재 값을 유지하며 같은 보고서 주소로 조회해야 합니다.'
);

$enabledFeatures = new LearningFeatures(true);
$performance = new PerformanceModePolicyRegistry();
$performance->register(
    'groups',
    new GroupPerformanceModePolicy($service, $enabledFeatures)
);
$staffScopes = new SubmissionStaffScopeRegistry();
$staffScopes->register(
    new GroupSubmissionStaffScope($service, $enabledFeatures)
);
$visibility = new SubmissionVisibilityPolicy(
    new LearningAuthorization(),
    $performance,
    $staffScopes
);
$summary = new SubmissionSummary(
    new SubmissionId(10),
    1000,
    'student',
    '학습자',
    6,
    4,
    10,
    1024,
    '2026-01-01 00:00:00',
    '2026-01-01 00:00:01',
    0,
    -1,
    null,
    null,
    true
);
learningSame(
    SubmissionAccessLevel::ResultOnly,
    $visibility->decide(2, 'student', $summary),
    '수행모드 구성원 본인은 제출 경로와 무관하게 결과만 봐야 합니다.'
);
learningSame(
    SubmissionAccessLevel::Full,
    $visibility->decide(3, 'delegate', $summary),
    '위임 운영자는 담당 구성원의 제출 상세와 코드를 볼 수 있어야 합니다.'
);
$disabledFeatures = new LearningFeatures(false);
learningAssert(
    ! (new GroupPerformanceModePolicy(
        $service,
        $disabledFeatures
    ))->restrictsOwnSubmissionDetails(2),
    'Groups OFF에서는 수행모드가 제출 상세를 제한하면 안 됩니다.'
);
learningAssert(
    ! (new GroupSubmissionStaffScope(
        $service,
        $disabledFeatures
    ))->canView(3, $summary),
    'Groups OFF에서는 그룹 담당자 범위를 조회하면 안 됩니다.'
);

$class = new LearningClass(
    31,
    '알고리즘 기초',
    'algorithm-basic',
    '',
    'private',
    'members',
    true,
    null,
    null,
    null,
    null,
    1,
    null
);
$classReport = new ClassReport(
    $class,
    [$users->findById(3), $users->findById(2)],
    [
        new SubmissionProblem(1000, '문제 1000'),
        new SubmissionProblem(1001, '문제 1001'),
    ],
    [
        new SubmissionProjection(
            901,
            1000,
            'student',
            4,
            6,
            10,
            1024,
            '2026-01-01 00:01:00',
            true
        ),
        new SubmissionProjection(
            902,
            1001,
            'student',
            4,
            6,
            10,
            1024,
            '2026-01-01 00:02:00',
            true
        ),
        new SubmissionProjection(
            903,
            1000,
            'delegate',
            6,
            6,
            10,
            1024,
            '2026-01-01 00:03:00',
            true
        ),
    ]
);
$classHtml = new ClassHtml(new JudgeResultCatalog());
$progressDescending = $classHtml->reportTable(
    $classReport,
    'progress-desc',
    '/classes/31/progress',
    'student'
);
learningAssert(
    str_contains($progressDescending, 'name="sort"')
        && str_contains($progressDescending, 'progress-desc" selected')
        && str_contains($progressDescending, '학습자</a> (나)')
        && strpos($progressDescending, '학습자</a> (나)')
            < strpos($progressDescending, '위임 운영자'),
    '수업 현황은 해결 수 높은 순 정렬과 현재 사용자 강조를 제공해야 합니다.'
);
learningAssert(
    str_contains($progressDescending, '최초 학습자 · 2026-01-01 00:01'),
    '수업 현황은 문제별 최초 해결자와 해결 시각을 함께 표시해야 합니다.'
);
$progressAscending = $classHtml->reportTable(
    $classReport,
    'progress-asc',
    '/classes/31/progress'
);
learningAssert(
    str_contains($progressAscending, 'progress-asc" selected')
        && strpos($progressAscending, '>위임 운영자')
            < strpos($progressAscending, '>학습자'),
    '수업 현황은 해결 수 낮은 순 정렬을 제공해야 합니다.'
);
learningAssert(
    str_contains($classHtml->reportTable(
        $classReport,
        'name-asc',
        '/classes/31/progress'
    ), 'name-asc" selected'),
    '수업 현황은 이름 순 정렬을 제공해야 합니다.'
);
learningAssert(
    ! str_contains($classHtml->submissionTable($classReport, false),
        'href="/submissions/901"'),
    '수행 모드 수업 제출 목록은 상세 링크를 만들면 안 됩니다.'
);
$contest = new Contest(
    20,
    '평가',
    '',
    '2026-01-01 00:00:00',
    '2026-01-01 01:00:00',
    false,
    false
);
$problem = new ContestProblem(1000, 0, 100.0);
$rankingCalculator = new ContestRankingCalculator();
$standardSettings = new ContestSettings(
    20,
    1,
    'public',
    null,
    'participants',
    'live',
    5,
    10,
    'pass_rate_correctness_base_v1',
    'standard_score_v1',
    'score_solved_time_v1',
    0,
    null,
    true,
    'penalty_before_accept',
    'none'
);
$contestAttempts = [
    new SubmissionProjection(
        18,
        1000,
        'student',
        5,
        6,
        0,
        0,
        '2026-01-01 00:05:00',
        true,
        0.2
    ),
    new SubmissionProjection(
        19,
        1000,
        'student',
        11,
        6,
        0,
        0,
        '2026-01-01 00:07:00',
        true,
        0.0
    ),
    new SubmissionProjection(
        20,
        1000,
        'student',
        6,
        6,
        0,
        0,
        '2026-01-01 00:10:00',
        true,
        0.5
    ),
    new SubmissionProjection(
        21,
        1000,
        'student',
        4,
        6,
        0,
        0,
        '2026-01-01 00:20:00',
        true,
        1.0
    ),
];
$standardRanking = $rankingCalculator->calculate(
    $contest,
    $standardSettings,
    [$users->findById(2)],
    [$problem],
    $contestAttempts
);
learningSame(91.0, $standardRanking[0]->score(), '표준 점수는 시간 점수를 내림하고 오답 감점을 반영해야 합니다.');
learningSame(1200, $standardRanking[0]->completionSeconds(), '실제 정답 제출 시간을 순위에 보존해야 합니다.');
learningSame(5, $standardRanking[0]->penalty(), '표준 점수의 오답 감점을 표시해야 합니다.');
$standardProblemResult = $standardRanking[0]->problemResults()[1000];
learningSame(
    [90.0, 6.0, 5, 91.0],
    [
        $standardProblemResult->correctnessScore(),
        $standardProblemResult->timeScore(),
        $standardProblemResult->penaltyPoints(),
        $standardProblemResult->score(),
    ],
    '표준 순위 상세는 정확성·시간·오답 감점과 최종 점수를 같은 계산 결과로 제공해야 합니다.'
);
learningSame(
    [4, 1, 1200, '2026-01-01 00:20:00'],
    [
        $standardProblemResult->attemptCount(),
        $standardProblemResult->penaltyAttemptCount(),
        $standardProblemResult->firstAcceptedElapsedSeconds(),
        $standardRanking[0]->latestSubmittedAt(),
    ],
    '순위 상세는 전체 제출 수·감점 제출 수·첫 정답 시간·최근 제출을 보존해야 합니다.'
);
$contestSiteClock = new class () implements Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-01-01 09:30:00',
            new DateTimeZone('Asia/Seoul')
        );
    }
};
$siteTimeRanking = (new ContestRankingCalculator(
    new ContestProblemResultCalculator($contestSiteClock)
))->calculate(
    new Contest(
        21,
        '지역시 평가',
        '',
        '2026-01-01 09:00:00',
        '2026-01-01 10:00:00',
        false,
        false
    ),
    $standardSettings,
    [$users->findById(2)],
    [$problem],
    $contestAttempts
);
learningSame(
    1200,
    $siteTimeRanking[0]->completionSeconds(),
    '대회 시작 지역시와 Judge 제출 UTC를 같은 절대 시각으로 변환해 경과 시간을 계산해야 합니다.'
);
$daylightSavingContest = new Contest(
    22,
    '서머타임 전환 평가',
    '',
    '2026-03-08 01:30:00',
    '2026-03-08 03:30:00',
    false,
    false
);
learningSame(
    3600,
    $daylightSavingContest->durationSeconds(
        new DateTimeZone('America/New_York')
    ),
    '대회 진행 시간은 WordPress 지역의 서머타임 전환도 반영해야 합니다.'
);
$noSubmissionParticipant = new UserIdentity(
    99,
    'no-submit',
    'no-submit@example.test',
    '미제출 학습자'
);
$statisticsRankings = $rankingCalculator->calculate(
    $contest,
    $standardSettings,
    [$users->findById(2), $noSubmissionParticipant],
    [$problem],
    $contestAttempts
);
$contestStatistics = (new ContestStatisticsCalculator())->calculate(
    [$users->findById(2), $noSubmissionParticipant],
    [$problem],
    $contestAttempts,
    $statisticsRankings
);
learningSame(
    [2, 4, 45.5, 0.5, 1],
    [
        $contestStatistics->participantCount(),
        $contestStatistics->submissionCount(),
        $contestStatistics->averageScore(),
        $contestStatistics->averageSolved(),
        $contestStatistics->noSubmissionCount(),
    ],
    '대회 통계는 참가자·유효 제출·평균 점수·평균 해결·미제출을 실시간 집계해야 합니다.'
);
$problemStatistics = $contestStatistics->problems()[0];
learningSame(
    [4, 1, 1, 100.0, 4.0],
    [
        $problemStatistics->submissionCount(),
        $problemStatistics->attemptedParticipantCount(),
        $problemStatistics->acceptedParticipantCount(),
        $problemStatistics->acceptedRate(),
        $problemStatistics->averageAttempts(),
    ],
    '문제별 통계는 제출·도전자·정답자·정답률·평균 시도를 구분해야 합니다.'
);
$reportClock = new class () implements Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-01-01 00:30:00',
            new DateTimeZone('Asia/Seoul')
        );
    }
};
$reportHtml = new ContestHtml(new JudgeResultCatalog(), $reportClock);
$standardReport = new ContestReport(
    $contest,
    $standardSettings,
    [$users->findById(2), $noSubmissionParticipant],
    [$problem],
    $contestAttempts,
    $statisticsRankings,
    $contestStatistics
);
$rankingHtml = $reportHtml->rankingTable(
    $standardReport,
    'score-desc',
    '/contests/20/ranking',
    'student'
);
foreach ([
    '정확성 90 + 시간 6 - 오답 5',
    '풀이 시간 00:20:00',
    'name="sort"',
    'score-desc" selected',
    '학습자 (나)',
] as $term) {
    learningAssert(
        str_contains($rankingHtml, $term),
        '대회 순위 상세 화면 요소가 없습니다: ' . $term
    );
}
$resultOnlyContestSubmissions = $reportHtml->submissionTable(
    $standardReport,
    false
);
learningAssert(
    ! str_contains($resultOnlyContestSubmissions, 'href="/submissions/')
        && ! str_contains($resultOnlyContestSubmissions, '<th>통과율</th>'),
    '수행 모드 대회 제출 목록은 상세 링크와 통과율 상세를 표시하면 안 됩니다.'
);
$statisticsHtml = $reportHtml->statisticsTable($standardReport);
foreach (['평균 점수', '미제출', '도전자', '정답률', '4.0회'] as $term) {
    learningAssert(
        str_contains($statisticsHtml, $term),
        '대회 통계 화면 요소가 없습니다: ' . $term
    );
}
$exportHandler = (new ReflectionClass(
    ExportContestActionHandler::class
))->newInstanceWithoutConstructor();
$rankingLines = (new ReflectionMethod(
    $exportHandler,
    'rankingLines'
))->invoke($exportHandler, $standardReport);
learningAssert(
    str_contains($rankingLines[0], 'problem_1000_penalty_attempts')
        && str_contains($rankingLines[1], ',91,1,5,1200,'),
    '순위 CSV는 문제별 점수·정답·시도·감점과 최종 순위 지표를 포함해야 합니다.'
);
$statisticsLines = (new ReflectionMethod(
    $exportHandler,
    'statisticsLines'
))->invoke($exportHandler, $standardReport);
learningAssert(
    count($statisticsLines) === 2
        && str_contains($statisticsLines[0], 'accepted_rate')
        && str_contains($statisticsLines[1], 'A,1000,100,4,1,1,100,4'),
    '통계 CSV는 문제별 제출·도전자·정답자·정답률·평균 시도를 포함해야 합니다.'
);
$compareRankingMetrics = new ReflectionMethod(
    $rankingCalculator,
    'compareMetrics'
);
learningSame(
    0,
    $compareRankingMetrics->invoke(
        $rankingCalculator,
        ['score' => 100.0, 'solved' => 1, 'completion' => 600, 'penalty' => 5],
        ['score' => 100.0, 'solved' => 1, 'completion' => 600, 'penalty' => 0],
        $standardSettings
    ),
    '표준 점수는 총점·해결 수·완료 시간이 같으면 표시용 감점과 무관하게 공동 순위여야 합니다.'
);

$icpcSettings = ContestSettings::fromRow(array_merge(
    $standardSettings->snapshot(),
    ['scoring_policy' => 'icpc_v1']
));
learningSame(
    ContestScoringPolicy::ICPC_RANKING,
    $icpcSettings->rankingPolicy(),
    'ICPC 채점 방식은 해결 수·패널티 순위 정책과 항상 짝을 이뤄야 합니다.'
);
$icpcRanking = $rankingCalculator->calculate(
    $contest,
    $icpcSettings,
    [$users->findById(2)],
    [$problem],
    $contestAttempts
);
learningSame(1, $icpcRanking[0]->solved(), 'ICPC 방식은 해결 문제 수를 계산해야 합니다.');
learningSame(2400, $icpcRanking[0]->penalty(), 'ICPC 방식은 풀이 초와 오답당 1200초를 합산해야 합니다.');
learningSame(
    1,
    $icpcRanking[0]->solved(),
    '출력 형식 오류와 컴파일 오류는 ICPC 오답 패널티에 포함하면 안 됩니다.'
);
$secondParticipant = new UserIdentity(
    4,
    'student2',
    'student2@example.test',
    '학습자 2'
);
$secondAttempts = [
    new SubmissionProjection(
        30,
        1000,
        'student2',
        6,
        6,
        0,
        0,
        '2026-01-01 00:10:00',
        true,
        0.5
    ),
    new SubmissionProjection(
        31,
        1000,
        'student2',
        4,
        6,
        0,
        0,
        '2026-01-01 00:20:00',
        true,
        1.0
    ),
];
$sharedRanking = $rankingCalculator->calculate(
    $contest,
    $icpcSettings,
    [$users->findById(2), $secondParticipant],
    [$problem],
    array_merge($contestAttempts, $secondAttempts)
);
learningSame(
    [1, 1],
    array_map(
        static fn ($row): int => $row->rank(),
        $sharedRanking
    ),
    '같은 해결 수·패널티·풀이 시간은 로그인 아이디와 무관하게 공동 순위여야 합니다.'
);
$differentCompletionAttempts = [
    new SubmissionProjection(
        40,
        1000,
        'student',
        4,
        6,
        0,
        0,
        '2026-01-01 00:25:00',
        true,
        1.0
    ),
    new SubmissionProjection(
        41,
        1000,
        'student2',
        6,
        6,
        0,
        0,
        '2026-01-01 00:02:00',
        true,
        0.3
    ),
    new SubmissionProjection(
        42,
        1000,
        'student2',
        4,
        6,
        0,
        0,
        '2026-01-01 00:05:00',
        true,
        1.0
    ),
];
$icpcPolicyTie = $rankingCalculator->calculate(
    $contest,
    $icpcSettings,
    [$users->findById(2), $secondParticipant],
    [$problem],
    $differentCompletionAttempts
);
learningSame(
    [1, 1],
    array_map(
        static fn ($row): int => $row->rank(),
        $icpcPolicyTie
    ),
    'ICPC는 해결 수와 패널티가 같으면 완료 시간이 달라도 공동 순위여야 합니다.'
);
learningSame(
    [1500, 1500],
    array_map(
        static fn ($row): int => $row->penalty(),
        $icpcPolicyTie
    ),
    'ICPC 패널티는 첫 정답까지의 초와 정답 전 오답당 1200초를 합산해야 합니다.'
);
learningSame(
    [1500, 300],
    array_map(
        static fn ($row): int => $row->completionSeconds(),
        $icpcPolicyTie
    ),
    'ICPC 공동 순위에서도 실제 완료 시간은 표시 데이터로 보존해야 합니다.'
);

$oiSettings = ContestSettings::fromRow(array_merge(
    $standardSettings->snapshot(),
    ['scoring_policy' => 'oi_v1']
));
learningSame(
    ContestScoringPolicy::OI_RANKING,
    $oiSettings->rankingPolicy(),
    'OI 채점 방식은 점수·해결 수·패널티 순위 정책과 항상 짝을 이뤄야 합니다.'
);
$oiRanking = $rankingCalculator->calculate(
    $contest,
    $oiSettings,
    [$users->findById(2)],
    [$problem],
    [$contestAttempts[2]]
);
learningSame(50.0, $oiRanking[0]->score(), 'OI 방식은 문제 배점에 통과율을 곱해야 합니다.');
learningSame(0, $oiRanking[0]->penalty(), 'OI 미해결 문제의 오답은 순위 패널티에 포함하면 안 됩니다.');
$oiPenaltyAttempts = [
    new SubmissionProjection(
        50,
        1000,
        'student',
        4,
        6,
        0,
        0,
        '2026-01-01 00:10:00',
        true,
        1.0
    ),
    new SubmissionProjection(
        51,
        1000,
        'student2',
        6,
        6,
        0,
        0,
        '2026-01-01 00:05:00',
        true,
        0.5
    ),
    new SubmissionProjection(
        52,
        1000,
        'student2',
        4,
        6,
        0,
        0,
        '2026-01-01 00:10:00',
        true,
        1.0
    ),
];
$oiPenaltyRanking = $rankingCalculator->calculate(
    $contest,
    $oiSettings,
    [$users->findById(2), $secondParticipant],
    [$problem],
    $oiPenaltyAttempts
);
learningSame(
    ['student', 'student2'],
    array_map(
        static fn ($row): string => $row->userLogin(),
        $oiPenaltyRanking
    ),
    'OI 동점자는 정답 전 오답이 적어 패널티가 낮은 참가자가 앞서야 합니다.'
);
learningSame(
    [600, 1800],
    array_map(
        static fn ($row): int => $row->penalty(),
        $oiPenaltyRanking
    ),
    'OI 패널티도 첫 정답까지의 초와 정답 전 오답당 1200초를 합산해야 합니다.'
);
learningSame(
    [1, 2],
    array_map(
        static fn ($row): int => $row->rank(),
        $oiPenaltyRanking
    ),
    'OI의 점수와 해결 수가 같으면 패널티로 순위를 결정해야 합니다.'
);
learningAssert(
    ! ContestScoringPolicy::isValidPair(
        ContestScoringPolicy::OI,
        ContestScoringPolicy::STANDARD_RANKING
    ),
    '서로 다른 채점 방식과 순위 정책 조합을 허용하면 안 됩니다.'
);
learningSame(
    'pass_rate_correctness_base_v1',
    $standardSettings->snapshot()['partial_score_policy'],
    '대회 보관 스냅샷은 비공개 속성을 명시적으로 직렬화해야 합니다.'
);

$judgeResults = new JudgeResultCatalog();
$contestClock = new class () implements Clock {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-01-01 09:30:00',
            new DateTimeZone('Asia/Seoul')
        );
    }
};
foreach ([
    new ClassHtml($judgeResults),
    new ContestHtml($judgeResults, $contestClock),
    new GroupHtml($judgeResults),
] as $renderer) {
    $resultLabel = new ReflectionMethod($renderer, 'resultLabel');
    learningSame(
        '<span class="ojp-badge ojp-badge--neutral">테스트 실행 완료</span>',
        $resultLabel->invoke($renderer, 13),
        '학습 공간 화면은 결과 코드 13의 중앙 문구를 사용해야 합니다.'
    );
    learningSame(
        '<span class="ojp-badge ojp-badge--warning ojp-verdict ojp-verdict--manual">수동 확인 대기</span>',
        $resultLabel->invoke($renderer, 14),
        '학습 공간 화면은 결과 코드 14의 중앙 문구를 사용해야 합니다.'
    );
    learningSame(
        '<span class="ojp-badge ojp-badge--danger ojp-verdict ojp-verdict--tle">시간 초과</span>',
        $resultLabel->invoke($renderer, 7),
        '학습 공간 화면은 시간 초과를 틀렸습니다와 다른 색 class로 보여야 합니다.'
    );
}

fwrite(
    STDOUT,
    sprintf(
        "R-07 학습 공간 단위 검사 통과: assertion %d개\n",
        $learningAssertions
    )
);
