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
    public function find(int $userId): ?UserProfile
    {
        return get_userdata($userId) === false ? null : new UserProfile($userId, null, null, AccessStatus::Active, false);
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
fwrite(STDOUT, sprintf("그룹 계정 권한 경계 검사 통과: assertion %d개\n", $boundaryAssertions));
