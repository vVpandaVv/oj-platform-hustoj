<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonAssignmentRosterQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextGatewayV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonLearningContextQueryV1;
use CodingPanda\OjPlatform\Kernel\Addon\AddonCorrelationIdFactory;
use CodingPanda\OjPlatform\Kernel\Addon\RealAddonContextGateway;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Classes\Contract\ClassContextAccess;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Groups\Contract\GroupContextAccess;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

function i06ContextAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function i06ContextSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true)
        );
    }
}

final class I06ClassContextLedger implements ClassContextAccess
{
    /** @var array<int, LearningClass> */
    public array $items = [];
    /** @var array<int, list<int>> */
    public array $managers = [];
    /** @var array<int, list<int>> */
    public array $learners = [];
    public bool $throwOnRoster = false;

    public function findById(int $classId): ?LearningClass
    {
        return $this->items[$classId] ?? null;
    }

    public function canManage(int $userId, LearningClass $class): bool
    {
        return in_array($userId, $this->managers[$class->id()] ?? [], true);
    }

    public function managedBy(int $userId): array
    {
        return array_values(array_filter(
            $this->items,
            fn (LearningClass $class): bool => $this->canManage($userId, $class)
        ));
    }

    public function learningBy(int $userId): array
    {
        return array_values(array_filter(
            $this->items,
            fn (LearningClass $class): bool => $class->isEnabled()
                && ! $class->isDeleted()
                && $this->isActiveLearner($class->id(), $userId)
        ));
    }

    public function activeLearnerUserIds(int $classId): array
    {
        if ($this->throwOnRoster) {
            throw new RuntimeException('private roster provider failure');
        }

        return $this->learners[$classId] ?? [];
    }

    public function isActiveParticipant(int $classId, int $userId): bool
    {
        return in_array($userId, $this->learners[$classId] ?? [], true);
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

final class I06GroupContextLedger implements GroupContextAccess
{
    /** @var array<int, LearningGroup> */
    public array $items = [];
    /** @var array<int, list<int>> */
    public array $managers = [];
    /** @var array<int, list<int>> */
    public array $learners = [];

    public function findById(int $groupId): ?LearningGroup
    {
        return $this->items[$groupId] ?? null;
    }

    public function canManage(int $userId, LearningGroup $group): bool
    {
        return in_array($userId, $this->managers[$group->id()] ?? [], true);
    }

    public function managedBy(int $userId): array
    {
        return array_values(array_filter(
            $this->items,
            fn (LearningGroup $group): bool => $this->canManage($userId, $group)
        ));
    }

    public function learningBy(int $userId): array
    {
        return array_values(array_filter(
            $this->items,
            fn (LearningGroup $group): bool => $group->isEnabled()
                && ! $group->isDeleted()
                && $this->isActiveMember($group->id(), $userId)
        ));
    }

    public function activeLearnerUserIds(int $groupId): array
    {
        return $this->learners[$groupId] ?? [];
    }

    public function isActiveMember(int $groupId, int $userId): bool
    {
        return in_array($userId, $this->learners[$groupId] ?? [], true);
    }

    public function relationOf(int $userId, LearningGroup $group): string
    {
        return $this->canManage($userId, $group) ? 'manager' : 'none';
    }
}

final class I06UserGateway implements WordPressUserGateway
{
    /** @var array<int, true> */
    public array $existing = [];

    public function currentUser(): ?UserIdentity { return null; }
    public function findById(int $userId): ?UserIdentity
    {
        return isset($this->existing[$userId])
            ? new UserIdentity(
                $userId,
                'private-login-' . $userId,
                'private-' . $userId . '@example.test',
                'Private Name ' . $userId
            )
            : null;
    }
    public function findByLogin(string $userLogin): ?UserIdentity { return null; }
    public function findByEmail(string $email): ?UserIdentity { return null; }
    public function verifyCredentials(string $userLogin, string $password): ?UserIdentity { return null; }
    public function establishSession(int $userId, bool $remember): void {}
    public function createUser(
        string $userLogin,
        string $email,
        string $password,
        string $displayName,
        string $role
    ): UserIdentity {
        throw new RuntimeException('unused');
    }
    public function updateUser(int $userId, string $email, string $displayName): UserIdentity
    {
        throw new RuntimeException('unused');
    }
    public function setRoles(int $userId, array $roles): void {}
    public function setPassword(int $userId, string $password): void {}
    public function invalidateSessions(int $userId): void {}
    public function requestPasswordReset(string $userLogin): void {}
    public function resetPasswordWithKey(string $userLogin, string $resetKey, string $newPassword): void {}
    public function deleteUser(int $userId): void {}
    public function findMany(array $userIds): array
    {
        return array_values(array_filter(array_map(
            fn (int $userId): ?UserIdentity => $this->findById($userId),
            $userIds
        )));
    }
}

function i06Class(int $id, bool $enabled = true, bool $deleted = false): LearningClass
{
    return LearningClass::fromRow([
        'id' => $id,
        'name' => '학급 ' . $id,
        'slug' => 'class-' . $id,
        'description' => '',
        'visibility' => 'private',
        'progress_visibility' => 'private',
        'enabled' => $enabled ? 1 : 0,
        'owner_user_id' => 10,
        'deleted_at' => $deleted ? '2026-08-14 00:00:00' : null,
    ]);
}

function i06Group(int $id, bool $enabled = true, bool $deleted = false): LearningGroup
{
    return LearningGroup::fromRow([
        'id' => $id,
        'name' => '그룹 ' . $id,
        'slug' => 'group-' . $id,
        'description' => '',
        'visibility' => 'private',
        'approval_mode' => 'manual',
        'enabled' => $enabled ? 1 : 0,
        'evaluation_mode' => 'normal',
        'owner_user_id' => 10,
        'deleted_at' => $deleted ? '2026-08-14 00:00:00' : null,
    ]);
}

const I06_TEACHER = 10;
const I06_OTHER_TEACHER = 11;
const I06_LEARNER_A = 20;
const I06_LEARNER_B = 21;
const I06_NONMEMBER = 22;

$classes = new I06ClassContextLedger();
$classes->items = [
    42 => i06Class(42),
    43 => i06Class(43, false),
    44 => i06Class(44, true, true),
];
$classes->managers = [42 => [I06_TEACHER], 43 => [I06_TEACHER], 44 => [I06_TEACHER]];
$classes->learners = [
    42 => [I06_LEARNER_B, I06_LEARNER_A, I06_LEARNER_A],
    43 => [I06_LEARNER_A],
    44 => [I06_LEARNER_A],
];

$groups = new I06GroupContextLedger();
$groups->items = [7 => i06Group(7)];
$groups->managers = [7 => [I06_TEACHER]];
$groups->learners = [7 => [I06_LEARNER_A]];

$users = new I06UserGateway();
foreach ([I06_TEACHER, I06_OTHER_TEACHER, I06_LEARNER_A, I06_LEARNER_B, I06_NONMEMBER] as $userId) {
    $users->existing[$userId] = true;
}

$gateway = new RealAddonContextGateway(
    'i06-addon',
    $classes,
    $groups,
    $users,
    new AddonCorrelationIdFactory()
);
i06ContextAssert($gateway instanceof AddonLearningContextGatewayV1, 'CG02 extension을 구현해야 합니다.');
i06ContextAssert($gateway instanceof AddonAssignmentRosterGatewayV1, 'CG11 extension을 구현해야 합니다.');

$studentView = $gateway->authorize(new AddonContextAccessRequestV1(
    I06_LEARNER_A,
    'class',
    'class:42',
    'view'
));
i06ContextAssert(! $studentView->isAllowed(), '학생은 교사용 view 권한을 얻으면 안 됩니다.');

$studentParticipate = $gateway->authorize(new AddonContextAccessRequestV1(
    I06_LEARNER_A,
    'class',
    'class:42',
    'participate',
    I06_LEARNER_A
));
i06ContextAssert($studentParticipate->isAllowed(), 'active 학생의 자기 participate는 허용해야 합니다.');
i06ContextSame('participant', $studentParticipate->snapshot()?->actorRelation(), '학급 학생 관계가 다릅니다.');

$delegatedParticipate = $gateway->authorize(new AddonContextAccessRequestV1(
    I06_LEARNER_A,
    'class',
    'class:42',
    'participate',
    I06_LEARNER_B
));
i06ContextAssert(! $delegatedParticipate->isAllowed(), '학생의 타인 대리 participate를 거부해야 합니다.');

$nonmemberParticipate = $gateway->authorize(new AddonContextAccessRequestV1(
    I06_NONMEMBER,
    'class',
    'class:42',
    'participate'
));
i06ContextAssert(! $nonmemberParticipate->isAllowed(), '비참여 학생을 거부해야 합니다.');

$inactiveParticipate = $gateway->authorize(new AddonContextAccessRequestV1(
    I06_LEARNER_A,
    'class',
    'class:43',
    'participate'
));
i06ContextAssert(! $inactiveParticipate->isAllowed(), 'inactive 학급 참여를 거부해야 합니다.');

$groupParticipate = $gateway->authorize(new AddonContextAccessRequestV1(
    I06_LEARNER_A,
    'group',
    'group:7',
    'participate'
));
i06ContextAssert($groupParticipate->isAllowed(), 'active 그룹 구성원의 자기 participate를 허용해야 합니다.');
i06ContextSame('member', $groupParticipate->snapshot()?->actorRelation(), '그룹 학생 관계가 다릅니다.');

$learningFirst = $gateway->listLearningContexts(new AddonLearningContextQueryV1(
    I06_LEARNER_A,
    ['class', 'group'],
    '',
    '',
    1
));
i06ContextAssert($learningFirst->isSuccessful(), '학생 학습 Context 첫 page가 성공해야 합니다.');
i06ContextSame(['class:42'], array_map(static fn ($item): string => $item->id(), $learningFirst->items()), '첫 학습 Context가 다릅니다.');
i06ContextSame('class:42', $learningFirst->nextCursor(), '학습 Context cursor가 다릅니다.');

$learningSecond = $gateway->listLearningContexts(new AddonLearningContextQueryV1(
    I06_LEARNER_A,
    ['class', 'group'],
    '',
    $learningFirst->nextCursor(),
    1
));
i06ContextSame(['group:7'], array_map(static fn ($item): string => $item->id(), $learningSecond->items()), '두 번째 학습 Context가 다릅니다.');
i06ContextSame('', $learningSecond->nextCursor(), '마지막 학습 Context cursor는 비어야 합니다.');

$unknownLearningCursor = $gateway->listLearningContexts(new AddonLearningContextQueryV1(
    I06_LEARNER_A,
    ['class'],
    '',
    'class:999',
    1
));
i06ContextAssert(! $unknownLearningCursor->isSuccessful(), '알 수 없는 학습 Context cursor를 실패 처리해야 합니다.');
i06ContextSame('addon_context_unavailable', $unknownLearningCursor->problem()?->code(), '학습 Context cursor 실패 코드가 다릅니다.');

$classes->learners[42] = [I06_LEARNER_B];
$withdrawnLearning = $gateway->listLearningContexts(new AddonLearningContextQueryV1(I06_LEARNER_A, ['class']));
i06ContextSame([], $withdrawnLearning->items(), '탈퇴한 학습 Context는 즉시 목록에서 사라져야 합니다.');
$classes->learners[42] = [I06_LEARNER_B, I06_LEARNER_A, I06_LEARNER_A];

$teacherContext = $gateway->authorize(new AddonContextAccessRequestV1(
    I06_TEACHER,
    'class',
    'class:42',
    'assign'
));
$contextRevision = $teacherContext->snapshot()?->revision() ?? '';
i06ContextAssert($contextRevision !== '', '배정용 Context revision을 얻어야 합니다.');

$rosterFirst = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'class',
    'class:42',
    $contextRevision,
    null,
    '',
    1
));
i06ContextAssert($rosterFirst->isSuccessful(), '배정 명단 첫 page가 성공해야 합니다.');
i06ContextSame([I06_LEARNER_A], array_map(static fn ($item): int => $item->learnerUserId(), $rosterFirst->items()), '명단은 정렬·중복 제거되어야 합니다.');
i06ContextSame('Private Name ' . I06_LEARNER_A, $rosterFirst->items()[0]->displayName(), '허용된 명단의 표시 이름이 달라졌습니다.');
i06ContextSame('Private Name ' . I06_LEARNER_A, $rosterFirst->items()[0]->managementName(), '관리용 이름이 없으면 표시 이름을 사용해야 합니다.');
i06ContextAssert($rosterFirst->rosterRevision() !== null && $rosterFirst->nextCursor() !== '', '첫 page는 명단 revision과 cursor를 반환해야 합니다.');

$rosterSecond = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'class',
    'class:42',
    $contextRevision,
    $rosterFirst->rosterRevision(),
    $rosterFirst->nextCursor(),
    1
));
i06ContextAssert($rosterSecond->isSuccessful(), '같은 revision의 다음 명단 page가 성공해야 합니다.');
i06ContextSame([I06_LEARNER_B], array_map(static fn ($item): int => $item->learnerUserId(), $rosterSecond->items()), '두 번째 명단 page가 다릅니다.');
i06ContextSame('', $rosterSecond->nextCursor(), '명단 마지막 cursor는 비어야 합니다.');
i06ContextSame($rosterFirst->rosterRevision(), $rosterSecond->rosterRevision(), '모든 page가 같은 명단 revision을 사용해야 합니다.');

$deterministicRoster = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'class',
    'class:42',
    $contextRevision,
    null,
    '',
    1
));
i06ContextSame($rosterFirst->rosterRevision(), $deterministicRoster->rosterRevision(), '같은 명단의 revision은 결정적이어야 합니다.');
i06ContextSame(
    $rosterFirst->items()[0]->membershipRevision(),
    $deterministicRoster->items()[0]->membershipRevision(),
    '같은 active membership revision은 결정적이어야 합니다.'
);

$classes->learners[42][] = I06_NONMEMBER;
$rosterRace = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'class',
    'class:42',
    $contextRevision,
    $rosterFirst->rosterRevision(),
    $rosterFirst->nextCursor(),
    1
));
i06ContextAssert(! $rosterRace->isSuccessful(), 'pagination 중 명단 변경은 전체 실패해야 합니다.');
i06ContextSame('addon_context_unavailable', $rosterRace->problem()?->code(), '명단 race 실패 코드가 다릅니다.');

$freshRoster = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'class',
    'class:42',
    $contextRevision,
    null,
    '',
    1
));
$unknownRosterCursor = 'r1.' . $freshRoster->rosterRevision() . '.999';
$unknownRoster = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'class',
    'class:42',
    $contextRevision,
    $freshRoster->rosterRevision(),
    $unknownRosterCursor,
    1
));
i06ContextAssert(! $unknownRoster->isSuccessful(), '알 수 없는 명단 cursor를 실패 처리해야 합니다.');

$unauthorizedRoster = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_OTHER_TEACHER,
    'class',
    'class:42',
    $contextRevision
));
i06ContextAssert(! $unauthorizedRoster->isSuccessful(), '비담당 교사의 명단 조회를 거부해야 합니다.');
i06ContextSame('addon_context_denied', $unauthorizedRoster->problem()?->code(), '비담당 명단 코드가 다릅니다.');

$staleContextRoster = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'class',
    'class:42',
    $contextRevision . 'stale'
));
i06ContextAssert(! $staleContextRoster->isSuccessful(), 'stale Context 명단 요청을 실패 처리해야 합니다.');
i06ContextSame('addon_context_unavailable', $staleContextRoster->problem()?->code(), 'stale Context 명단 코드가 다릅니다.');

$inactiveContext = $gateway->authorize(new AddonContextAccessRequestV1(
    I06_TEACHER,
    'class',
    'class:43',
    'assign'
));
$inactiveRoster = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'class',
    'class:43',
    $inactiveContext->snapshot()?->revision() ?? 'unavailable'
));
i06ContextAssert(! $inactiveRoster->isSuccessful(), 'inactive Context의 배정 명단을 거부해야 합니다.');
i06ContextSame('addon_context_denied', $inactiveRoster->problem()?->code(), 'inactive 명단 코드가 다릅니다.');

$groupContext = $gateway->authorize(new AddonContextAccessRequestV1(
    I06_TEACHER,
    'group',
    'group:7',
    'assign'
));
$groupRoster = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'group',
    'group:7',
    $groupContext->snapshot()?->revision() ?? ''
));
i06ContextAssert($groupRoster->isSuccessful(), '그룹 배정 명단이 성공해야 합니다.');
i06ContextSame([I06_LEARNER_A], array_map(static fn ($item): int => $item->learnerUserId(), $groupRoster->items()), '그룹 명단이 다릅니다.');

$classes->throwOnRoster = true;
$providerFailure = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'class',
    'class:42',
    $contextRevision
));
i06ContextAssert(! $providerFailure->isSuccessful(), '명단 provider 예외를 실패 결과로 격리해야 합니다.');
i06ContextAssert(! str_contains($providerFailure->problem()?->message() ?? '', 'private roster'), 'provider 상세를 노출하면 안 됩니다.');
$classes->throwOnRoster = false;

$classes->learners[42] = range(1000, 6000);
$oversizedRoster = $gateway->listAssignmentLearners(new AddonAssignmentRosterQueryV1(
    I06_TEACHER,
    'class',
    'class:42',
    $contextRevision
));
i06ContextAssert(! $oversizedRoster->isSuccessful(), '5,000명을 넘는 명단을 전체 실패해야 합니다.');
i06ContextSame('addon_context_unavailable', $oversizedRoster->problem()?->code(), '명단 상한 실패 코드가 다릅니다.');

$learnerMethods = array_values(array_filter(array_map(
    static fn (ReflectionMethod $method): string => $method->getName(),
    (new ReflectionClass($rosterFirst->items()[0]))->getMethods(ReflectionMethod::IS_PUBLIC)
), static fn (string $method): bool => $method !== '__construct'));
sort($learnerMethods);
i06ContextSame(
    ['displayName', 'learnerUserId', 'managementName', 'membershipRevision'],
    $learnerMethods,
    '명단 항목은 ID·리비전·표시 이름·관리용 이름만 노출해야 합니다.'
);

fwrite(
    STDOUT,
    "C28-I06 Context 계약 검사 통과: CG01 self participate, CG02 active learning list, CG11 authorized roster·names·race·5000 cap\n"
);
