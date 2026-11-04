<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Application\PasswordResetService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AdminActionAuditLog;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAccountProvisioner;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationRequirements;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressPasswordResetTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserApiGateway;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupRepository;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupSaveCommand;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Action\GroupMembershipActionHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Action\GroupPasswordResetActionHandler;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjectionQuery;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
Autoloader::register($pluginRoot);
$boundaryAssertions = 0;
$GLOBALS['group_boundary_users'] = [];
$GLOBALS['group_boundary_password_writes'] = [];
$GLOBALS['group_boundary_sessions_revoked'] = [];

function groupBoundaryAssert(bool $condition, string $message): void
{
    ++$GLOBALS['boundaryAssertions'];
    if (! $condition) { throw new RuntimeException($message); }
}

function groupBoundaryUser(int $id, array $roles, array $capabilities): object
{
    return (object) [
        'ID' => $id, 'user_login' => 'fixture-user-' . $id,
        'user_email' => 'fixture-' . $id . '@example.test',
        'display_name' => '검사 사용자 ' . $id, 'roles' => $roles,
        'allcaps' => array_fill_keys(array_merge(['read'], $roles, $capabilities), true),
    ];
}

function get_user_by(string $field, mixed $value): object|false
{
    foreach ($GLOBALS['group_boundary_users'] as $user) {
        if (($field === 'id' && $user->ID === (int) $value)
            || ($field === 'login' && $user->user_login === $value)) {
            return $user;
        }
    }
    return false;
}

function get_userdata(int $userId): object|false { return get_user_by('id', $userId); }
function user_can(mixed $user, string $capability, mixed ...$arguments): bool
{
    unset($arguments);
    $user = is_object($user) ? $user : get_userdata((int) $user);
    return $user !== false && ($user->allcaps[$capability] ?? false) === true;
}
function is_super_admin(int $userId): bool { unset($userId); return false; }
function wp_set_password(string $password, int $userId): void
{
    $GLOBALS['group_boundary_password_writes'][$userId] = $password;
}

final class WP_Session_Tokens
{
    private function __construct(private readonly int $userId) {}
    public static function get_instance(int $userId): self { return new self($userId); }
    public function destroy_all(): void { $GLOBALS['group_boundary_sessions_revoked'][] = $this->userId; }
}

$GLOBALS['group_boundary_users'][10] = groupBoundaryUser(10, ['oj_group_manager'], ['oj_access_console', 'oj_create_groups', 'oj_manage_own_groups']);
$GLOBALS['group_boundary_users'][11] = groupBoundaryUser(11, ['subscriber'], []);
$GLOBALS['group_boundary_users'][20] = groupBoundaryUser(20, ['oj_class_manager'], ['oj_access_console', 'oj_manage_classes']);
$GLOBALS['group_boundary_users'][30] = groupBoundaryUser(30, ['subscriber'], []);
$users = new WordPressUserApiGateway();
$profiles = new class implements UserProfileRepository {
    public array $saved = [];
    public array $statuses = [];
    public array $missing = [];
    public function find(int $userId): ?UserProfile
    {
        return get_userdata($userId) === false || in_array($userId, $this->missing, true)
            ? null : new UserProfile($userId, null, null, $this->statuses[$userId] ?? AccessStatus::Active, false);
    }
    public function create(UserProfile $profile): void { $this->save($profile); }
    public function save(UserProfile $profile, ?int $changedByUserId = null): void { $this->saved[$profile->userId()] = $profile; }
    public function recordLogin(int $userId): void {}
    public function saveRegistrationRequirements(int $userId, RegistrationRequirements $requirements): void {}
    public function registrationRequirements(int $userId): ?RegistrationRequirements { return null; }
    public function findPage(int $limit, int $offset = 0): array { return []; }
    public function findByStatus(AccessStatus $status, int $limit, int $offset = 0): array { return []; }
    public function findFiltered(?array $userIds, ?AccessStatus $status, int $limit, int $offset = 0): array { return []; }
    public function countFiltered(?array $userIds, ?AccessStatus $status): int { return 0; }
};
$permissions = new class implements Authorization {
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return user_can($userId, $capability); }
    public function canAny(int $userId, array $capabilities): bool
    {
        foreach ($capabilities as $capability) { if ($this->can($userId, $capability)) { return true; } }
        return false;
    }
    public function isSuperAdmin(int $userId): bool { return is_super_admin($userId); }
};
$repository = new class implements GroupRepository {
    public array $members = [30 => 'active'];
    public function find(int $groupId): ?LearningGroup
    {
        return $groupId === 1 ? new LearningGroup(1, '검사 그룹', 'fixture-group', '', 'private', 'manual', true, null, 'normal', null, 10, null) : null;
    }
    public function visibleFor(int $userId): array { return $this->managedBy($userId, false); }
    public function managedBy(int $userId, bool $all): array { return $all || $userId === 10 ? [$this->find(1)] : []; }
    public function memberUserIds(int $groupId, string $status = 'active'): array
    {
        return $groupId === 1 ? array_keys(array_filter($this->members, static fn (string $value): bool => $value === $status)) : [];
    }
    public function managerUserIds(int $groupId): array { return []; }
    public function activeGroupIdsForUser(int $userId): array { return ($this->members[$userId] ?? '') === 'active' ? [1] : []; }
    public function managedGroupIdsForUser(int $userId): array { return $userId === 10 ? [1] : []; }
    public function activePerformanceGroupIdsForUser(int $userId): array { return []; }
    public function isActiveMember(int $groupId, int $userId): bool { return $groupId === 1 && ($this->members[$userId] ?? '') === 'active'; }
    public function membershipStatus(int $groupId, int $userId): ?string { return $groupId === 1 ? ($this->members[$userId] ?? null) : null; }
    public function isActiveManager(int $groupId, int $userId): bool { return false; }
    public function hasActivePerformanceGroup(int $userId): bool { return false; }
    public function saveAggregate(int $actorUserId, GroupSaveCommand $command, ?string $joinCodeHash): int { throw new LogicException('이 검사에서는 그룹을 만들지 않습니다.'); }
    public function setMembershipStatus(int $groupId, int $userId, string $status, int $actorUserId): void { $this->members[$userId] = $status; }
    public function setManager(int $groupId, int $userId, bool $enabled, int $actorUserId): void { throw new LogicException('이 검사에서는 운영자를 변경하지 않습니다.'); }
    public function setDeleted(int $groupId, bool $deleted, int $actorUserId): void { throw new LogicException('이 검사에서는 그룹을 보관하지 않습니다.'); }
};
$accounts = new class implements CurrentAccountProvider {
    public int $actorId = 10;
    public function current(): CurrentAccount
    {
        $user = get_userdata($this->actorId);
        return new CurrentAccount(new UserIdentity($user->ID, $user->user_login, $user->user_email, $user->display_name, $user->roles), new UserProfile($user->ID, null, null, AccessStatus::Active, false));
    }
};
$security = new class implements ActionSecurity {
    public function verify(string $actionKey, HttpRequest $request): bool { return $request->bodyString('_nonce') === 'fixture-nonce'; }
    public function hiddenFields(string $actionKey): string { return ''; }
};
$limiter = new class implements AttemptLimiter {
    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds): bool { return true; }
    public function resetAll(): int { return 0; }
};
$audit = new class implements AdminActionAuditLog {
    public array $items = [];
    public function record(int $actorUserId, string $scope, int $targetUserId, string $action, string $result): void { $this->items[] = compact('actorUserId', 'scope', 'targetUserId', 'action', 'result'); }
    public function recent(int $limit = 100): array { return array_slice($this->items, 0, $limit); }
};
$provisioner = new class implements LearnerAccountProvisioner {
    public function provisionLearner(string $userLogin, string $email, string $password, string $displayName): UserIdentity { throw new LogicException('이 검사에서는 계정을 만들지 않습니다.'); }
};
$submissions = new class implements SubmissionProjectionProvider {
    public function byIds(int $actorUserId, string $actorUserLogin, array $submissionIds): array { return []; }
    public function search(int $actorUserId, string $actorUserLogin, SubmissionProjectionQuery $query): array { return []; }
    public function report(SubmissionProjectionQuery $query): array { return []; }
    public function searchAll(int $actorUserId, string $actorUserLogin, SubmissionProjectionQuery $query): array { return []; }
    public function reportAll(SubmissionProjectionQuery $query): array { return []; }
};
$responses = new class implements ActionResponse {
    public function redirect(string $path, UiMessage $message, int $userId = 0): HttpResponse { return HttpResponse::redirect($path); }
};
$pages = new class implements ConsoleResponder {
    public function respond(string $title, string $content, string $currentMenu, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
};
$errors = new class implements ErrorReporter {
    public function report(Throwable $error): void {}
};
$passwords = new PasswordResetService($users, $profiles, new WordPressPasswordResetTargetPolicy(), $audit);
$groups = new GroupService($repository, $permissions, $users, $provisioner, $passwords, $submissions);
$guard = new ActionGuard($security, $accounts, $permissions, $limiter);
$membership = new GroupMembershipActionHandler('oj_group_add_member', 'add', false, $groups, $guard, $responses, $errors);
$reset = new GroupPasswordResetActionHandler($groups, $guard, $pages, $errors);
$addRequest = static fn (int $target): HttpRequest => new HttpRequest('POST', '/wp-admin/admin-post.php', [], ['_nonce' => 'fixture-nonce', 'group_id' => 1, 'target_user_id' => $target]);
$resetRequest = static fn (int $target, string $nonce = 'fixture-nonce'): HttpRequest => new HttpRequest('POST', '/wp-admin/admin-post.php', [], ['_nonce' => $nonce, 'group_id' => 1, 'target_user_ids' => [$target], 'custom_password' => 'FixtureOnly!234']);

$accounts->actorId = 11;
$membership->handle($addRequest(20));
$reset->handle($resetRequest(30));
groupBoundaryAssert(! isset($repository->members[20]), '일반 학습자는 다른 그룹에 구성원을 추가할 수 없어야 합니다.');
groupBoundaryAssert($GLOBALS['group_boundary_password_writes'] === [], '일반 학습자는 다른 그룹 구성원의 비밀번호를 초기화할 수 없어야 합니다.');
$accounts->actorId = 10;
$reset->handle($resetRequest(30, 'invalid-nonce'));
groupBoundaryAssert($GLOBALS['group_boundary_password_writes'] === [], '그룹 소유자도 nonce 검증 없이 비밀번호를 바꾸면 안 됩니다.');

$roleDefinitions = (require $pluginRoot . '/config/capabilities.php')['roles'];
foreach ($roleDefinitions as $definition) {
    $role = $definition['key'];
    $GLOBALS['group_boundary_users'][20] = groupBoundaryUser(20, [$role], $definition['capabilities']);
    $membership->handle($addRequest(20));
    groupBoundaryAssert(($repository->members[20] ?? null) === 'active', '운영 계정의 그룹 참여 자체와 비밀번호 관리 권한은 구분해야 합니다.');
    $response = $reset->handle($resetRequest(20));
    groupBoundaryAssert(! isset($GLOBALS['group_boundary_password_writes'][20]), '그룹 운영자가 구성원으로 추가한 다른 운영 계정을 탈취할 수 있습니다: ' . $role);
    groupBoundaryAssert(! in_array(20, $GLOBALS['group_boundary_sessions_revoked'], true), '보호된 운영 계정의 세션을 폐기하면 안 됩니다: ' . $role);
    groupBoundaryAssert(! str_contains($response->body(), 'FixtureOnly!234'), '보호된 운영 계정의 초기화 결과에 비밀번호가 노출되면 안 됩니다.');
    groupBoundaryAssert(str_contains($response->body(), 'auth.account_protection.'), '보호 계정 제외 사유를 구체적으로 표시해야 합니다.');
}
foreach (['oj_manage_settings', 'upload_files', 'edit_posts'] as $capability) {
    $GLOBALS['group_boundary_users'][20] = groupBoundaryUser(20, ['subscriber'], [$capability]);
    $reset->handle($resetRequest(20));
    groupBoundaryAssert(! isset($GLOBALS['group_boundary_password_writes'][20]), '학습자 역할에 직접 부여된 운영 권한도 보호해야 합니다: ' . $capability);
}
$response = $reset->handle($resetRequest(30));
groupBoundaryAssert(($GLOBALS['group_boundary_password_writes'][30] ?? null) === 'FixtureOnly!234', '정상 일반 학습자의 초기화는 유지해야 합니다.');
groupBoundaryAssert(in_array(30, $GLOBALS['group_boundary_sessions_revoked'], true), '일반 학습자의 초기화 뒤 기존 세션을 폐기해야 합니다.');
groupBoundaryAssert(($profiles->saved[30] ?? null)?->mustChangePassword() === true, '일반 학습자에게 다음 로그인 비밀번호 변경을 요구해야 합니다.');
groupBoundaryAssert($response->status() === 200, '권한 있는 일반 구성원 초기화 결과를 확인할 수 있어야 합니다.');
foreach ([31, 32, 33] as $targetId) {
    $GLOBALS['group_boundary_users'][$targetId] = groupBoundaryUser($targetId, ['subscriber'], []);
    $repository->members[$targetId] = 'active';
}
$profiles->statuses[31] = AccessStatus::Pending;
$profiles->statuses[32] = AccessStatus::Suspended;
$profiles->missing[] = 33;
$repository->members[9999] = 'active';
foreach ([31 => '가입 승인 대기', 32 => '이용 중지 계정', 33 => '플랫폼 사용자 정보가 없습니다', 9999 => '계정을 찾을 수 없습니다'] as $targetId => $label) {
    $result = $reset->handle($resetRequest($targetId));
    groupBoundaryAssert(str_contains($result->body(), $label), '그룹 초기화에서 실제 제외 사유를 표시해야 합니다: ' . $label);
    groupBoundaryAssert(! isset($GLOBALS['group_boundary_password_writes'][$targetId]), '이용 불가 그룹 구성원의 암호를 변경하면 안 됩니다.');
    groupBoundaryAssert(! str_contains($result->body(), 'FixtureOnly!234'), '거절된 구성원의 초기화 결과에 입력 비밀번호를 노출하면 안 됩니다.');
}
$addonFixture = require dirname(__DIR__) . '/Fixtures/learner-addon-capabilities.php';
$addonFixture['catalog']->commitAndFreeze($addonFixture['entries']);
$declaredLearners = new \CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonLearnerCapabilities($addonFixture['catalog']);
$catalogDetector = new \CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressPrivilegedAccountDetector($declaredLearners);
$catalogPasswords = new PasswordResetService($users, $profiles, new WordPressPasswordResetTargetPolicy($catalogDetector), $audit);
$catalogGroups = new GroupService($repository, $permissions, $users, $provisioner, $catalogPasswords, $submissions);
$catalogReset = new GroupPasswordResetActionHandler($catalogGroups, $guard, $pages, $errors);
$GLOBALS['group_boundary_users'][30] = groupBoundaryUser(30, ['subscriber'], $addonFixture['learner_keys']);
$combinedCapabilities = $GLOBALS['group_boundary_users'][30]->allcaps;
unset($GLOBALS['group_boundary_password_writes'][30]);
$reset->handle($resetRequest(30));
groupBoundaryAssert(! isset($GLOBALS['group_boundary_password_writes'][30]), '기존 개별 필터 방식에서 다중 애드온 학습자의 실제 초기화 실패가 재현돼야 합니다.');
$catalogReset->handle($resetRequest(30, 'invalid-nonce'));
groupBoundaryAssert(! isset($GLOBALS['group_boundary_password_writes'][30]), '학습자 선언으로 nonce 확인을 우회하면 안 됩니다.');
$response = $catalogReset->handle($resetRequest(30));
groupBoundaryAssert(($GLOBALS['group_boundary_password_writes'][30] ?? null) === 'FixtureOnly!234', '12개 학습자 권한을 가진 일반 구성원의 그룹 초기화가 성공해야 합니다.');
groupBoundaryAssert(str_contains($response->body(), '1명 모두 비밀번호 초기화에 성공') && str_contains($response->body(), 'type="password"'), '다중 애드온 그룹 초기화 결과도 성공과 비밀번호 기본 숨김을 표시해야 합니다.');
groupBoundaryAssert(($response->headers()['Cache-Control'] ?? null) === 'private, no-store', '다중 애드온 그룹 초기화 결과를 캐시하면 안 됩니다.');
groupBoundaryAssert(in_array(30, $GLOBALS['group_boundary_sessions_revoked'], true) && $profiles->saved[30]->mustChangePassword(), '그룹 초기화 뒤 세션 폐기와 다음 로그인 비밀번호 변경을 유지해야 합니다.');
groupBoundaryAssert($combinedCapabilities === $GLOBALS['group_boundary_users'][30]->allcaps, '그룹 초기화는 학습자의 기존 권한을 변경하면 안 됩니다.');
foreach ([...$addonFixture['protected_keys'], 'oj_addon_unknown_play', 'oj_addon_rejected_addon_learn', 'oj_addon_incompatible_addon_learn', 'oj_manage_settings', 'manage_options'] as $protectedKey) {
    $GLOBALS['group_boundary_users'][30]->allcaps = $combinedCapabilities + [$protectedKey => true];
    unset($GLOBALS['group_boundary_password_writes'][30]);
    $result = $catalogReset->handle($resetRequest(30));
    groupBoundaryAssert(! isset($GLOBALS['group_boundary_password_writes'][30]), '학습자와 운영·미등록 권한이 혼합되면 그룹 초기화를 거부해야 합니다: ' . $protectedKey);
    groupBoundaryAssert(str_contains($result->body(), 'auth.account_protection.capability_blocked'), '혼합 권한의 보호 사유를 유지해야 합니다.');
    groupBoundaryAssert(str_contains($result->body(), $protectedKey), '그룹 초기화 결과에 실제 차단된 권한 키를 표시해야 합니다.');
    groupBoundaryAssert(! str_contains($result->body(), 'FixtureOnly!234'), '보호 계정의 결과에 비밀번호를 반환하면 안 됩니다.');
}
$GLOBALS['group_boundary_users'][30]->allcaps = $combinedCapabilities;
$GLOBALS['group_boundary_users'][72] = groupBoundaryUser(72, ['subscriber'], $addonFixture['learner_keys']);
$catalogReset->handle($resetRequest(72));
groupBoundaryAssert(! isset($GLOBALS['group_boundary_password_writes'][72]), '학습자 선언으로 그룹 외부 계정의 초기화를 허용하면 안 됩니다.');
$accounts->actorId = 11;
$catalogReset->handle($resetRequest(30));
groupBoundaryAssert(! isset($GLOBALS['group_boundary_password_writes'][30]), '학습자 선언으로 작업자의 그룹 관리 권한을 대신하면 안 됩니다.');
$accounts->actorId = 10;
groupBoundaryAssert(! str_contains((string) json_encode($audit->items), 'FixtureOnly!234'), '그룹 초기화 감사 기록에 비밀번호 원문을 저장하면 안 됩니다.');
$failedLearnerProvider = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAddonCapabilities {
    public int $calls = 0;
    public function capabilityKeys(): array { ++$this->calls; throw new RuntimeException('SECRET /home/private/key'); }
};
$failedDetector = new \CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressPrivilegedAccountDetector($failedLearnerProvider);
$failedPasswords = new PasswordResetService($users, $profiles, new WordPressPasswordResetTargetPolicy($failedDetector), $audit);
$failedGroups = new GroupService($repository, $permissions, $users, $provisioner, $failedPasswords, $submissions);
$failedReset = new GroupPasswordResetActionHandler($failedGroups, $guard, $pages, $errors);
$failedResponse = $failedReset->handle($resetRequest(30));
groupBoundaryAssert(! isset($GLOBALS['group_boundary_password_writes'][30]), '진단이 가능한 선언 조회 장애에서도 비밀번호를 변경하면 안 됩니다.');
groupBoundaryAssert(str_contains($failedResponse->body(), 'auth.account_protection.addon_catalog_failed') && str_contains($failedResponse->body(), 'RuntimeException'), '그룹 결과에서도 권한 선언 조회 오류와 종류를 구체적으로 표시해야 합니다.');
groupBoundaryAssert(! str_contains($failedResponse->body(), 'SECRET') && ! str_contains($failedResponse->body(), '/home/private') && ! str_contains($failedResponse->body(), 'FixtureOnly!234'), '그룹 진단에 원문 예외·경로·비밀번호를 노출하면 안 됩니다.');
$result = $failedGroups->resetMemberPasswords(10, 1, [30], 'FixtureOnly!234')[0];
groupBoundaryAssert($result['reason'] === 'target_protected' && ($result['diagnostic'] ?? null) instanceof \CodingPanda\OjPlatform\Modules\Auth\Contract\AccountProtectionDiagnostic, '기존 결과 코드를 유지하며 선택적인 진단 스냅샷을 제공해야 합니다.');
$callsBefore = $failedLearnerProvider->calls;
$failedReset->handle($resetRequest(72));
$failedReset->handle($resetRequest(30, 'invalid-nonce'));
$accounts->actorId = 11;
$failedReset->handle($resetRequest(30));
groupBoundaryAssert($callsBefore === $failedLearnerProvider->calls, '그룹 범위·nonce·작업자 인가 전에 진단을 실행하면 안 됩니다.');
$accounts->actorId = 10;
fwrite(STDOUT, sprintf("그룹 계정 권한 경계 검사 통과: assertion %d개\n", $boundaryAssertions));
