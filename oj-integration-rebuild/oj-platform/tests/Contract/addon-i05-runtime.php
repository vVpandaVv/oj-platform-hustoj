<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskDefinitionV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextSnapshotRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonTeachingContextQueryV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonAiTaskRuntimeGateway;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCatalogEntry;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\AddonManifest;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonContextGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassContextAccess;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupContextAccess;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretReference;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SecretVault;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskAssignmentSnapshot;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRequest;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskResponse;
use CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

function i05Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function i05Same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' 예상=' . var_export($expected, true)
            . ' 실제=' . var_export($actual, true)
        );
    }
}

// --- Static check: the two new gateways never touch a raw secret or base URL ---

foreach ([
    $pluginRoot . '/src/Kernel/Addon/AddonAiTaskRuntimeGateway.php',
    $pluginRoot . '/src/Kernel/Addon/RealAddonRuntimeServiceFactory.php',
] as $gatewayFile) {
    $source = (string) file_get_contents($gatewayFile);
    i05Assert(
        ! str_contains($source, '->secret(') && ! str_contains($source, '->baseUrl('),
        $gatewayFile . '은 secret()/baseUrl()을 직접 호출하면 안 됩니다.'
    );
}

// ===================== Context gateway fixtures =====================

final class FakeClassContextAccess implements ClassContextAccess
{
    /** @var array<int, LearningClass> */
    public array $classes = [];
    /** @var array<int, list<int>> */
    public array $managers = [];
    /** @var array<int, list<int>> */
    public array $participants = [];
    public bool $throwOnManage = false;

    public function findById(int $classId): ?LearningClass
    {
        return $this->classes[$classId] ?? null;
    }

    public function canManage(int $userId, LearningClass $class): bool
    {
        if ($this->throwOnManage) {
            throw new RuntimeException('SECRET-DB-DETAIL-should-never-leak');
        }

        return in_array($userId, $this->managers[$class->id()] ?? [], true);
    }

    public function managedBy(int $userId): array
    {
        return array_values(array_filter(
            $this->classes,
            fn (LearningClass $class): bool => $this->canManage($userId, $class)
        ));
    }

    public function learningBy(int $userId): array
    {
        return array_values(array_filter(
            $this->classes,
            fn (LearningClass $class): bool => $class->isEnabled()
                && ! $class->isDeleted()
                && $this->isActiveLearner($class->id(), $userId)
        ));
    }

    public function activeLearnerUserIds(int $classId): array
    {
        return $this->participants[$classId] ?? [];
    }

    public function isActiveParticipant(int $classId, int $userId): bool
    {
        return in_array($userId, $this->participants[$classId] ?? [], true);
    }

    public function isActiveLearner(int $classId, int $userId): bool
    {
        return $this->isActiveParticipant($classId, $userId);
    }

    public function relationOf(int $userId, LearningClass $class): string
    {
        return $this->canManage($userId, $class) ? 'instructor' : 'none';
    }
}

final class FakeGroupContextAccess implements GroupContextAccess
{
    /** @var array<int, LearningGroup> */
    public array $groups = [];
    /** @var array<int, list<int>> */
    public array $managers = [];
    /** @var array<int, list<int>> */
    public array $members = [];

    public function findById(int $groupId): ?LearningGroup
    {
        return $this->groups[$groupId] ?? null;
    }

    public function canManage(int $userId, LearningGroup $group): bool
    {
        return in_array($userId, $this->managers[$group->id()] ?? [], true);
    }

    public function managedBy(int $userId): array
    {
        return array_values(array_filter(
            $this->groups,
            fn (LearningGroup $group): bool => $this->canManage($userId, $group)
        ));
    }

    public function learningBy(int $userId): array
    {
        return array_values(array_filter(
            $this->groups,
            fn (LearningGroup $group): bool => $group->isEnabled()
                && ! $group->isDeleted()
                && $this->isActiveMember($group->id(), $userId)
        ));
    }

    public function activeLearnerUserIds(int $groupId): array
    {
        return $this->members[$groupId] ?? [];
    }

    public function isActiveMember(int $groupId, int $userId): bool
    {
        return in_array($userId, $this->members[$groupId] ?? [], true);
    }

    public function relationOf(int $userId, LearningGroup $group): string
    {
        return $this->canManage($userId, $group) ? 'manager' : 'none';
    }
}

final class FakeWordPressUserGateway implements WordPressUserGateway
{
    /** @var array<int, true> */
    public array $existingUserIds = [];

    public function currentUser(): ?UserIdentity
    {
        return null;
    }

    public function findById(int $userId): ?UserIdentity
    {
        return isset($this->existingUserIds[$userId])
            ? new UserIdentity($userId, 'user' . $userId, 'user' . $userId . '@example.test', 'User ' . $userId)
            : null;
    }

    public function findByLogin(string $userLogin): ?UserIdentity
    {
        return null;
    }

    public function findByEmail(string $email): ?UserIdentity
    {
        return null;
    }

    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity
    {
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
        throw new RuntimeException('사용하지 않습니다.');
    }

    public function updateUser(int $userId, string $email, string $displayName): UserIdentity
    {
        throw new RuntimeException('사용하지 않습니다.');
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

    public function resetPasswordWithKey(string $userLogin, string $resetKey, string $newPassword): void
    {
    }

    public function deleteUser(int $userId): void
    {
    }

    public function findMany(array $userIds): array
    {
        return [];
    }
}

function i05LearningClass(int $id, int $ownerUserId, bool $enabled = true, bool $deleted = false): LearningClass
{
    return LearningClass::fromRow([
        'id' => $id,
        'name' => '테스트 학급 ' . $id,
        'slug' => 'class-' . $id,
        'description' => '',
        'visibility' => 'private',
        'progress_visibility' => 'private',
        'enabled' => $enabled ? 1 : 0,
        'owner_user_id' => $ownerUserId,
        'deleted_at' => $deleted ? '2026-01-01 00:00:00' : null,
    ]);
}

function i05LearningGroup(int $id, int $ownerUserId, bool $enabled = true, bool $deleted = false): LearningGroup
{
    return LearningGroup::fromRow([
        'id' => $id,
        'name' => '테스트 그룹 ' . $id,
        'slug' => 'group-' . $id,
        'description' => '',
        'visibility' => 'private',
        'approval_mode' => 'manual',
        'enabled' => $enabled ? 1 : 0,
        'evaluation_mode' => 'normal',
        'owner_user_id' => $ownerUserId,
        'deleted_at' => $deleted ? '2026-01-01 00:00:00' : null,
    ]);
}

const I05_TEACHER = 10;
const I05_OTHER_TEACHER = 20;
const I05_STUDENT = 30;
const I05_UNKNOWN_LEARNER = 999;

$classes = new FakeClassContextAccess();
$classes->classes[42] = i05LearningClass(42, I05_TEACHER);
$classes->classes[43] = i05LearningClass(43, I05_TEACHER, true, true); // deleted
$classes->managers[42] = [I05_TEACHER];
$classes->managers[43] = [I05_TEACHER];
$classes->participants[42] = [I05_STUDENT];

$groups = new FakeGroupContextAccess();
$groups->groups[7] = i05LearningGroup(7, I05_TEACHER);
$groups->managers[7] = [I05_TEACHER];
$groups->members[7] = [I05_STUDENT];

$users = new FakeWordPressUserGateway();
$users->existingUserIds[I05_TEACHER] = true;
$users->existingUserIds[I05_OTHER_TEACHER] = true;
$users->existingUserIds[I05_STUDENT] = true;

$gateway = new RealAddonContextGateway('stub-addon', $classes, $groups, $users, new AddonCorrelationIdFactory());

// 1. Teacher-in-charge -> allow
$decision = $gateway->authorize(new AddonContextAccessRequestV1(I05_TEACHER, 'class', 'class:42', 'view'));
i05Assert($decision->isAllowed(), '담당 교사는 허용돼야 합니다.');
i05Same('', $decision->code(), '허용된 결정은 code가 비어 있어야 합니다.');
i05Assert($decision->snapshot() !== null, '허용되면 snapshot이 있어야 합니다.');

// 2. Other teacher -> deny
$decision = $gateway->authorize(new AddonContextAccessRequestV1(I05_OTHER_TEACHER, 'class', 'class:42', 'view'));
i05Assert(! $decision->isAllowed(), '다른 학급의 교사는 거부돼야 합니다.');
i05Same('addon_context_denied', $decision->code(), '거부 코드가 addon_context_denied여야 합니다.');

// 3. Student (no staff role) -> deny
$decision = $gateway->authorize(new AddonContextAccessRequestV1(I05_STUDENT, 'class', 'class:42', 'view'));
i05Assert(! $decision->isAllowed(), '학생은 거부돼야 합니다.');

// 4. Deleted class, actor is its real owner -> deny
$decision = $gateway->authorize(new AddonContextAccessRequestV1(I05_TEACHER, 'class', 'class:43', 'view'));
i05Assert(! $decision->isAllowed(), '삭제된 학급은 소유자여도 거부돼야 합니다.');

// 5. Nonexistent class id -> deny
$decision = $gateway->authorize(new AddonContextAccessRequestV1(I05_TEACHER, 'class', 'class:999999', 'view'));
i05Assert(! $decision->isAllowed(), '존재하지 않는 학급은 거부돼야 합니다.');

// 6. Malformed contextId -> deny (never throws)
$decision = $gateway->authorize(new AddonContextAccessRequestV1(I05_TEACHER, 'class', 'not-a-valid-id', 'view'));
i05Assert(! $decision->isAllowed(), '형식이 잘못된 contextId는 거부돼야 합니다.');

// 7. learnerUserId set but WP user doesn't exist -> deny
$decision = $gateway->authorize(new AddonContextAccessRequestV1(
    I05_TEACHER, 'class', 'class:42', 'view', I05_UNKNOWN_LEARNER
));
i05Assert(! $decision->isAllowed(), '존재하지 않는 학습자는 거부돼야 합니다.');

// 8. learnerUserId exists but isn't a participant of THIS class -> deny
$decision = $gateway->authorize(new AddonContextAccessRequestV1(
    I05_TEACHER, 'class', 'class:42', 'view', I05_OTHER_TEACHER
));
i05Assert(! $decision->isAllowed(), '이 학급 참여자가 아닌 학습자는 거부돼야 합니다.');

// 9. learnerUserId is an active participant of this class -> allow
$decision = $gateway->authorize(new AddonContextAccessRequestV1(
    I05_TEACHER, 'class', 'class:42', 'view', I05_STUDENT
));
i05Assert($decision->isAllowed(), '이 학급의 실제 참여 학생은 허용돼야 합니다.');

// 10. contextType = site/user -> always deny
foreach (['site', 'user'] as $type) {
    $decision = $gateway->authorize(new AddonContextAccessRequestV1(I05_TEACHER, $type, $type . ':1', 'view'));
    i05Assert(! $decision->isAllowed(), $type . ' context는 항상 거부돼야 합니다.');
}

// 11. Stale expectedRevision -> deny with addon_context_unavailable, not _denied
$fresh = $gateway->authorize(new AddonContextAccessRequestV1(I05_TEACHER, 'class', 'class:42', 'view'));
$realRevision = $fresh->snapshot()->revision();
$decision = $gateway->authorize(new AddonContextAccessRequestV1(
    I05_TEACHER, 'class', 'class:42', 'view', null, $realRevision . 'stale'
));
i05Assert(! $decision->isAllowed(), '오래된 revision은 거부돼야 합니다.');
i05Same('addon_context_unavailable', $decision->code(), 'revision 불일치는 addon_context_unavailable이어야 합니다.');

// 12. Collaborator throws -> fail-closed, no message leakage
$classes->throwOnManage = true;
$decision = $gateway->authorize(new AddonContextAccessRequestV1(I05_TEACHER, 'class', 'class:42', 'view'));
i05Assert(! $decision->isAllowed(), '예외가 발생하면 거부돼야 합니다.');
i05Same('addon_context_unavailable', $decision->code(), '예외는 addon_context_unavailable로 처리돼야 합니다.');
$classes->throwOnManage = false;

// 13. Group side, symmetric spot-check
$decision = $gateway->authorize(new AddonContextAccessRequestV1(I05_TEACHER, 'group', 'group:7', 'manage'));
i05Assert($decision->isAllowed(), '그룹 담당자는 허용돼야 합니다.');
$decision = $gateway->authorize(new AddonContextAccessRequestV1(I05_STUDENT, 'group', 'group:7', 'manage'));
i05Assert(! $decision->isAllowed(), '그룹 구성원(운영자 아님)은 거부돼야 합니다.');

// 14. snapshot() mirrors authorize()'s staff gate and mandatory revision check
$snapshotResult = $gateway->snapshot(new AddonContextSnapshotRequestV1(I05_TEACHER, 'class', 'class:42', $realRevision));
i05Assert($snapshotResult->isSuccessful(), '올바른 revision의 snapshot은 성공해야 합니다.');
$deniedSnapshot = $gateway->snapshot(new AddonContextSnapshotRequestV1(I05_STUDENT, 'class', 'class:42', $realRevision));
i05Assert(! $deniedSnapshot->isSuccessful(), '학생의 snapshot 요청은 거부돼야 합니다.');
i05Same('addon_context_denied', $deniedSnapshot->problem()?->code(), 'snapshot 거부 코드가 올바르지 않습니다.');

// 15. listTeachingContexts: deleted never appears, search filters, pagination is stable
$page = $gateway->listTeachingContexts(new AddonTeachingContextQueryV1(I05_TEACHER, ['class', 'group']));
i05Assert($page->isSuccessful(), '목록 조회는 성공해야 합니다.');
$ids = array_map(static fn ($item) => $item->id(), $page->items());
i05Assert(in_array('class:42', $ids, true), '담당 학급이 목록에 있어야 합니다.');
i05Assert(! in_array('class:43', $ids, true), '삭제된 학급은 목록에 없어야 합니다.');
i05Assert(in_array('group:7', $ids, true), '담당 그룹이 목록에 있어야 합니다.');

$searchPage = $gateway->listTeachingContexts(new AddonTeachingContextQueryV1(I05_TEACHER, ['class'], '없는이름'));
i05Same([], $searchPage->items(), '검색어와 맞지 않으면 빈 목록이어야 합니다.');

// site/user types contribute nothing but never error
$sitePage = $gateway->listTeachingContexts(new AddonTeachingContextQueryV1(I05_TEACHER, ['site', 'user']));
i05Assert($sitePage->isSuccessful(), 'site/user 조회는 오류 없이 빈 목록을 반환해야 합니다.');
i05Same([], $sitePage->items(), 'site/user 조회는 항상 빈 목록이어야 합니다.');

// ===================== AI task gateway fixtures =====================

final class FakeAiTaskRouter implements AiTaskRouter
{
    public int $executeCalls = 0;
    public bool $canRouteResult = true;
    public ?AiTaskAssignmentSnapshot $snapshotResult = null;
    public ?\Throwable $snapshotException = null;
    public ?AiTaskResponse $executeResult = null;
    public ?\Throwable $executeException = null;

    public function canRoute(string $taskType, bool $requiresSubmissionSource = false): bool
    {
        return $this->canRouteResult;
    }

    public function snapshot(string $taskType, bool $requiresSubmissionSource = false): AiTaskAssignmentSnapshot
    {
        if ($this->snapshotException !== null) {
            throw $this->snapshotException;
        }

        return $this->snapshotResult;
    }

    public function execute(AiTaskAssignmentSnapshot $assignment, AiTaskRequest $request): AiTaskResponse
    {
        ++$this->executeCalls;
        if ($this->executeException !== null) {
            throw $this->executeException;
        }

        return $this->executeResult;
    }
}

final class FakeSettingsManager implements SettingsManager
{
    public function __construct(private readonly mixed $value)
    {
    }

    public function read(string $key, mixed $default = null): SettingsSnapshot
    {
        return new SettingsSnapshot(1, $this->value);
    }

    public function save(string $key, mixed $value, int $expectedRevision): SettingsSnapshot
    {
        throw new RuntimeException('사용하지 않습니다.');
    }
}

final class FakeSecretVault implements SecretVault
{
    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload)
    {
    }

    public function store(mixed $value): SecretReference
    {
        throw new RuntimeException('사용하지 않습니다.');
    }

    public function reveal(SecretReference $reference): mixed
    {
        return $this->payload;
    }

    public function discard(SecretReference $reference): void
    {
    }
}

function i05AddonManifest(string $namespace, array $aiTasks): AddonManifest
{
    return new AddonManifest(
        $namespace,
        '1.0.0',
        __FILE__,
        '^2.0',
        '^1.0',
        '테스트 애드온',
        'https://example.test/support',
        [],
        [],
        [],
        [],
        [],
        [],
        $aiTasks
    );
}

const I05_SECRET_MARKER = 'SECRET-API-KEY-MUST-NOT-LEAK-9f8e7d';

$secretReference = new SecretReference('secret:v2:aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
$profile = AiProviderProfile::tested(
    'primary-profile',
    '테스트 Provider',
    'ollama',
    'https://ai.example.test/should-not-leak',
    'bearer',
    I05_SECRET_MARKER,
    'test-model',
    'test-model-rev',
    ['addon.stubns.summarize'],
    false,
    30,
    ['127.0.0.1']
);
$settingsManager = new FakeSettingsManager($secretReference);
$secretVault = new FakeSecretVault(['profiles' => [$profile->toArray()]]);
$providerSettings = new AiProviderSettingsService($settingsManager, $secretVault);

$manifestA = i05AddonManifest('stubns', [
    new AddonAiTaskDefinitionV1('addon.stubns.summarize', '요약', '1.0', 'none'),
]);
$manifestB = i05AddonManifest('otherns', [
    new AddonAiTaskDefinitionV1('addon.otherns.review', '검토', '1.0', 'none'),
]);
$entryA = new AddonCatalogEntry('stub-addon', $manifestA, 'accepted', '');
$entryB = new AddonCatalogEntry('other-addon', $manifestB, 'accepted', '');

$aiRouter = new FakeAiTaskRouter();
$aiRouter->snapshotResult = new AiTaskAssignmentSnapshot(
    'addon.stubns.summarize',
    5,
    'primary-profile',
    null,
    $profile->fingerprint()
);
$aiRouter->executeResult = new AiTaskResponse(
    ['result' => 'ok'],
    'primary-profile',
    'ollama',
    'test-model',
    $profile->fingerprint()
);

$aiGatewayA = new AddonAiTaskRuntimeGateway('stub-addon', $entryA, $aiRouter, $providerSettings, new AddonCorrelationIdFactory());
$aiGatewayB = new AddonAiTaskRuntimeGateway('other-addon', $entryB, $aiRouter, $providerSettings, new AddonCorrelationIdFactory());

// canRoute: addon's own declared task -> routable
$routeDecision = $aiGatewayA->canRoute('addon.stubns.summarize');
i05Assert($routeDecision->canRoute(), '애드온 자신의 선언된 작업은 routable해야 합니다.');
i05Same('', $routeDecision->code(), 'routable 결정은 code가 비어 있어야 합니다.');

// canRoute: a CORE task key must never be routable through an addon gateway
$routeDecision = $aiGatewayA->canRoute('submission_feedback');
i05Assert(! $routeDecision->canRoute(), 'core AI 작업 키는 애드온 gateway로 routable하면 안 됩니다.');
i05Same('addon_ai_task_unroutable', $routeDecision->code(), 'core 작업 거부 코드가 올바르지 않습니다.');

// canRoute: addon A must never route addon B's key, even though it's a real accepted key
$routeDecision = $aiGatewayA->canRoute('addon.otherns.review');
i05Assert(! $routeDecision->canRoute(), '다른 애드온의 작업 키는 routable하면 안 됩니다.');
$routeDecision = $aiGatewayB->canRoute('addon.otherns.review');
i05Assert($routeDecision->canRoute(), '애드온 B는 자신의 작업을 routable해야 합니다.');

// snapshot(): only providerKey/model/fingerprint/revision, never the secret or base URL
$snapshot = $aiGatewayA->snapshot('addon.stubns.summarize');
i05Assert($snapshot->isAvailable(), 'snapshot은 사용 가능해야 합니다.');
i05Same('ollama', $snapshot->providerKey(), 'providerKey가 올바르지 않습니다.');
i05Same('test-model', $snapshot->modelKey(), 'modelKey가 올바르지 않습니다.');
i05Assert(
    ! str_contains($snapshot->providerKey(), I05_SECRET_MARKER)
    && ! str_contains($snapshot->modelKey(), I05_SECRET_MARKER)
    && ! str_contains($snapshot->profileFingerprint(), I05_SECRET_MARKER)
    && ! str_contains($snapshot->routingRevision(), I05_SECRET_MARKER)
    && ! str_contains($snapshot->providerKey(), 'ai.example.test')
    && ! str_contains($snapshot->modelKey(), 'ai.example.test'),
    'snapshot 어디에도 비밀값이나 base URL이 나타나면 안 됩니다.'
);

// snapshot(): a task not declared by this addon is unroutable, not an exception
$snapshot = $aiGatewayA->snapshot('addon.otherns.review');
i05Assert(! $snapshot->isAvailable(), '선언하지 않은 작업의 snapshot은 unavailable이어야 합니다.');
i05Same('addon_ai_task_unroutable', $snapshot->problem()?->code(), '코드가 올바르지 않습니다.');

// execute(): success path returns output and routing info, never a secret
$request = new AddonAiTaskRequestV1('addon.stubns.summarize', '1.0', I05_TEACHER, 'idem-1', ['text' => 'hello']);
$researchAccess = new class implements \CodingPanda\OjPlatform\Shared\Ai\ActorAiAccessPolicy {
    public int $calls = 0;
    public int $blockAt = 1;
    public function assertAiAccess(int $actorUserId): void {
        i05Same(I05_TEACHER, $actorUserId, '연구 제한은 요청자에게 적용합니다.');
        if (++$this->calls >= $this->blockAt) { throw new DomainException('현재 연구 활동에서는 AI를 사용할 수 없습니다.'); }
    }
};
$restrictedGateway = new AddonAiTaskRuntimeGateway('stub-addon', $entryA, $aiRouter, $providerSettings, new AddonCorrelationIdFactory(), $researchAccess);
i05Assert($restrictedGateway->snapshot('addon.stubns.summarize')->isAvailable(), '사용자 없는 모델 정보 조회는 연구 실행 제한과 분리합니다.');
i05Same(0, $researchAccess->calls, '모델 snapshot에서 존재하지 않는 요청자를 참조하지 않습니다.');
$callsBeforeResearchBlock = $aiRouter->executeCalls;
i05Assert(! $restrictedGateway->execute($request)->isSuccessful(), '연구 중 별도 애드온 AI 실행을 사전에 차단합니다.');
i05Same($callsBeforeResearchBlock, $aiRouter->executeCalls, '차단된 연구 참여자의 요청은 모델 실행에 도달하지 않습니다.');
i05Same(1, $researchAccess->calls, '차단된 요청은 사후 전달 단계에 도달하지 않습니다.');
$researchAccess->calls = 0; $researchAccess->blockAt = 2;
$blockedDelivery = $restrictedGateway->execute($request);
i05Assert(! $blockedDelivery->isSuccessful() && $blockedDelivery->output() === [], '연구 단계가 실행 중 바뀌면 응답 내용 전달을 차단합니다.');
i05Same(2, $researchAccess->calls, 'AI 실행 전과 응답 전달 전에 연구 정책을 확인합니다.');
$result = $aiGatewayA->execute($request);
i05Assert($result->isSuccessful(), 'execute는 성공해야 합니다.');
i05Same(['result' => 'ok'], $result->output(), 'execute 출력이 올바르지 않습니다.');
i05Assert(
    ! str_contains($result->routingSnapshot()->providerKey(), I05_SECRET_MARKER)
    && ! str_contains($result->routingSnapshot()->modelKey(), I05_SECRET_MARKER),
    'execute 결과에 비밀값이 노출되면 안 됩니다.'
);

// execute(): a core task key is rejected before ever reaching the router
$coreRequest = new AddonAiTaskRequestV1('addon.stubns.summarize', 'wrong-version', I05_TEACHER, 'idem-2', []);
$result = $aiGatewayA->execute($coreRequest);
i05Assert(! $result->isSuccessful(), '계약 버전이 다르면 실패해야 합니다.');
i05Same('addon_ai_task_unroutable', $result->problem()?->code(), '계약 버전 불일치 코드가 올바르지 않습니다.');

// execute(): provider failure maps to addon_ai_task_failed, never leaking raw provider detail beyond the audited message
$aiRouter->executeException = new AiProviderException('provider_timeout', 'AI 서버 응답이 지연되었습니다.', true);
$result = $aiGatewayA->execute($request);
i05Assert(! $result->isSuccessful(), 'provider 예외는 실패로 처리돼야 합니다.');
i05Same('addon_ai_task_failed', $result->problem()?->code(), 'provider 실패 코드가 올바르지 않습니다.');
i05Assert($result->problem()?->retryable() === true, 'transient 오류는 retryable=true여야 합니다.');
$aiRouter->executeException = null;

// execute(): an unclassified exception never forwards its own message
$aiRouter->executeException = new RuntimeException('SECRET-INTERNAL-DETAIL-should-not-leak');
$result = $aiGatewayA->execute($request);
i05Assert(! $result->isSuccessful(), '예상 못한 예외도 실패로 처리돼야 합니다.');
i05Assert(
    $result->problem() !== null && ! str_contains($result->problem()->message(), 'SECRET-INTERNAL-DETAIL'),
    '분류되지 않은 예외의 메시지가 그대로 노출되면 안 됩니다.'
);
$aiRouter->executeException = null;

// snapshot(): a core-shaped key (the exact hijack probe) must degrade gracefully, never throw uncaught
$snapshot = $aiGatewayA->snapshot('submission_feedback');
i05Assert(! $snapshot->isAvailable(), 'core 키로 snapshot()을 호출하면 unavailable이어야 합니다.');
i05Same('addon_ai_task_unroutable', $snapshot->problem()?->code(), 'core 키 snapshot 코드가 올바르지 않습니다.');

// snapshot(): completely malformed input must also degrade gracefully, never throw uncaught
$snapshot = $aiGatewayA->snapshot('not-even-addon-shaped!!');
i05Assert(! $snapshot->isAvailable(), '형식이 잘못된 키로 snapshot()을 호출하면 unavailable이어야 합니다.');
i05Same('addon_ai_task_unroutable', $snapshot->problem()?->code(), '잘못된 키 snapshot 코드가 올바르지 않습니다.');

// AiTaskRoutingService::assignmentsFrom() must skip a stale addon assignment, not break every task's routing
$staleRoutingSettings = new FakeSettingsManager([
    'assignments' => [
        [
            'task_type' => 'submission_feedback',
            'primary_profile_key' => 'primary-profile',
            'fallback_profile_key' => null,
            'max_output_tokens' => 1200,
            'fallback_policy' => 'none',
        ],
        [
            // No addon currently declares this key (its addon was deactivated/removed).
            'task_type' => 'addon.gone.stale_task',
            'primary_profile_key' => 'primary-profile',
            'fallback_profile_key' => null,
            'max_output_tokens' => 1200,
            'fallback_policy' => 'none',
        ],
    ],
]);
$catalogWithoutStaleAddon = new \CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog();
$routingService = new \CodingPanda\OjPlatform\Modules\Settings\Application\AiTaskRoutingService(
    $staleRoutingSettings,
    $providerSettings,
    $catalogWithoutStaleAddon
);
$assignments = $routingService->assignments();
i05Assert(
    isset($assignments['submission_feedback']),
    '오래된 애드온 배정이 있어도 core 작업 배정은 그대로 조회돼야 합니다.'
);
i05Assert(
    ! isset($assignments['addon.gone.stale_task']),
    '더 이상 지원하지 않는 애드온 작업 배정은 조용히 제외돼야 합니다.'
);

fwrite(
    STDOUT,
    "C28-I05 실연동 계약 검사 통과: Context fail-closed 매트릭스, AI 라우팅·비밀 비노출, 교차 애드온 차단, 오래된 배정 격리 확인\n"
);
