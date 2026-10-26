<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Auth\Application\AccountInputPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Application\GuardianConsentVerifier;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAdministrationService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Domain\ApprovalMode;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationRequirements;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressPasswordResetTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressPrivilegedAccountDetector;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserApiGateway;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserManagementTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\UpdateUserActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Ui\Message\FlashStore;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

require __DIR__ . '/auth.php';
$boundaryAssertions = $authAssertions;
$GLOBALS['account_boundary_users'] = [];
$GLOBALS['account_boundary_writes'] = [];

function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed
{
    unset($arguments);
    if ($hook !== 'oj_platform_unprivileged_addon_capabilities') {
        return $value;
    }
    $filter = $GLOBALS['account_boundary_addon_filter'] ?? null;

    return is_callable($filter) ? $filter($value) : $value;
}

function get_user_by(string $field, int|string $value): object|false
{
    if (($GLOBALS['account_boundary_lookup_failure'] ?? null) === (int) $value) {
        throw new RuntimeException('가상 계정 권한 조회 실패');
    }
    foreach ($GLOBALS['account_boundary_users'] as $user) {
        $candidate = match ($field) {
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            default => null,
        };
        if ((string) $candidate === (string) $value) {
            return $user;
        }
    }

    return false;
}

function user_can(int $userId, string $capability, mixed ...$arguments): bool
{
    $user = $GLOBALS['account_boundary_users'][$userId] ?? null;
    if (! is_object($user)) {
        return false;
    }
    if ($capability === 'edit_user') {
        return $userId === (int) ($arguments[0] ?? 0) || ! empty($user->allcaps['edit_users']);
    }

    return ! empty($user->allcaps[$capability]);
}

function is_super_admin(int $userId): bool
{
    return (bool) ($GLOBALS['account_boundary_users'][$userId]->super_admin ?? false);
}

function get_user_meta(int $userId, string $key, bool $single): string
{
    return (string) ($GLOBALS['account_boundary_users'][$userId]->{$key} ?? '');
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof Throwable;
}

function wp_update_user(array $fields): int
{
    $GLOBALS['account_boundary_writes'][] = $fields;
    foreach ($fields as $key => $value) {
        $GLOBALS['account_boundary_users'][$fields['ID']]->{$key} = $value;
    }

    return $fields['ID'];
}

$seedBoundaryUser = static function (int $userId, array $roles, array $capabilities): void {
    $GLOBALS['account_boundary_users'][$userId] = (object) [
        'ID' => $userId,
        'user_login' => 'boundary-' . $userId,
        'user_email' => 'boundary-' . $userId . '@example.test',
        'display_name' => '사용자 ' . $userId,
        'nickname' => '관리용 이름 ' . $userId,
        'roles' => $roles,
        'allcaps' => $capabilities,
    ];
};
$seedBoundaryUser(10, ['administrator'], ['read' => true, 'edit_users' => true, 'oj_edit_users' => true, 'oj_deactivate_users' => true, 'oj_approve_user_registrations' => true]);
$seedBoundaryUser(20, ['oj_user_manager'], ['read' => true, 'oj_edit_users' => true, 'oj_deactivate_users' => true]);
$seedBoundaryUser(30, ['subscriber'], ['read' => true, 'level_0' => true, 'subscriber' => true]);
$seedBoundaryUser(31, ['subscriber'], ['read' => true, 'subscriber' => false, 'oj_manage_roles' => false]);
$seedBoundaryUser(32, ['custom_learner'], ['read' => true, 'custom_learner' => true]);
$seedBoundaryUser(40, ['oj_class_manager'], ['read' => true, 'oj_class_manager' => true, 'oj_manage_classes' => true]);
$seedBoundaryUser(41, ['subscriber'], ['read' => true, 'subscriber' => true, 'oj_manage_settings' => true]);
$seedBoundaryUser(42, ['editor'], ['read' => true, 'editor' => true, 'edit_posts' => true]);
$seedBoundaryUser(43, ['subscriber'], ['read' => true, 'subscriber' => true, 'upload_files' => true]);
$seedBoundaryUser(44, ['subscriber'], ['read' => true, 'subscriber' => true, 'unfiltered_html' => true]);
$seedBoundaryUser(45, ['subscriber'], ['read' => true]);
$GLOBALS['account_boundary_users'][45]->super_admin = true;
$seedBoundaryUser(46, ['subscriber'], ['read' => true]);
unset($GLOBALS['account_boundary_users'][46]->allcaps);
$seedBoundaryUser(47, ['subscriber'], ['read' => true]);
$GLOBALS['account_boundary_users'][47]->ID = 999;
$gateway = new WordPressUserApiGateway();
$detector = new WordPressPrivilegedAccountDetector();
$targetPolicy = new WordPressUserManagementTargetPolicy($detector);
$resetTargetPolicy = new WordPressPasswordResetTargetPolicy($detector);
foreach ([30, 31, 32] as $learnerId) {
    $identity = $gateway->findById($learnerId);
    authAssert($identity !== null && ! $detector->isPrivileged($identity), '읽기 전용 학습자와 false 권한·역할 마커는 운영 권한이 아닙니다.');
    authAssert($targetPolicy->allows(20, $identity), '사용자 담당자의 일반 학습자 수정은 보존해야 합니다.');
    authAssert(! $resetTargetPolicy->protectsTarget($identity), '일반 학습자의 승인된 비밀번호 초기화는 보존해야 합니다.');
}
foreach ([10, 20, 40, 41, 42, 43, 44, 45, 46] as $operatorId) {
    $identity = $gateway->findById($operatorId);
    authAssert($identity !== null && $detector->isPrivileged($identity), '역할·직접 권한·불완전 권한 조회의 운영 계정을 보호해야 합니다: ' . $operatorId);
    if ($operatorId !== 20) {
        authAssert(! $targetPolicy->allows(20, $identity), 'WP 대상 수정 권한 없는 하위 운영자가 보호 계정을 바꾸면 안 됩니다.');
    }
    authAssert($resetTargetPolicy->protectsTarget($identity), '공유 비밀번호 정책도 같은 운영 계정을 보호해야 합니다.');
}
$mismatchedIdentity = new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity(47, 'mismatch', 'mismatch@example.test', '불일치', ['subscriber']);
authAssert($detector->isPrivileged($mismatchedIdentity), '조회된 WordPress 계정 ID가 다르면 보호해야 합니다.');
authAssert(! $targetPolicy->allows(10, $mismatchedIdentity), '관리자도 ID가 일치하지 않는 계정을 변경하면 안 됩니다.');
$missingIdentity = new \CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity(404, 'missing', 'missing@example.test', '삭제 계정', ['subscriber']);
authAssert($detector->isPrivileged($missingIdentity), '삭제되거나 존재하지 않는 계정의 권한 조회는 보수적으로 거부해야 합니다.');
authAssert(! $targetPolicy->allows(10, $missingIdentity), '삭제된 대상은 WP 관리 권한이 있어도 수정하면 안 됩니다.');
authAssert(! $targetPolicy->allows(10, $gateway->findById(46)), '대상 권한 정보가 없으면 WP 관리자에게도 변경을 허용하면 안 됩니다.');
$lookupFailureIdentity = $gateway->findById(30);
$GLOBALS['account_boundary_lookup_failure'] = 30;
authAssert($detector->isPrivileged($lookupFailureIdentity), 'WordPress 권한 조회 예외도 비밀번호 초기화 대상 보호를 유지해야 합니다.');
authAssert(! $targetPolicy->allows(10, $lookupFailureIdentity), '계정 조회 실패 시 관리자 변경도 거부해야 합니다.');
unset($GLOBALS['account_boundary_lookup_failure']);
authAssert(! $targetPolicy->allows(0, $gateway->findById(30)), '비로그인 사용자가 관리 대상 검사를 통과하면 안 됩니다.');
authAssert(! $targetPolicy->allows(20, $gateway->findById(30), 'manage_options'), '임의 권한 이름으로 대상 관리 정책을 확장하면 안 됩니다.');
authAssert($targetPolicy->allows(10, $gateway->findById(40)), 'WordPress 대상 수정 권한이 있는 관리자는 운영 계정 정보를 수정할 수 있어야 합니다.');

$learnerAddonCapability = 'oj_addon_fixture_learner_observe';
$unlistedAddonCapability = 'oj_addon_fixture_manage_reports';
$seedBoundaryUser(60, ['subscriber'], ['read' => true, 'subscriber' => true, $learnerAddonCapability => true]);
$seedBoundaryUser(61, ['subscriber'], ['read' => true, 'subscriber' => true, $unlistedAddonCapability => true]);
$seedBoundaryUser(62, ['subscriber', 'oj_class_manager'], ['read' => true, 'subscriber' => true, 'oj_class_manager' => true, $learnerAddonCapability => true, 'oj_manage_classes' => true]);
$seedBoundaryUser(63, ['subscriber'], ['read' => true, $learnerAddonCapability => false]);
$addonLearner = $gateway->findById(60);
$addonLearnerProfile = new UserProfile(60, null, '2000-01-01', AccessStatus::Active, false);
authAssert($detector->isPrivileged($addonLearner), '미등록 애드온 권한은 기본 운영 계정 보호를 유지해야 합니다.');
authAssert(! $targetPolicy->allows(20, $addonLearner), '허용되지 않은 애드온 권한 계정은 일반 사용자 담당자의 수정 대상이 아닙니다.');
authAssert($resetTargetPolicy->protectsTarget($addonLearner), '허용되지 않은 애드온 권한 계정의 암호 초기화를 보호해야 합니다.');
authAssert(! $detector->isPrivileged($gateway->findById(63)), '거부된 애드온 권한은 실제 부여된 운영 권한으로 취급하면 안 됩니다.');
$addonCapabilitiesBefore = $GLOBALS['account_boundary_users'][60]->allcaps;
$GLOBALS['account_boundary_addon_filter'] = static function (mixed $capabilities) use ($learnerAddonCapability): array {
    authSame([], $capabilities, '애드온 예외 필터는 기본적으로 비어 있어야 합니다.');

    return [$learnerAddonCapability];
};
authAssert(! $detector->isPrivileged($addonLearner), '명시적으로 허용한 학습자 애드온 권한만 있는 계정은 운영 계정이 아닙니다.');
authAssert($targetPolicy->allows(20, $addonLearner), '학습자 애드온 권한을 가진 일반 학생의 계정 관리를 보존해야 합니다.');
authAssert(! $resetTargetPolicy->protectsTarget($addonLearner), '허용된 학습자 권한만으로 암호 초기화 대상 보호가 생기면 안 됩니다.');
authAssert($resetTargetPolicy->allows(10, $addonLearner, $addonLearnerProfile), 'WP 대상 수정 권한을 가진 관리자의 학생 암호 초기화를 보존해야 합니다.');
authAssert(! $resetTargetPolicy->allows(20, $addonLearner, $addonLearnerProfile), '예외 목록이 암호 초기화에 필요한 WP 대상 수정 권한을 대신하면 안 됩니다.');
authAssert(! $targetPolicy->allows(0, $addonLearner), '학습자 애드온 예외로 비로그인 관리 요청을 허용하면 안 됩니다.');
authSame($addonCapabilitiesBefore, $GLOBALS['account_boundary_users'][60]->allcaps, '대상 보호 예외는 WordPress 권한을 부여하거나 변경하지 않아야 합니다.');
foreach ([10, 20, 40, 41, 42, 43, 44, 45, 46, 61, 62] as $protectedId) {
    $identity = $gateway->findById($protectedId);
    authAssert($detector->isPrivileged($identity), '애드온 예외가 관리자·운영 권한·다른 애드온·조회 실패 보호를 해제하면 안 됩니다: ' . $protectedId);
    if ($protectedId !== 20) {
        authAssert(! $targetPolicy->allows(20, $identity), '등록된 학습자 애드온과 다른 운영 권한이 함께 있어도 일반 담당자의 수정은 거부해야 합니다.');
    }
    authAssert($resetTargetPolicy->protectsTarget($identity), '애드온 예외 뒤에도 운영 계정 암호 초기화 보호를 유지해야 합니다.');
}
$malformedAddonFilters = [
    null, false, $learnerAddonCapability, new stdClass(),
    ['capability' => $learnerAddonCapability], [1 => $learnerAddonCapability],
    [$learnerAddonCapability, 'manage_options'], [$learnerAddonCapability, 'oj_edit_users'],
    [$learnerAddonCapability, 1], [$learnerAddonCapability, [$learnerAddonCapability]],
    [$learnerAddonCapability, 'oj_addon_*'], [$learnerAddonCapability, 'oj_addon_AAA'],
    [$learnerAddonCapability, 'oj_addon_ab'], [$learnerAddonCapability, 'oj_addon_' . str_repeat('a', 161)],
    [$learnerAddonCapability, $learnerAddonCapability . "\n"], [$learnerAddonCapability, "oj_addon_abc\0"],
];
foreach ($malformedAddonFilters as $malformedFilter) {
    $GLOBALS['account_boundary_addon_filter'] = static fn (mixed $capabilities): mixed => $malformedFilter;
    authAssert($detector->isPrivileged($addonLearner), '잘못된 필터 결과는 유효한 원소가 섞여 있어도 예외 목록 전체를 무효화해야 합니다.');
    authAssert(! $targetPolicy->allows(20, $addonLearner), '잘못된 필터로 일반 담당자의 관리 대상이 확대되면 안 됩니다.');
    authAssert($resetTargetPolicy->protectsTarget($addonLearner), '잘못된 필터로 암호 초기화 대상 보호가 해제되면 안 됩니다.');
}
$filterCalls = 0;
$GLOBALS['account_boundary_addon_filter'] = static function (mixed $capabilities) use (&$filterCalls): never {
    ++$filterCalls;
    throw new RuntimeException('가상 애드온 필터 실패');
};
authAssert($detector->isPrivileged($gateway->findById(10)), '관리자는 필터 실패와 무관하게 항상 보호해야 합니다.');
authAssert($detector->isPrivileged($gateway->findById(45)), 'superadmin은 필터 실패와 무관하게 항상 보호해야 합니다.');
authSame(0, $filterCalls, '관리자와 superadmin 보호는 예외 필터 실행보다 먼저 판단해야 합니다.');
authAssert($detector->isPrivileged($addonLearner), '필터 실행 예외는 운영 계정 보호를 유지해야 합니다.');
authAssert(! $targetPolicy->allows(20, $addonLearner), '필터 실행 예외 시 일반 담당자의 변경을 거부해야 합니다.');
unset($GLOBALS['account_boundary_addon_filter']);
authAssert($detector->isPrivileged($addonLearner), '애드온 필터가 제거되면 기본 보호 정책으로 즉시 복귀해야 합니다.');
// Exercise the real account update/status services with the opted-in learner grant.
$GLOBALS['account_boundary_addon_filter'] = static fn (mixed $capabilities): array => [$learnerAddonCapability];
$GLOBALS['account_boundary_users'][30]->allcaps[$learnerAddonCapability] = true;

$boundaryProfiles = new AuthMemoryProfiles();
foreach ([10, 20, 30, 40] as $userId) {
    $boundaryProfiles->create(new UserProfile($userId, null, '2000-01-01', AccessStatus::Active, false));
}
$boundaryPreferences = new AuthMemoryPreferences();
$boundaryGuardians = new AuthMemoryGuardians();
$boundaryTransaction = new AuthMemoryTransaction();
$accountService = new UserAccountService(
    $gateway, $gateway, $boundaryProfiles, $boundaryPreferences,
    new AuthPolicyProvider(new RegistrationPolicy(true, ApprovalMode::Automatic, 'subscriber', [], false, 14, 24)),
    new GuardianConsentVerifier($boundaryGuardians), new AccountInputPolicy(), $boundaryTransaction, $targetPolicy
);
$boundaryAccounts = new class ($gateway, $boundaryProfiles) implements CurrentAccountProvider {
    public function __construct(private readonly WordPressUserApiGateway $users, private readonly AuthMemoryProfiles $profiles) {}
    public function current(): CurrentAccount { return new CurrentAccount($this->users->findById(20), $this->profiles->find(20)); }
};
$boundarySecurity = new class implements ActionSecurity {
    public function hiddenFields(string $actionKey): string { return ''; }
    public function verify(string $actionKey, HttpRequest $request): bool { return $request->bodyString('_oj_nonce') === 'valid'; }
};
$boundaryPermissions = new class implements Authorization {
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return user_can($userId, $capability); }
    public function canAny(int $userId, array $capabilities): bool { foreach ($capabilities as $capability) { if ($this->can($userId, $capability)) { return true; } } return false; }
    public function isSuperAdmin(int $userId): bool { return is_super_admin($userId); }
};
$boundaryAttempts = new class implements AttemptLimiter {
    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds): bool { return true; }
    public function resetAll(): int { return 0; }
};
$boundaryFlash = new class implements FlashStore {
    public ?UiMessage $message = null;
    public function issue(UiMessage $message, int $userId = 0): string { $this->message = $message; return 'boundary'; }
    public function consume(string $token, int $userId = 0): ?UiMessage { return null; }
};
$handler = new UpdateUserActionHandler($accountService, new ActionGuard($boundarySecurity, $boundaryAccounts, $boundaryPermissions, $boundaryAttempts), new ActionResponder($boundaryFlash));
$editRequest = static fn (int $targetId, string $email): HttpRequest => new HttpRequest('POST', '/wp-admin/admin-post.php', [], [
    '_oj_nonce' => 'valid', 'user_id' => $targetId, 'email' => $email,
    'display_name' => '수정 이름', 'nickname' => '관리용 이름', 'birth_date' => '2000-01-01',
]);
$handler->handle($editRequest(10, 'attacker-inbox@example.test'));
authSame([], $GLOBALS['account_boundary_writes'], '유효 nonce와 사용자 수정 권한이 있어도 관리자 이메일 탈취 요청은 WP API에 전달하면 안 됩니다.');
authSame('boundary-10@example.test', $GLOBALS['account_boundary_users'][10]->user_email, '관리자 복구 이메일을 보존해야 합니다.');
authSame('auth.user_update_failed', $boundaryFlash->message?->code(), '거부한 관리자 변경을 성공으로 안내하면 안 됩니다.');
$handler->handle($editRequest(30, 'learner-update@example.test'));
authSame(1, count($GLOBALS['account_boundary_writes']), '일반 학습자의 관리자 수정은 WP API에 한 번 전달해야 합니다.');
authSame('learner-update@example.test', $GLOBALS['account_boundary_users'][30]->user_email, '일반 학습자의 계정 관리 기능은 유지해야 합니다.');

$administration = new UserAdministrationService($boundaryProfiles, new AuthMemoryAgreements(), $boundaryGuardians, $gateway, $targetPolicy);
$protectedStatusBlocked = false;
try { $administration->changeAccessStatus(10, AccessStatus::Suspended, 20); } catch (DomainException) { $protectedStatusBlocked = true; }
authAssert($protectedStatusBlocked, '하위 운영자가 관리자 이용을 중지하는 요청을 거부해야 합니다.');
authSame(AccessStatus::Active, $boundaryProfiles->find(10)?->accessStatus(), '거부된 관리자 상태 변경은 원래 상태를 유지해야 합니다.');
$administration->changeAccessStatus(30, AccessStatus::Suspended, 20);
authSame(AccessStatus::Suspended, $boundaryProfiles->find(30)?->accessStatus(), '일반 학습자의 이용 중지는 보존해야 합니다.');
$administration->changeAccessStatus(30, AccessStatus::Active, 20);
authSame(AccessStatus::Active, $boundaryProfiles->find(30)?->accessStatus(), '이미 승인된 학습자의 이용 재개는 보존해야 합니다.');
$boundaryProfiles->save($boundaryProfiles->find(30)->withAccessStatus(AccessStatus::Pending));
$boundaryProfiles->saveRegistrationRequirements(30, new RegistrationRequirements([], false));
$approvalDenied = false;
try { $administration->approve(30, 20); } catch (DomainException) { $approvalDenied = true; }
authAssert($approvalDenied, '이용 중지 권한만 있는 담당자는 가입을 승인할 수 없어야 합니다.');
foreach ([AccessStatus::Active, AccessStatus::Suspended] as $bypassStatus) {
    $denied = false;
    try { $administration->changeAccessStatus(30, $bypassStatus, 20); } catch (DomainException) { $denied = true; }
    authAssert($denied, '가입 승인 전에는 이용 상태 변경을 통한 우회를 거부해야 합니다.');
}
$administration->approve(30, 10);
authSame(AccessStatus::Active, $boundaryProfiles->find(30)?->accessStatus(), '승인 권한과 필수 동의 확인이 완료되면 활성화해야 합니다.');

printf("계정 관리 권한 경계 검사 통과: assertion %d개\n", $authAssertions - $boundaryAssertions);
