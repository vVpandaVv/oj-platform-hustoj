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
    $GLOBALS['account_boundary_lookup_reads'][(int) $value] = ($GLOBALS['account_boundary_lookup_reads'][(int) $value] ?? 0) + 1;
    $failAfter = $GLOBALS['account_boundary_lookup_failure_after'][(int) $value] ?? null;
    if (($GLOBALS['account_boundary_lookup_failure'] ?? null) === (int) $value) {
        throw new RuntimeException('가상 계정 권한 조회 실패');
    }
    if ($failAfter !== null && $GLOBALS['account_boundary_lookup_reads'][(int) $value] > $failAfter) {
        throw new RuntimeException('SECRET lookup failure /home/private/source.php');
    }
    if ($field === 'id' && isset($GLOBALS['account_boundary_users'][(int) $value])) {
        return $GLOBALS['account_boundary_users'][(int) $value];
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
    if (($GLOBALS['account_boundary_superadmin_failure'] ?? null) === $userId) {
        throw new TypeError('SECRET superadmin failure');
    }
    return (bool) ($GLOBALS['account_boundary_users'][$userId]->super_admin ?? false);
}

function is_user_logged_in(): bool { return ($GLOBALS['account_boundary_current_user'] ?? 0) > 0; }
function wp_get_current_user(): object { return $GLOBALS['account_boundary_users'][$GLOBALS['account_boundary_current_user']]; }

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

function wp_set_password(string $password, int $userId): void
{
    $GLOBALS['account_boundary_password_writes'][$userId] = $password;
}

final class WP_Session_Tokens
{
    private function __construct(private readonly int $userId) {}
    public static function get_instance(int $userId): self { return new self($userId); }
    public function destroy_all(): void { $GLOBALS['account_boundary_revoked_sessions'][] = $this->userId; }
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
authAssert($resetTargetPolicy->allows(20, $addonLearner, $addonLearnerProfile), 'OJ 사용자 담당자는 별도 WP 계정 수정 권한 없이 일반 학생의 암호를 초기화할 수 있어야 합니다.');
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

$GLOBALS['account_boundary_password_writes'] = [];
$GLOBALS['account_boundary_revoked_sessions'] = [];
$resetAudit = new AuthAuditLog();
$resetService = new \CodingPanda\OjPlatform\Modules\Auth\Application\PasswordResetService($gateway, $boundaryProfiles, $resetTargetPolicy, $resetAudit);
$resetPages = new class implements \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder {
    public function respond(string $title, string $content, string $currentMenu, HttpRequest $request, int $status = 200): \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse
    { return \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse::html($content, $status); }
};
$resetErrors = new class implements \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter {
    public array $errors = [];
    public function report(Throwable $error): void { $this->errors[] = $error; }
};
$resetHandler = new \CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\PasswordResetActionHandler(
    $resetService, new ActionGuard($boundarySecurity, $boundaryAccounts, $boundaryPermissions, $boundaryAttempts), $resetPages, $resetErrors
);
$resetRequest = static fn (array $targetIds, string $nonce = 'valid'): HttpRequest => new HttpRequest('POST', '/wp-admin/admin-post.php', [], [
    '_oj_nonce' => $nonce, 'user_ids' => $targetIds, 'custom_password' => 'BoundaryReset!42',
]);
authAssert(! user_can(20, 'edit_user', 30), '회귀 검사는 WP 계정 수정 권한이 없는 OJ 담당자를 사용해야 합니다.');
$invalidReset = $resetHandler->handle($resetRequest([30], 'invalid'));
authSame([], $GLOBALS['account_boundary_password_writes'], '유효한 nonce 없이 암호를 초기화하면 안 됩니다.');
$resetErrors->errors = [];
$resetResponse = $resetHandler->handle($resetRequest([30]));
authSame('BoundaryReset!42', $GLOBALS['account_boundary_password_writes'][30] ?? null, 'OJ 사용자 담당자의 정상 학생 초기화를 WP API에 전달해야 합니다.');
authAssert(in_array(30, $GLOBALS['account_boundary_revoked_sessions'], true), '정상 초기화 뒤 기존 학생 세션을 폐기해야 합니다.');
authAssert($boundaryProfiles->find(30)?->mustChangePassword() === true, '초기화한 학생은 다음 로그인에서 비밀번호를 변경해야 합니다.');
authAssert(str_contains($resetResponse->body(), '1명 모두 비밀번호 초기화에 성공') && str_contains($resetResponse->body(), 'type="password"'), '초기화 성공과 기본 숨김 비밀번호를 표시해야 합니다.');
authSame('private, no-store', $resetResponse->headers()['Cache-Control'] ?? null, '임시 비밀번호 결과를 캐시하면 안 됩니다.');
foreach ([10, 40] as $protectedId) {
    $resetResult = $resetService->reset(20, [$protectedId], 'BoundaryReset!42')[0];
    authSame('target_protected', $resetResult->resultCode(), '관리자·운영 계정의 보호는 계속 유지해야 합니다.');
    authAssert(! isset($GLOBALS['account_boundary_password_writes'][$protectedId]), '보호 계정의 암호를 변경하면 안 됩니다.');
}
authSame('target_protected', $resetService->reset(10, [40], 'BoundaryReset!42')[0]->resultCode(), 'WP 관리자에게도 기존 보호 대상 제외 규칙을 유지해야 합니다.');
authSame('actor_not_allowed', $resetService->reset(32, [30], 'BoundaryReset!42')[0]->resultCode(), '수정 권한 없는 학습자의 초기화는 거부해야 합니다.');

$boundaryProfiles->create(new UserProfile(31, null, null, AccessStatus::Pending, false));
$boundaryProfiles->create(new UserProfile(32, null, null, AccessStatus::Suspended, false));
$seedBoundaryUser(33, ['subscriber'], ['read' => true, 'subscriber' => true]);
$codes = [31 => 'account_pending', 32 => 'account_suspended', 33 => 'profile_missing', 9999 => 'account_not_found', 20 => 'self_reset_forbidden'];
$resultRows = $resetService->reset(20, array_keys($codes), 'BoundaryReset!42');
foreach ($resultRows as $item) {
    authSame($codes[$item->userId()], $item->resultCode(), '초기화 거절 사유를 개별 구분해야 합니다.');
    authAssert(! $item->succeeded() && $item->temporaryPassword() === null, '거절된 행에 임시 비밀번호를 만들거나 노출하면 안 됩니다.');
    authAssert(! isset($GLOBALS['account_boundary_password_writes'][$item->userId()]), '거절된 대상의 암호를 저장하면 안 됩니다.');
}
$diagnosticResponse = $resetHandler->handle($resetRequest([31, 32, 33, 9999, 40]));
foreach (['가입 승인 대기', '이용 중지 계정', '플랫폼 사용자 정보가 없습니다', '계정을 찾을 수 없습니다', '학습자 권한으로 분류되지 않은'] as $label) {
    authAssert(str_contains($diagnosticResponse->body(), $label), '결과 화면에 실제 초기화 제외 사유를 표시해야 합니다: ' . $label);
}
authAssert(! str_contains($diagnosticResponse->body(), 'BoundaryReset!42'), '모두 거절된 응답에는 입력 비밀번호를 반환하면 안 됩니다.');
authAssert(! str_contains((string) json_encode($resetAudit->entries), 'BoundaryReset!42'), '감사 기록에 비밀번호 원문을 저장하면 안 됩니다.');
authAssert(in_array('account_pending', array_column($resetAudit->entries, 'result'), true), '구체적인 제외 사유를 감사 기록에도 남겨야 합니다.');
authSame([], $resetErrors->errors, '예상 가능한 초기화 거절을 시스템 장애로 기록하면 안 됩니다.');

// All installed addons must work together, without per-addon exception filters.
unset($GLOBALS['account_boundary_addon_filter']);
$addonFixture = require dirname(__DIR__) . '/Fixtures/learner-addon-capabilities.php';
$declaredLearners = new \CodingPanda\OjPlatform\Kernel\Addon\AcceptedAddonLearnerCapabilities($addonFixture['catalog']);
$catalogDetector = new WordPressPrivilegedAccountDetector($declaredLearners);
$catalogResetPolicy = new WordPressPasswordResetTargetPolicy($catalogDetector);
$catalogManagementPolicy = new WordPressUserManagementTargetPolicy($catalogDetector);
$seedBoundaryUser(70, ['subscriber'], array_fill_keys(['read', 'level_0', 'subscriber', ...$addonFixture['learner_keys']], true));
$boundaryProfiles->create(new UserProfile(70, null, null, AccessStatus::Active, false));
$combinedLearner = $gateway->findById(70);
$combinedCapabilities = $GLOBALS['account_boundary_users'][70]->allcaps;
authSame(12, count($addonFixture['learner_keys']), '운영 사이트의 학습자 권한 12개를 함께 재현해야 합니다.');
authAssert($detector->isPrivileged($combinedLearner), '개별 필터만 사용하는 이전 판정에서 실제 오탐 조건이 재현돼야 합니다.');
authSame([], $declaredLearners->capabilityKeys(), '등록 검증 전에는 어떤 애드온 권한도 신뢰하면 안 됩니다.');
authAssert($catalogDetector->isPrivileged($combinedLearner), '등록 검증 전의 학습자 선언으로 보호를 해제하면 안 됩니다.');
$addonFixture['catalog']->commitAndFreeze($addonFixture['entries']);
$declaredKeys = $declaredLearners->capabilityKeys();
$expectedKeys = $addonFixture['learner_keys'];
sort($declaredKeys); sort($expectedKeys);
authSame($expectedKeys, $declaredKeys, '검증 후에는 learner 선언의 WordPress 키만 반환해야 합니다.');
authAssert(! $catalogDetector->isPrivileged($combinedLearner), '여러 애드온 학습 권한을 받은 학생을 운영 계정으로 오탐하면 안 됩니다.');
authAssert($catalogManagementPolicy->allows(20, $combinedLearner), '일반 학생의 사용자 정보 관리도 같은 분류를 사용해야 합니다.');
$catalogResetService = new \CodingPanda\OjPlatform\Modules\Auth\Application\PasswordResetService($gateway, $boundaryProfiles, $catalogResetPolicy, $resetAudit);
$catalogResetHandler = new \CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\PasswordResetActionHandler(
    $catalogResetService, new ActionGuard($boundarySecurity, $boundaryAccounts, $boundaryPermissions, $boundaryAttempts), $resetPages, $resetErrors
);
$catalogResetHandler->handle($resetRequest([70], 'invalid'));
authAssert(! isset($GLOBALS['account_boundary_password_writes'][70]), '선언된 학습 권한으로 nonce 검사를 우회하면 안 됩니다.');
$catalogResponse = $catalogResetHandler->handle($resetRequest([70]));
authSame('BoundaryReset!42', $GLOBALS['account_boundary_password_writes'][70] ?? null, '12개 학습자 권한을 받은 학생의 사용자 목록 초기화가 성공해야 합니다.');
authAssert(in_array(70, $GLOBALS['account_boundary_revoked_sessions'], true), '다중 애드온 학습자의 초기화 뒤에도 세션을 폐기해야 합니다.');
authAssert($boundaryProfiles->find(70)?->mustChangePassword() === true, '다중 애드온 학습자에게 다음 로그인 비밀번호 변경을 요구해야 합니다.');
authAssert(str_contains($catalogResponse->body(), '1명 모두 비밀번호 초기화에 성공') && str_contains($catalogResponse->body(), 'type="password"'), '성공 결과와 기본 숨김을 유지해야 합니다.');
authSame('private, no-store', $catalogResponse->headers()['Cache-Control'] ?? null, '다중 애드온 초기화 결과도 캐시하지 않아야 합니다.');
authSame($combinedCapabilities, $GLOBALS['account_boundary_users'][70]->allcaps, '분류 수정은 학생의 실제 역할이나 권한을 변경하면 안 됩니다.');
authSame('actor_not_allowed', $catalogResetService->reset(32, [70], 'BoundaryReset!42')[0]->resultCode(), '학습 권한 선언은 작업자의 사용자 관리 권한을 대체하지 않습니다.');
$boundaryProfiles->create(new UserProfile(71, null, null, AccessStatus::Active, false));
foreach ([...$addonFixture['protected_keys'], 'oj_addon_rejected_addon_learn', 'oj_addon_incompatible_addon_learn', 'oj_addon_unknown_play', 'oj_manage_classes', 'manage_options', 'edit_users'] as $protectedKey) {
    $seedBoundaryUser(71, ['subscriber'], $combinedCapabilities + [$protectedKey => true]);
    $protectedIdentity = $gateway->findById(71);
    authAssert($catalogDetector->isPrivileged($protectedIdentity), '학습 권한과 함께 있는 운영·미등록 권한도 보호해야 합니다: ' . $protectedKey);
    authAssert(! $catalogManagementPolicy->allows(20, $protectedIdentity), '사용자 담당자가 애드온 교사·운영 계정을 수정하면 안 됩니다: ' . $protectedKey);
    authSame('target_protected', $catalogResetService->reset(20, [71], 'BoundaryReset!42')[0]->resultCode(), '운영·미등록 권한은 사용자 목록 초기화를 계속 차단해야 합니다: ' . $protectedKey);
    authAssert(! isset($GLOBALS['account_boundary_password_writes'][71]), '차단된 계정의 암호를 저장하면 안 됩니다.');
}
foreach ([10, 20, 40, 41, 42, 43, 44, 45, 46] as $protectedId) {
    authAssert($catalogDetector->isPrivileged($gateway->findById($protectedId)), '학습자 선언을 처리한 뒤에도 기존 관리자·운영·불완전 조회 보호를 유지해야 합니다.');
}
$GLOBALS['account_boundary_addon_filter'] = static fn (mixed $keys): array => [$learnerAddonCapability];
authAssert(! $catalogDetector->isPrivileged($addonLearner), '기존 명시적 학습자 필터도 호환성을 유지해야 합니다.');
authAssert(! $catalogDetector->isPrivileged($combinedLearner), '기존 필터와 승인된 manifest의 학습자 권한을 함께 처리해야 합니다.');
$GLOBALS['account_boundary_addon_filter'] = static fn (mixed $keys): array => ['manage_options'];
authAssert($catalogDetector->isPrivileged($combinedLearner), '잘못된 기존 필터는 승인된 선언이 있어도 보수적으로 거부해야 합니다.');
unset($GLOBALS['account_boundary_addon_filter']);
$failedProvider = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAddonCapabilities {
    public function capabilityKeys(): array { throw new RuntimeException('가상 학습자 권한 목록 조회 실패'); }
};
authAssert((new WordPressPrivilegedAccountDetector($failedProvider))->isPrivileged($combinedLearner), '학습자 권한 공급자 오류 시 보호를 유지해야 합니다.');
authAssert(! str_contains((string) json_encode($resetAudit->entries), 'BoundaryReset!42'), '확장된 초기화 감사 기록에도 비밀번호를 남기면 안 됩니다.');

$diagnosticView = new \CodingPanda\OjPlatform\Ui\Component\AccountProtectionDiagnosticView();
$inspection = $catalogDetector->inspect($combinedLearner);
authAssert(! $inspection->isProtected() && $inspection->reason() === 'not_protected', '읽기 전용 진단과 실제 일반 학생 보호 판정이 같아야 합니다.');
authSame(12, $inspection->learnerCapabilityCount(), '인정된 학습자 권한 수를 확인할 수 있어야 합니다.');
authSame('administrator_role', $catalogDetector->inspect($gateway->findById(10))->reason(), '관리자 역할 보호를 별도 식별해야 합니다.');
authSame('super_admin', $catalogDetector->inspect($gateway->findById(45))->reason(), 'WordPress superadmin 판정을 별도 식별해야 합니다.');
authSame('capability_data_invalid', $catalogDetector->inspect($gateway->findById(46))->reason(), '불완전 권한 데이터를 운영 권한으로 오인 표시하면 안 됩니다.');
authSame('identity_mismatch', $catalogDetector->inspect($mismatchedIdentity)->reason(), '계정 ID 불일치를 식별해야 합니다.');
authSame('account_missing', $catalogDetector->inspect($missingIdentity)->reason(), '조회 단계의 계정 없음도 식별해야 합니다.');
$GLOBALS['account_boundary_users'][70]->allcaps += ['oj_manage_settings' => true, 'edit_posts' => true, 'upload_files' => false];
unset($GLOBALS['account_boundary_password_writes'][70]);
$inspection = $catalogDetector->inspect($combinedLearner);
authSame(['oj_manage_settings', 'edit_posts'], $inspection->blockingCapabilities(), '실제 부여된 차단 권한만 모두 표시하고 false 값은 제외해야 합니다.');
authAssert($inspection->isProtected() && $catalogDetector->isPrivileged($combinedLearner), '진단을 추가해도 혼합 운영 권한을 보호해야 합니다.');
$blockedResponse = $catalogResetHandler->handle($resetRequest([70]));
foreach (['oj_manage_settings', 'edit_posts', 'auth.account_protection.capability_blocked', '계정 보호 진단 상세'] as $text) {
    authAssert(str_contains($blockedResponse->body(), $text), '사용자 초기화 실패 결과에 실제 차단 진단을 표시해야 합니다: ' . $text);
}
authAssert(! str_contains($blockedResponse->body(), 'BoundaryReset!42'), '보호 진단에 비밀번호를 노출하면 안 됩니다.');
authAssert(! isset($GLOBALS['account_boundary_password_writes'][70]), '차단 권한 진단 중 비밀번호를 변경하면 안 됩니다.');
$GLOBALS['account_boundary_users'][70]->allcaps = $combinedCapabilities;
foreach ([null, ['bad_key' => 'oj_addon_fixture_learner'], ['manage_options']] as $invalid) {
    $GLOBALS['account_boundary_addon_filter'] = static fn (mixed $keys): mixed => $invalid;
    $inspection = $catalogDetector->inspect($combinedLearner);
    authAssert($inspection->isProtected() && $inspection->fault() === 'addon_filter_invalid', '잘못된 필터 반환 형식은 차단 사유와 구분하여 진단해야 합니다.');
    authSame(0, $inspection->learnerCapabilityCount(), '잘못된 예외 목록을 일부만 인정하면 안 됩니다.');
}
$GLOBALS['account_boundary_addon_filter'] = static function (mixed $keys): never { throw new RuntimeException('SECRET token /home/private/source.php'); };
$inspection = $catalogDetector->inspect($combinedLearner);
authAssert($inspection->isProtected() && $inspection->fault() === 'addon_filter_failed', '필터 실행 예외는 계속 차단하면서 정확한 단계를 알려야 합니다.');
authSame('RuntimeException', $inspection->exceptionType(), '필터 오류 종류를 안전하게 표시해야 합니다.');
authAssert(preg_match('/^account-management-boundaries\.php:[1-9][0-9]*$/D', $inspection->sourceLocation() ?? '') === 1, '원인 파일명과 행만 표시하고 절대경로를 제외해야 합니다.');
$filterResponse = $catalogResetHandler->handle($resetRequest([70]));
authAssert(str_contains($filterResponse->body(), '필터 실행에 실패') && str_contains($filterResponse->body(), 'auth.account_protection.addon_filter_failed'), '필터 오류를 운영 권한 보유로 단정하지 않아야 합니다.');
authAssert(! isset($GLOBALS['account_boundary_password_writes'][70]), '필터 예외를 진단하면서 초기화 보호를 해제하면 안 됩니다.');
foreach (['SECRET', '/home/private', 'BoundaryReset!42', '관리자 또는 운영 권한이 있는'] as $secret) {
    authAssert(! str_contains($filterResponse->body(), $secret), '원문 예외·비밀번호·오해를 부르는 일반 메시지를 진단에 표시하면 안 됩니다.');
}
unset($GLOBALS['account_boundary_addon_filter']);
authSame('addon_catalog_failed', (new WordPressPrivilegedAccountDetector($failedProvider))->inspect($combinedLearner)->fault(), '학습자 선언 조회 예외는 필터 오류와 구분해야 합니다.');
$invalidProvider = new class implements \CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAddonCapabilities {
    public function capabilityKeys(): array { return ['key' => 'oj_addon_fixture_learner']; }
};
authSame('addon_catalog_invalid', (new WordPressPrivilegedAccountDetector($invalidProvider))->inspect($combinedLearner)->fault(), '잘못된 학습자 선언 목록도 구분해야 합니다.');
$GLOBALS['account_boundary_superadmin_failure'] = 70;
$inspection = $catalogDetector->inspect($combinedLearner);
authAssert($inspection->isProtected() && $inspection->fault() === 'super_admin_check_failed' && $inspection->exceptionType() === 'TypeError', 'superadmin 확인 오류도 보수적으로 차단하고 진단해야 합니다.');
unset($GLOBALS['account_boundary_superadmin_failure']);
$GLOBALS['account_boundary_lookup_failure'] = 70;
$inspection = $catalogDetector->inspect($combinedLearner);
authAssert($inspection->isProtected() && $inspection->fault() === 'user_lookup_failed', '계정 조회 실패는 별도 진단해야 합니다.');
unset($GLOBALS['account_boundary_lookup_failure']);
$GLOBALS['account_boundary_lookup_reads'][70] = 0;
$GLOBALS['account_boundary_lookup_failure_after'][70] = 1;
$lookupResponse = $catalogResetHandler->handle($resetRequest([70]));
authAssert(str_contains($lookupResponse->body(), 'auth.account_protection.user_lookup_failed'), '초기 신원 조회 뒤 보호 검사 조회가 실패한 실제 경로를 진단해야 합니다.');
authAssert(! str_contains($lookupResponse->body(), 'SECRET'), '조회 예외 메시지를 표시하면 안 됩니다.');
authAssert(! isset($GLOBALS['account_boundary_password_writes'][70]), '권한 조회 실패에서도 초기화 보호를 유지해야 합니다.');
unset($GLOBALS['account_boundary_lookup_failure_after']);
$redacted = new \CodingPanda\OjPlatform\Modules\Auth\Contract\AccountProtectionDiagnostic(true, 'capability_blocked', ['<script>SECRET</script>']);
authAssert(! str_contains($diagnosticView->render($redacted), 'SECRET') && str_contains($diagnosticView->render($redacted), '비표준 권한 키'), '비표준 권한 키는 원문을 노출하지 않고 식별해야 합니다.');

$detailRoles = new \CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry('roles', \CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition::class);
$detailDirectory = new \CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService($boundaryProfiles, $gateway, $gateway);
$detailAccounts = new \CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService($gateway, $boundaryProfiles);
$detailHandler = new \CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\UserDetailPageHandler(
    $resetPages, $detailDirectory, $detailAccounts, $boundaryPermissions, $detailRoles,
    new \CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter(), $catalogResetPolicy
);
$detailDefinition = array_values(array_filter(require dirname(__DIR__, 2) . '/config/routes.php', static fn (array $route): bool => $route['name'] === 'console.users.detail'))[0];
$detailRoute = new \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch(
    \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::fromArray($detailDefinition), ['user_id' => '70']
);
$GLOBALS['account_boundary_current_user'] = 20;
$passwordWritesBefore = $GLOBALS['account_boundary_password_writes'];
$profileBefore = $boundaryProfiles->find(70);
$capsBefore = $GLOBALS['account_boundary_users'][70]->allcaps;
$detailResponse = $detailHandler->handle($detailRoute, new HttpRequest('GET', '/oj-console/users/70'));
authAssert(str_contains($detailResponse->body(), '계정 보호 진단') && str_contains($detailResponse->body(), 'auth.account_protection.not_protected'), '사용자 수정 권한자는 상세 GET에서 비밀번호 초기화 없이 진단할 수 있어야 합니다.');
authSame('private, no-store', $detailResponse->headers()['Cache-Control'] ?? null, '계정별 권한 진단 화면을 캐시하지 않아야 합니다.');
authSame($passwordWritesBefore, $GLOBALS['account_boundary_password_writes'], '상세 진단은 암호를 변경하지 않아야 합니다.');
authSame($profileBefore, $boundaryProfiles->find(70), '상세 진단은 계정 상태를 변경하지 않아야 합니다.');
authSame($capsBefore, $GLOBALS['account_boundary_users'][70]->allcaps, '상세 진단은 권한을 변경하지 않아야 합니다.');
$GLOBALS['account_boundary_addon_filter'] = static function (mixed $keys): never { throw new TypeError('SECRET token /home/private/key'); };
$failedDetail = $detailHandler->handle($detailRoute, new HttpRequest('GET', '/oj-console/users/70'));
authAssert(str_contains($failedDetail->body(), 'auth.account_protection.addon_filter_failed') && ! str_contains($failedDetail->body(), 'SECRET'), '상세 GET에서도 필터 오류를 안전하게 확인할 수 있어야 합니다.');
authSame($passwordWritesBefore, $GLOBALS['account_boundary_password_writes'], '오류 진단 GET도 비밀번호 변경 없이 동작해야 합니다.');
unset($GLOBALS['account_boundary_addon_filter']);
$GLOBALS['account_boundary_users'][20]->allcaps['oj_edit_users'] = false;
$deniedDetail = $detailHandler->handle($detailRoute, new HttpRequest('GET', '/oj-console/users/70'));
authAssert(! str_contains($deniedDetail->body(), 'auth.account_protection.'), '사용자 조회만 가능한 담당자에게 보호 진단을 노출하면 안 됩니다.');
$GLOBALS['account_boundary_users'][20]->allcaps['oj_manage_roles'] = true;
$roleDetail = $detailHandler->handle($detailRoute, new HttpRequest('GET', '/oj-console/users/70'));
authAssert(str_contains($roleDetail->body(), 'auth.account_protection.'), '역할 관리자는 읽기 전용 보호 진단을 확인할 수 있어야 합니다.');
unset($GLOBALS['account_boundary_users'][20]->allcaps['oj_manage_roles']);
$GLOBALS['account_boundary_users'][20]->allcaps['oj_edit_users'] = true;
$GLOBALS['account_boundary_current_user'] = 0;
$anonymousDetail = $detailHandler->handle($detailRoute, new HttpRequest('GET', '/oj-console/users/70'));
authAssert(! str_contains($anonymousDetail->body(), 'auth.account_protection.'), '로그인하지 않은 요청에 보호 진단을 노출하면 안 됩니다.');
unset($GLOBALS['account_boundary_current_user']);

printf("계정 관리 권한 경계 검사 통과: assertion %d개\n", $authAssertions - $boundaryAssertions);
