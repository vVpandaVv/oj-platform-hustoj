<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassRepository;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassSaveCommand;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassStaffPolicy;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassProblem;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Classes\Infrastructure\WordPress\WordPressClassRepository;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Action\ClassMembershipActionHandler;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Console\ClassMemberPageHandler;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Route\ClassCatalogPageHandler;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResult;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeResults;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemAccessContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Shared\Selection\GroupMembershipProvider;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);

$classInvitationAssertions = 0;

function classInvitationAssert(bool $condition, string $message): void
{
    global $classInvitationAssertions;
    ++$classInvitationAssertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function classInvitationSame(
    mixed $expected,
    mixed $actual,
    string $message
): void {
    classInvitationAssert(
        $expected === $actual,
        $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true)
    );
}

function classInvitationThrows(
    callable $callback,
    string $expectedMessage,
    string $message
): void {
    try {
        $callback();
    } catch (DomainException $error) {
        classInvitationAssert(
            str_contains($error->getMessage(), $expectedMessage),
            $message . ' 실제 메시지: ' . $error->getMessage()
        );

        return;
    }

    throw new RuntimeException($message);
}

function classInvitationRuntimeThrows(
    callable $callback,
    string $message
): void {
    try {
        $callback();
    } catch (RuntimeException) {
        classInvitationAssert(true, $message);

        return;
    }

    throw new RuntimeException($message);
}

final class ClassInvitationRepository implements ClassRepository
{
    /** @var array<int, LearningClass> */
    public array $items;
    /** @var array<int, array<int, string>> */
    public array $statuses = [];
    /** @var array<int, list<ClassProblem>> */
    public array $problemItems = [];
    /** @var array<int, list<int>> */
    public array $instructors = [];
    /** @var array<int, list<int>> */
    public array $groups = [7 => [7]];
    /** @var list<array{class_id: int, user_id: int, expected: ?string, status: string}> */
    public array $transitions = [];
    public ?Closure $beforeTransition = null;
    public int $problemReads = 0;

    public function __construct()
    {
        $this->items = [
            1 => new LearningClass(
                1,
                '비공개 초대 수업',
                'private-invitation',
                '수락 전에는 보이면 안 되는 민감한 설명',
                'private',
                'private',
                true,
                '2026-09-01 00:00:00',
                '2026-12-31 23:59:59',
                null,
                null,
                10,
                null
            ),
            2 => new LearningClass(
                2,
                '공개 수업',
                'public-class',
                '공개 설명',
                'public',
                'private',
                true,
                null,
                null,
                null,
                null,
                10,
                null
            ),
            3 => new LearningClass(
                3,
                '종료된 초대 수업',
                'ended-invitation',
                '',
                'private',
                'private',
                true,
                null,
                '2026-09-09 23:59:59',
                null,
                null,
                10,
                null
            ),
            4 => new LearningClass(
                4,
                '중지된 초대 수업',
                'disabled-invitation',
                '',
                'private',
                'private',
                false,
                null,
                null,
                null,
                null,
                10,
                null
            ),
            5 => new LearningClass(
                5,
                '삭제된 초대 수업',
                'deleted-invitation',
                '',
                'private',
                'private',
                true,
                null,
                null,
                null,
                null,
                10,
                '2026-09-09 10:00:00'
            ),
            6 => new LearningClass(
                6,
                '종료 경계 초대 수업',
                'boundary-invitation',
                '',
                'private',
                'private',
                true,
                null,
                '2026-09-10 12:00:00',
                null,
                null,
                10,
                null
            ),
            7 => new LearningClass(
                7,
                '그룹 참여 수업',
                'group-class',
                '그룹 참여자는 정상 수업 화면을 봅니다.',
                'group_only',
                'private',
                true,
                null,
                null,
                null,
                null,
                10,
                null
            ),
        ];
        $this->problemItems = [
            1 => [new ClassProblem(1001, 0, true, null, null)],
            7 => [new ClassProblem(7001, 0, true, null, null)],
        ];
    }

    public function find(int $classId): ?LearningClass
    {
        return $this->items[$classId] ?? null;
    }

    public function visibleFor(int $userId): array
    {
        return array_values(array_filter(
            $this->items,
            function (LearningClass $class) use ($userId): bool {
                if ($class->isDeleted() || ! $class->isEnabled()) {
                    return false;
                }

                return in_array($class->visibility(), ['public', 'password'], true)
                    || $class->ownerUserId() === $userId
                    || ($this->statuses[$class->id()][$userId] ?? null) === 'active'
                    || in_array(
                        $userId,
                        $this->instructors[$class->id()] ?? [],
                        true
                    );
            }
        ));
    }

    public function invitedFor(int $userId): array
    {
        return array_values(array_filter(
            $this->items,
            fn (LearningClass $class): bool =>
                ($this->statuses[$class->id()][$userId] ?? null) === 'invited'
        ));
    }

    public function linkedToGroups(array $groupIds): array
    {
        return array_values(array_filter(
            $this->items,
            fn (LearningClass $class): bool =>
                $class->visibility() === 'group_only'
                && array_intersect(
                    $groupIds,
                    $this->groups[$class->id()] ?? []
                ) !== []
        ));
    }

    public function managedBy(int $userId, bool $all): array
    {
        return array_values(array_filter(
            $this->items,
            fn (LearningClass $class): bool => $all
                || $class->ownerUserId() === $userId
                || in_array(
                    $userId,
                    $this->instructors[$class->id()] ?? [],
                    true
                )
        ));
    }

    public function problems(int $classId): array
    {
        ++$this->problemReads;

        return $this->problemItems[$classId] ?? [];
    }

    public function participantUserIds(
        int $classId,
        string $status = 'active'
    ): array {
        $userIds = [];
        foreach ($this->statuses[$classId] ?? [] as $userId => $current) {
            if ($current === $status) {
                $userIds[] = $userId;
            }
        }

        return $userIds;
    }

    public function participantApprovedAtMap(int $classId): array
    {
        return [];
    }

    public function instructorUserIds(int $classId): array
    {
        return $this->instructors[$classId] ?? [];
    }

    public function groupIds(int $classId): array
    {
        return $this->groups[$classId] ?? [];
    }

    public function isActiveParticipant(int $classId, int $userId): bool
    {
        return ($this->statuses[$classId][$userId] ?? null) === 'active';
    }

    public function enrollmentStatus(int $classId, int $userId): ?string
    {
        return $this->statuses[$classId][$userId] ?? null;
    }

    public function isActiveInstructor(int $classId, int $userId): bool
    {
        return in_array(
            $userId,
            $this->instructors[$classId] ?? [],
            true
        );
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
    ): void {
        $this->statuses[$classId][$userId] = $status;
    }

    public function transitionEnrollmentStatus(
        int $classId,
        int $userId,
        ?string $expectedStatus,
        string $status,
        int $actorUserId
    ): bool {
        if ($this->beforeTransition !== null) {
            $hook = $this->beforeTransition;
            $this->beforeTransition = null;
            $hook($this, $classId, $userId, $expectedStatus, $status);
        }
        $current = $this->statuses[$classId][$userId] ?? null;
        if ($current !== $expectedStatus) {
            return false;
        }
        $this->statuses[$classId][$userId] = $status;
        $this->transitions[] = [
            'class_id' => $classId,
            'user_id' => $userId,
            'expected' => $expectedStatus,
            'status' => $status,
        ];

        return true;
    }

    public function setDeleted(
        int $classId,
        bool $deleted,
        int $actorUserId
    ): void {
    }
}

final class ClassInvitationAuthorization implements Authorization
{
    public function can(
        int $userId,
        string $capability,
        ?PermissionContext $context = null
    ): bool {
        return false;
    }

    public function canAny(int $userId, array $capabilities): bool
    {
        return false;
    }

    public function isSuperAdmin(int $userId): bool
    {
        return false;
    }
}

final class ClassInvitationUsers implements WordPressUserGateway
{
    /** @var array<int, UserIdentity> */
    private array $users;

    public function __construct()
    {
        $this->users = [
            10 => new UserIdentity(10, 'owner', 'owner@example.test', '수업 소유자'),
            20 => new UserIdentity(20, 'invitee', 'invitee@example.test', '초대 학생'),
            30 => new UserIdentity(30, 'other', 'other@example.test', '다른 학생'),
        ];
    }

    public function currentUser(): ?UserIdentity
    {
        return $this->users[20];
    }

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

    public function findByEmail(string $email): ?UserIdentity
    {
        return null;
    }

    public function verifyCredentials(
        string $userLogin,
        string $password
    ): ?UserIdentity {
        return null;
    }

    public function establishSession(int $userId, bool $remember): void
    {
    }

    public function createUser(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        string $role
    ): UserIdentity {
        throw new LogicException('사용하지 않는 테스트 경로입니다.');
    }

    public function updateUser(
        int $userId,
        string $email,
        string $displayName
    ): UserIdentity {
        return $this->users[$userId];
    }

    public function setRoles(int $userId, array $roles): void
    {
    }

    public function setPassword(int $userId, string $password): void
    {
    }

    public function invalidateSessions(int $userId): void
    {
    }

    public function requestPasswordReset(string $userLogin): void
    {
    }

    public function resetPasswordWithKey(
        string $userLogin,
        string $resetKey,
        string $newPassword
    ): void {
    }

    public function deleteUser(int $userId): void
    {
    }

    public function findMany(array $userIds): array
    {
        return array_values(array_filter(array_map(
            fn (int $userId): ?UserIdentity => $this->users[$userId] ?? null,
            $userIds
        )));
    }
}

final class ClassInvitationProblems implements SubmissionProblemProvider
{
    public function findVisible(
        int $userId,
        int $problemId,
        ?ProblemAccessContext $context = null
    ): ?SubmissionProblem {
        return null;
    }

    public function findVisibleMany(
        int $userId,
        array $problemIds,
        ?ProblemAccessContext $context = null
    ): array {
        return [];
    }

    public function listVisible(string $keyword = '', int $limit = 100): array
    {
        return [];
    }

    public function codePolicy(
        int $userId,
        int $problemId,
        ?ProblemAccessContext $context = null
    ): SubmissionCodePolicy {
        throw new LogicException('사용하지 않는 테스트 경로입니다.');
    }
}

final class ClassInvitationSubmissions implements SubmissionProjectionProvider
{
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
        return [];
    }

    public function report(SubmissionProjectionQuery $query): array
    {
        return [];
    }

    public function searchAll(
        int $actorUserId,
        string $actorUserLogin,
        SubmissionProjectionQuery $query
    ): array {
        return [];
    }

    public function reportAll(SubmissionProjectionQuery $query): array
    {
        return [];
    }
}

final class ClassInvitationGroups implements ChoiceProvider, GroupMembershipProvider
{
    /** @var array<int, list<int>> */
    public array $userGroups = [20 => [7]];

    public function isAvailable(): bool
    {
        return true;
    }

    public function choicesFor(int $userId, array $includedIds = []): array
    {
        return [7 => '테스트 그룹'];
    }

    public function activeGroupIdsForUser(int $userId): array
    {
        return $this->userGroups[$userId] ?? [];
    }

    public function activeUserIdsForGroups(array $groupIds): array
    {
        return in_array(7, $groupIds, true) ? [20] : [];
    }
}

final class ClassInvitationClock implements Clock
{
    private DateTimeImmutable $current;

    public function __construct(string $current)
    {
        $this->current = new DateTimeImmutable($current);
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function set(string $current): void
    {
        $this->current = new DateTimeImmutable($current);
    }
}

final class ClassInvitationAccount implements CurrentAccountProvider
{
    public function __construct(private readonly UserIdentity $identity)
    {
    }

    public function current(): CurrentAccount
    {
        return new CurrentAccount($this->identity, null);
    }
}

final class ClassInvitationPageResponder implements PageResponder
{
    public function guest(
        string $title,
        string $content,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse {
        return HttpResponse::html($content, $status);
    }

    public function authenticated(
        string $title,
        string $content,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse {
        return HttpResponse::html($content, $status);
    }
}

final class ClassInvitationConsoleResponder implements ConsoleResponder
{
    public function respond(
        string $title,
        string $content,
        string $currentMenu,
        HttpRequest $request,
        int $status = 200
    ): HttpResponse {
        return HttpResponse::html($content, $status);
    }
}

final class ClassInvitationActionUrls implements ActionUrlProvider
{
    public function actionUrl(): string
    {
        return '/wp-admin/admin-post.php';
    }

    public function ajaxUrl(): string
    {
        return '/wp-admin/admin-ajax.php';
    }
}

final class ClassInvitationSecurity implements ActionSecurity
{
    public function hiddenFields(string $actionKey): string
    {
        return '<input type="hidden" name="action" value="' . $actionKey . '">';
    }

    public function verify(string $actionKey, HttpRequest $request): bool
    {
        return true;
    }
}

final class ClassInvitationResults implements JudgeResults
{
    public function find(int $code): JudgeResult
    {
        return new JudgeResult($code, 'test', '테스트', 'neutral', false);
    }

    public function all(): array
    {
        return [];
    }
}

final class ClassInvitationGuard implements ActionAccessGuard
{
    public function __construct(private readonly UserIdentity $actor)
    {
    }

    public function verifyNonce(string $actionKey, HttpRequest $request): void
    {
    }

    public function actor(
        string $actionKey,
        HttpRequest $request,
        string $capability
    ): UserIdentity {
        return $this->actor;
    }

    public function authenticatedActor(
        string $actionKey,
        HttpRequest $request
    ): UserIdentity {
        return $this->actor;
    }

    public function throttle(
        string $scope,
        string $identifier,
        int $limit,
        int $windowSeconds
    ): void {
    }
}

final class ClassInvitationResponses implements ActionResponse
{
    public ?string $path = null;

    public function redirect(
        string $path,
        UiMessage $message,
        int $userId = 0
    ): HttpResponse {
        $this->path = $path;

        return HttpResponse::redirect($path);
    }
}

final class ClassInvitationErrors implements ErrorReporter
{
    public array $errors = [];

    public function report(Throwable $error): void
    {
        $this->errors[] = $error;
    }
}

final class ClassInvitationDatabase implements ApplicationDatabase
{
    public string $rowsSql = '';
    public array $rowsValues = [];
    public array $updateWhere = [];
    public array $insertData = [];
    public int $updateResult = 1;
    public bool $failInsert = false;
    public int $updateCalls = 0;
    public int $insertCalls = 0;

    public function row(string $sql, array $values = []): ?array
    {
        return null;
    }

    public function rows(string $sql, array $values = []): array
    {
        $this->rowsSql = $sql;
        $this->rowsValues = $values;

        return [];
    }

    public function value(string $sql, array $values = []): mixed
    {
        return null;
    }

    public function insert(
        string $table,
        array $data,
        array $formats = []
    ): int {
        ++$this->insertCalls;
        $this->insertData = $data;
        if ($this->failInsert) {
            throw new RuntimeException('고유키 충돌');
        }

        return 1;
    }

    public function update(
        string $table,
        array $data,
        array $where,
        array $formats = [],
        array $whereFormats = []
    ): int {
        ++$this->updateCalls;
        $this->updateWhere = $where;

        return $this->updateResult;
    }

    public function delete(
        string $table,
        array $where,
        array $whereFormats = []
    ): int {
        return 0;
    }

    public function beginTransaction(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }
}

function classInvitationService(
    ClassInvitationRepository $repository,
    ClassInvitationUsers $users,
    ClassInvitationGroups $groups,
    ClassInvitationClock $clock
): ClassService {
    return new ClassService(
        $repository,
        new ClassStaffPolicy($repository, new ClassInvitationAuthorization()),
        $users,
        new ClassInvitationProblems(),
        new ClassInvitationSubmissions(),
        $groups,
        $clock
    );
}

function classInvitationRoute(
    string $name,
    string $path,
    array $parameters = [],
    string $area = 'public'
): RouteMatch {
    return new RouteMatch(RouteDefinition::fromArray([
        'name' => $name,
        'owner_module' => 'classes',
        'feature_key' => 'classes',
        'path' => $path,
        'area' => $area,
        'methods' => ['GET'],
        'authentication' => 'authenticated',
        'response_type' => 'html',
    ]), $parameters);
}

$repository = new ClassInvitationRepository();
$users = new ClassInvitationUsers();
$groups = new ClassInvitationGroups();
$clock = new ClassInvitationClock('2026-09-10 12:00:00');
$service = classInvitationService($repository, $users, $groups, $clock);
$repository->statuses = [
    1 => [20 => 'invited'],
    3 => [20 => 'invited'],
    4 => [20 => 'invited'],
    5 => [20 => 'invited'],
    6 => [20 => 'invited'],
    7 => [20 => 'invited'],
];

$invitationIds = array_map(
    static fn (LearningClass $class): int => $class->id(),
    $service->pendingInvitationsFor(20)
);
classInvitationSame(
    [1, 6],
    $invitationIds,
    '유효한 본인 초대만 별도 목록에 표시해야 합니다.'
);
classInvitationSame(
    1,
    $service->pendingInvitation(1, 20)?->id(),
    '초대받은 본인은 비공개 수업의 최소 초대 정보를 조회할 수 있어야 합니다.'
);
classInvitationThrows(
    static fn () => $service->findVisible(1, 20),
    '접근할 수 있는 수업',
    'invited를 일반 참여자 권한으로 확대하면 안 됩니다.'
);
classInvitationSame(
    [],
    $service->classProblemsForUser(1, 20),
    '수락 전에는 문제 목록을 조회할 수 없어야 합니다.'
);
classInvitationAssert(
    ! $service->canViewProblem(20, 1, 1001),
    '수락 전에는 문제 상세 권한도 없어야 합니다.'
);
foreach (['accept', 'decline'] as $operation) {
    classInvitationThrows(
        static fn () => $service->membership(30, 1, $operation, 20),
        '본인의 수업 참여 상태만',
        '다른 사용자가 초대 대상자의 수락·거절 요청을 대신 보낼 수 없어야 합니다.'
    );
    classInvitationSame(
        'invited',
        $repository->statuses[1][20],
        '대리 수락·거절 거부 뒤 초대 상태를 보존해야 합니다.'
    );
}
classInvitationThrows(
    static fn () => $service->report(20, 'invitee', 1, true),
    '학습 현황을 볼 권한',
    '초대 수락 전에는 본인의 수업 보고서도 볼 수 없어야 합니다.'
);
classInvitationSame(
    null,
    $service->pendingInvitation(7, 20),
    '이미 그룹으로 참여 중이면 남은 invited 행이 정상 수업 화면을 가리면 안 됩니다.'
);
classInvitationSame(
    7,
    $service->findVisible(7, 20)->id(),
    '그룹 참여자는 기존 수업 접근 권한을 유지해야 합니다.'
);
$repository->instructors[1] = [20];
classInvitationSame(
    null,
    $service->pendingInvitation(1, 20),
    '이미 담당자인 사용자는 남은 invited 행 때문에 초대 화면으로 전환되면 안 됩니다.'
);
classInvitationSame(
    1,
    $service->findVisible(1, 20)->id(),
    '담당자는 기존 수업 접근 권한을 유지해야 합니다.'
);
unset($repository->instructors[1]);

$html = new ClassHtml(new ClassInvitationResults());
$catalog = new ClassCatalogPageHandler(
    new ClassInvitationPageResponder(),
    new ClassInvitationAccount($users->findById(20)),
    $service,
    new ClassInvitationActionUrls(),
    new ClassInvitationSecurity(),
    $html,
    new PaginationView()
);
$indexResponse = $catalog->handle(
    classInvitationRoute('classes.index', '/classes'),
    new HttpRequest('GET', '/classes')
);
classInvitationAssert(
    str_contains($indexResponse->body(), '수락 대기 중인 초대')
        && str_contains($indexResponse->body(), '비공개 초대 수업')
        && str_contains($indexResponse->body(), '초대 수락 대기'),
    '학생 수업 목록에 초대 대기 항목을 별도 표시해야 합니다.'
);
classInvitationAssert(
    ! str_contains($indexResponse->body(), '수락 전에는 보이면 안 되는 민감한 설명'),
    '초대 목록에는 수업 설명을 노출하면 안 됩니다.'
);

$repository->problemReads = 0;
$detailResponse = $catalog->handle(
    classInvitationRoute(
        'classes.show',
        '/classes/{class_id}',
        ['class_id' => '1']
    ),
    new HttpRequest('GET', '/classes/1')
);
classInvitationSame(200, $detailResponse->status(), '유효한 초대 상세는 열려야 합니다.');
classInvitationAssert(
    str_contains($detailResponse->body(), '비공개 초대 수업')
        && str_contains($detailResponse->body(), 'value="accept"')
        && str_contains($detailResponse->body(), 'value="decline"'),
    '초대 상세에는 수업명과 수락·거절 작업이 있어야 합니다.'
);
classInvitationAssert(
    ! str_contains($detailResponse->body(), '민감한 설명')
        && ! str_contains($detailResponse->body(), '1001')
        && ! str_contains($detailResponse->body(), '수업 문제')
        && ! str_contains($detailResponse->body(), '내 제출'),
    '초대 상세에는 설명, 문제, 제출 또는 학습 탭을 노출하면 안 됩니다.'
);
classInvitationSame(
    0,
    $repository->problemReads,
    '초대 상세 렌더링은 문제 저장소를 호출하지 않아야 합니다.'
);

$otherCatalog = new ClassCatalogPageHandler(
    new ClassInvitationPageResponder(),
    new ClassInvitationAccount($users->findById(30)),
    $service,
    new ClassInvitationActionUrls(),
    new ClassInvitationSecurity(),
    $html,
    new PaginationView()
);
$otherResponse = $otherCatalog->handle(
    classInvitationRoute(
        'classes.show',
        '/classes/{class_id}',
        ['class_id' => '1']
    ),
    new HttpRequest('GET', '/classes/1')
);
classInvitationSame(
    403,
    $otherResponse->status(),
    '초대받지 않은 다른 사용자는 비공개 초대 상세를 볼 수 없어야 합니다.'
);

foreach ([3, 4, 5] as $unavailableClassId) {
    classInvitationThrows(
        static fn () => $service->pendingInvitation($unavailableClassId, 20),
        '더 이상 수락할 수 있는',
        '종료·중지·삭제된 수업의 초대 상세는 거부해야 합니다.'
    );
    classInvitationThrows(
        static fn () => $service->membership(
            20,
            $unavailableClassId,
            'accept',
            20
        ),
        '더 이상 수락할 수 있는',
        '종료·중지·삭제된 수업 초대는 수락할 수 없어야 합니다.'
    );
}

$clock->set('2026-09-10 12:00:01');
classInvitationThrows(
    static fn () => $service->pendingInvitation(6, 20),
    '더 이상 수락할 수 있는',
    '종료 경계를 지난 즉시 초대를 열 수 없어야 합니다.'
);
$clock->set('2026-09-10 12:00:00');

foreach (['pending', 'rejected', 'left', 'removed'] as $nonInvitationStatus) {
    $repository->statuses[1][20] = $nonInvitationStatus;
    classInvitationThrows(
        static fn () => $service->membership(20, 1, 'accept', 20),
        '수락할 수 있는 초대가 없습니다',
        'invited가 아닌 상태는 과거 초대 URL로 수락할 수 없어야 합니다.'
    );
    classInvitationSame(
        $nonInvitationStatus,
        $repository->statuses[1][20],
        '잘못된 초대 수락 요청이 현재 참여 상태를 바꾸면 안 됩니다.'
    );
}
$repository->statuses[1][20] = 'active';
classInvitationThrows(
    static fn () => $service->membership(10, 1, 'invite', 20),
    '이미 참여 중인 사용자',
    '관리자가 active 참여자를 invited로 강등하면 안 됩니다.'
);
classInvitationSame(
    'active',
    $repository->statuses[1][20],
    'active 초대 거부 뒤 기존 참여 상태를 보존해야 합니다.'
);

$repository->statuses[1][20] = 'removed';
$service->membership(10, 1, 'invite', 20);
classInvitationSame(
    'invited',
    $repository->statuses[1][20],
    'removed 사용자는 관리자의 명시적 재초대 뒤 invited가 되어야 합니다.'
);
$service->membership(20, 1, 'accept', 20);
classInvitationSame(
    'active',
    $repository->statuses[1][20],
    '초대받은 본인의 최초 수락만 active로 전이되어야 합니다.'
);
classInvitationSame(
    1,
    $service->findVisible(1, 20)->id(),
    '수락 성공 뒤 비공개 수업의 정상 상세 접근이 열려야 합니다.'
);
classInvitationSame(
    1001,
    $service->classProblemsForUser(1, 20)[0]->problemId() ?? null,
    '수락 성공 뒤 수업 문제 목록 접근이 열려야 합니다.'
);
classInvitationAssert(
    $service->canViewProblem(20, 1, 1001),
    '수락 성공 뒤 공개 중인 수업 문제 상세 접근이 열려야 합니다.'
);
classInvitationThrows(
    static fn () => $service->membership(20, 1, 'accept', 20),
    '수락할 수 있는 초대가 없습니다',
    '수락 재전송은 성공하면 안 됩니다.'
);

$repository->statuses[1][20] = 'removed';
$repository->beforeTransition = static function (
    ClassInvitationRepository $repository,
    int $classId,
    int $userId
): void {
    $repository->statuses[$classId][$userId] = 'active';
};
classInvitationThrows(
    static fn () => $service->membership(10, 1, 'invite', 20),
    '참여 상태가 이미 변경',
    '기존행 재초대와 학생 수락이 경합하면 CAS가 관리자 덮어쓰기를 거부해야 합니다.'
);
classInvitationSame(
    'active',
    $repository->statuses[1][20],
    '기존행 경합 뒤 학생의 active 상태를 보존해야 합니다.'
);

$repository->statuses[1][20] = 'invited';
$repository->beforeTransition = static function (
    ClassInvitationRepository $repository,
    int $classId,
    int $userId
): void {
    $repository->statuses[$classId][$userId] = 'removed';
};
classInvitationThrows(
    static fn () => $service->membership(20, 1, 'accept', 20),
    '초대 상태가 이미 변경',
    '수락 처리 중 관리자가 제외하면 stale 수락 CAS가 실패해야 합니다.'
);
classInvitationSame(
    'removed',
    $repository->statuses[1][20],
    'stale 수락 실패 뒤 최신 removed 상태를 보존해야 합니다.'
);

$repository->statuses[1][20] = 'invited';
$repository->beforeTransition = static function (
    ClassInvitationRepository $repository,
    int $classId,
    int $userId
): void {
    $repository->statuses[$classId][$userId] = 'rejected';
};
classInvitationThrows(
    static fn () => $service->membership(20, 1, 'decline', 20),
    '초대 상태가 이미 변경',
    '거절 처리 중 상태가 바뀌면 stale 거절 CAS가 실패해야 합니다.'
);
classInvitationSame(
    'rejected',
    $repository->statuses[1][20],
    'stale 거절 실패 뒤 최신 rejected 상태를 보존해야 합니다.'
);

unset($repository->statuses[1][20]);
$repository->beforeTransition = static function (
    ClassInvitationRepository $repository,
    int $classId,
    int $userId
): void {
    $repository->statuses[$classId][$userId] = 'active';
};
classInvitationThrows(
    static fn () => $service->membership(10, 1, 'invite', 20),
    '참여 상태가 이미 변경',
    'null 신규 초대 경합도 insert-only 비교로 기존 active 생성을 보존해야 합니다.'
);
classInvitationSame(
    'active',
    $repository->statuses[1][20],
    'null 신규 초대 경합 뒤에도 active를 invited로 덮으면 안 됩니다.'
);

$repository->statuses[1][20] = 'invited';
$memberPage = new ClassMemberPageHandler(
    new ClassInvitationConsoleResponder(),
    new ClassInvitationAccount($users->findById(10)),
    $users,
    $service,
    new ClassInvitationActionUrls(),
    new ClassInvitationSecurity(),
    $html
);
$memberResponse = $memberPage->handle(
    classInvitationRoute(
        'console.classes.participants',
        '/oj-console/classes/{class_id}/participants',
        ['class_id' => '1'],
        'console'
    ),
    new HttpRequest('GET', '/oj-console/classes/1/participants')
);
classInvitationAssert(
    str_contains($memberResponse->body(), 'invitee')
        && str_contains($memberResponse->body(), '초대 수락 대기'),
    '관리자 참여자 화면에 invited 사용자를 구분해 표시해야 합니다.'
);
classInvitationAssert(
    ! str_contains($memberResponse->body(), 'name="operation" value="remove"'),
    '초대 대기 행에는 승인되지 않은 추가 상태 변경 버튼을 만들면 안 됩니다.'
);

$responses = new ClassInvitationResponses();
$errors = new ClassInvitationErrors();
$membershipAction = new ClassMembershipActionHandler(
    $service,
    new ClassInvitationGuard($users->findById(20)),
    $responses,
    $errors
);
$declineResponse = $membershipAction->handle(new HttpRequest(
    'POST',
    '/wp-admin/admin-post.php',
    [],
    ['class_id' => 1, 'operation' => 'decline']
));
classInvitationSame(
    '/classes',
    $declineResponse->headers()['Location'] ?? null,
    '초대 거절 성공 뒤 접근 불가 상세가 아니라 수업 목록으로 이동해야 합니다.'
);
classInvitationSame(
    'rejected',
    $repository->statuses[1][20],
    '초대 거절은 exact invited 상태를 rejected로 바꿔야 합니다.'
);
classInvitationSame([], $errors->errors, '정상 거절 흐름에 내부 오류가 없어야 합니다.');

$database = new ClassInvitationDatabase();
$wordpressRepository = new WordPressClassRepository($database, $clock);
$wordpressRepository->invitedFor(20);
classInvitationAssert(
    str_contains($database->rowsSql, 'e.status = %s')
        && $database->rowsValues === [20, 'invited'],
    '실제 저장소의 초대 목록 SQL은 본인과 exact invited 상태를 사용해야 합니다.'
);
classInvitationAssert(
    $wordpressRepository->transitionEnrollmentStatus(
        1,
        20,
        'invited',
        'active',
        20
    ),
    '실제 저장소 CAS는 1행 갱신 때 성공해야 합니다.'
);
classInvitationSame(
    ['class_id' => 1, 'user_id' => 20, 'status' => 'invited'],
    $database->updateWhere,
    '실제 저장소 CAS WHERE에는 class, user, expected status가 모두 있어야 합니다.'
);
$database->updateResult = 0;
classInvitationAssert(
    ! $wordpressRepository->transitionEnrollmentStatus(
        1,
        20,
        'invited',
        'active',
        20
    ),
    '실제 저장소 CAS는 stale 상태에서 false를 반환해야 합니다.'
);
$updatesBeforeInsert = $database->updateCalls;
classInvitationAssert(
    $wordpressRepository->transitionEnrollmentStatus(
        1,
        30,
        null,
        'invited',
        10
    ),
    '신규 초대는 insert-only로 생성되어야 합니다.'
);
classInvitationSame(
    $updatesBeforeInsert,
    $database->updateCalls,
    '신규 초대는 기존행 UPDATE를 시도하면 안 됩니다.'
);
classInvitationSame(
    ['status' => 'invited', 'invited_at' => '2026-09-10 12:00:00',
        'invited_by_user_id' => 10, 'class_id' => 1, 'user_id' => 30],
    $database->insertData,
    '신규 초대 insert에는 상태, 감사 시각, actor와 고유 키가 모두 있어야 합니다.'
);
$database->failInsert = true;
classInvitationRuntimeThrows(
    static fn () => $wordpressRepository->transitionEnrollmentStatus(
        1,
        30,
        null,
        'invited',
        10
    ),
    '신규 초대 고유키 경합은 기존행 갱신 없이 실패해야 합니다.'
);
classInvitationSame(
    $updatesBeforeInsert,
    $database->updateCalls,
    '신규 초대 insert 실패 뒤에도 UPDATE fallback을 수행하면 안 됩니다.'
);

fwrite(
    STDOUT,
    sprintf(
        "수업 초대 단위 검사 통과: assertion %d개\n",
        $classInvitationAssertions
    )
);
