<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ManagedUser;
use CodingPanda\OjPlatform\Modules\Auth\Domain\ApprovalMode;
use CodingPanda\OjPlatform\Modules\Auth\Domain\RegistrationPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserApiGateway;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressManagedUserNameGuard;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressManagedUserNameUpdater;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressManagedUserNameWriteScope;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserIdentityAnonymizer;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\UserEditPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\UserListPageHandler;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

require __DIR__ . '/auth.php';
$nameAssertions = $authAssertions;
$GLOBALS['name_users'] = [];
$GLOBALS['name_meta'] = [];
$GLOBALS['name_writes'] = [];
$GLOBALS['name_filters'] = [];

function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArguments = 1): bool
{
    $GLOBALS['name_filters'][$hook][] = [$callback, $acceptedArguments];
    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$arguments): mixed
{
    foreach ($GLOBALS['name_filters'][$hook] ?? [] as [$callback, $acceptedArguments]) {
        $value = $callback(...array_slice([$value, ...$arguments], 0, $acceptedArguments));
    }
    return $value;
}

function update_user_meta(int $id, string $key, mixed $value, mixed $previous = ''): bool
{
    $check = apply_filters('update_user_metadata', null, $id, $key, $value, $previous);
    if ($check !== null) { return (bool) $check; }
    if ($key === 'nickname') { $GLOBALS['name_meta'][$id] = $value; }
    return true;
}

function add_user_meta(int $id, string $key, mixed $value, bool $unique = false): bool
{
    $check = apply_filters('add_user_metadata', null, $id, $key, $value, $unique);
    if ($check !== null) { return (bool) $check; }
    if ($key === 'nickname') { $GLOBALS['name_meta'][$id] = $value; }
    return true;
}

function delete_user_meta(int $id, string $key, mixed $value = ''): bool
{
    $check = apply_filters('delete_user_metadata', null, $id, $key, $value, false);
    if ($check !== null) { return (bool) $check; }
    if ($key === 'nickname') { unset($GLOBALS['name_meta'][$id]); }
    return true;
}

function wp_slash(mixed $value): mixed { return $value; }
function get_userdata(int $id): object|false { return $GLOBALS['name_users'][$id] ?? false; }

function wp_insert_user(array $fields): int
{
    $id = count($GLOBALS['name_users']) + 1;
    $GLOBALS['name_users'][$id] = (object) ($fields + ['ID' => $id, 'roles' => [$fields['role']]]);
    $GLOBALS['name_meta'][$id] = $fields['nickname'];
    return $id;
}

function wp_update_user(array $fields): int
{
    $GLOBALS['name_writes'][] = $fields;
    if (isset($GLOBALS['name_before_update'])) {
        ($GLOBALS['name_before_update'])();
        unset($GLOBALS['name_before_update']);
    }
    $id = $fields['ID'];
    $fields = apply_filters('wp_pre_insert_user_data', $fields, true, $id, $fields);
    foreach ($fields as $key => $value) {
        if ($key === 'nickname') {
            update_user_meta($id, $key, $value);
        } else {
            $GLOBALS['name_users'][$id]->{$key} = $value;
        }
    }
    return $id;
}

function get_user_by(string $field, int|string $value): object|false
{
    authSame('id', $field, 'Identity gateway must reload the user by numeric ID.');
    return $GLOBALS['name_users'][(int) $value] ?? false;
}

function get_user_meta(int $id, string $key, bool $single): string
{
    authAssert($key === 'nickname' && $single, 'Student name must use the existing WordPress nickname meta.');
    return $GLOBALS['name_meta'][$id] ?? '';
}

function is_wp_error(mixed $value): bool { return $value instanceof Throwable; }

$gateway = new WordPressUserApiGateway();
$createdName = $gateway->createUser('names-user', 'names@example.test', 'password', '최초 이름', 'subscriber');
authSame('최초 이름', $createdName->nickname(), 'New accounts initialize the student name from the display name.');
$managedName = $gateway->updateProfileIdentity($createdName->userId(), $createdName->email(), '공개 이름', '김학생');
authSame('김학생', $managedName->nickname(), 'Managed identity updates persist an independent student name.');
$selfName = $gateway->updateUser($createdName->userId(), $createdName->email(), '바꾼 공개 이름');
authSame('김학생', $selfName->nickname(), 'Self updates preserve the managed student name.');
authSame('바꾼 공개 이름', $selfName->displayName(), 'Self updates still change the public display name.');
authAssert(! array_key_exists('nickname', end($GLOBALS['name_writes'])), 'Self updates must omit nickname from the WordPress write, not replay a stale value.');
$GLOBALS['name_before_update'] = static function () use ($createdName): void {
    $GLOBALS['name_meta'][$createdName->userId()] = '동시에 수정한 학생 이름';
};
$concurrentName = $gateway->updateUser($createdName->userId(), $createdName->email(), '다음 공개 이름');
authSame('동시에 수정한 학생 이름', $concurrentName->nickname(), 'Concurrent administrator changes must survive self updates.');

$nameActions = new class implements ActionUrlProvider {
    public function actionUrl(): string { return '/wp-admin/admin-post.php'; }
    public function ajaxUrl(): string { return '/wp-admin/admin-ajax.php'; }
};
$nameSecurity = new class implements ActionSecurity {
    public function hiddenFields(string $actionKey): string { return ''; }
    public function verify(string $actionKey, HttpRequest $request): bool { return true; }
};
foreach ([
    'actionUrls' => $nameActions,
    'security' => $nameSecurity,
    'registrationPolicy' => new AuthPolicyProvider(new RegistrationPolicy(true, ApprovalMode::Automatic, 'subscriber', [], false, 14, 24)),
] as $key => $value) {
    $settingsPageReflection->getProperty($key)->setValue($settingsPage, $value);
}
$settingsHtml = $settingsPageReflection->getMethod('settingsForm')->invoke(
    $settingsPage, 'learner24', 'learner24@example.test', '공개 이름', '학습 모임', '', '', new UserPreferences(1), false
);
authAssert(str_contains($settingsHtml, 'name="display_name"'), 'Settings must expose the public display name.');
authAssert(! str_contains($settingsHtml, 'nickname') && ! str_contains($settingsHtml, '학생 이름'), 'Settings must not expose the managed name.');

$profileUsers->updateProfileIdentity($profileOwner->userId(), $profileOwner->email(), '공개 이름', '김학생');
$nameManaged = new ManagedUser($profileUsers->findById($profileOwner->userId()), $profileStore->find($profileOwner->userId()));
$listReflection = new ReflectionClass(UserListPageHandler::class);
$listHandler = $listReflection->newInstanceWithoutConstructor();
$listReflection->getProperty('dates')->setValue($listHandler, new SiteDateFormatter());
$listTable = $listReflection->getMethod('table');
$listHtml = $listTable->invoke($listHandler, [$nameManaged], true, true, true);
authAssert(str_contains($listHtml, '<th>학생 이름</th>') && str_contains($listHtml, '김학생'), 'User managers must see student names in the list.');
$approvalHtml = $listTable->invoke($listHandler, [$nameManaged], false, true, false);
authAssert(! str_contains($approvalHtml, '학생 이름') && ! str_contains($approvalHtml, '김학생'), 'Approval-only users must not receive student names.');
authAssert(str_contains($listTable->invoke($listHandler, [], true, true, true), 'colspan="9"'), 'Selectable management table must have nine columns.');
authAssert(str_contains($listTable->invoke($listHandler, [], false, true, false), 'colspan="7"'), 'Approval-only empty table must retain seven columns.');
$consolePages = new class implements ConsoleResponder {
    public function respond(string $title, string $content, string $currentMenu, HttpRequest $request, int $status = 200): HttpResponse {
        return HttpResponse::html($content, $status);
    }
};
$namePermissions = new class implements Authorization {
    public bool $allowed = true;
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return $this->allowed; }
    public function canAny(int $userId, array $capabilities): bool { return $this->allowed; }
    public function isSuperAdmin(int $userId): bool { return $this->allowed; }
};
$editHandler = new UserEditPageHandler(
    $consolePages, new UserDirectoryService($profileStore, $profileUsers, $matchingSearch),
    $nameActions, $nameSecurity, new CurrentAccountService($profileUsers, $profileStore), $namePermissions,
    $profileEditPolicy
);
$editHtml = $editHandler->handle($profileRouteMatch, $profileRequest)->body();
authAssert(str_contains($editHtml, '학생 이름 (관리용)') && str_contains($editHtml, 'value="김학생"'), 'Management form must label and load the student name.');
$namePermissions->allowed = false;
authAssert(! str_contains($editHandler->handle($profileRouteMatch, $profileRequest)->body(), 'name="nickname"'), 'Users without edit permission must not receive the managed name field.');

$protectedNameProfiles = new AuthMemoryProfiles();
$protectedNameProfiles->create(new \CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile(
    $createdName->userId(), null, null,
    \CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus::Active, false
));
$nameGuard = new WordPressManagedUserNameGuard($protectedNameProfiles);
$nameGuard->register();
foreach (['add_user_metadata', 'update_user_metadata', 'delete_user_metadata'] as $hook) {
    authSame(1, count($GLOBALS['name_filters'][$hook] ?? []), 'Managed name protection must cover every ordinary metadata mutation.');
}
$beforeNativeName = $gateway->findById($createdName->userId())->nickname();
// REST users/me and native profile editing both eventually call wp_update_user.
wp_update_user(['ID' => $createdName->userId(), 'nickname' => '본인이 고친 관리 이름', 'display_name' => '새 공개 표시 이름']);
authSame($beforeNativeName, $gateway->findById($createdName->userId())->nickname(), 'Native profile writes must not change the administrator-managed OJ name.');
authSame('새 공개 표시 이름', $gateway->findById($createdName->userId())->displayName(), 'Managed-name protection must preserve native public display-name updates.');
authSame(false, update_user_meta($createdName->userId(), 'nickname', '직접 변경'), 'Direct metadata updates must not bypass managed-name protection.');
authSame(false, add_user_meta($createdName->userId(), 'nickname', '추가 값'), 'Adding a duplicate nickname must not bypass managed-name protection.');
authSame(false, delete_user_meta($createdName->userId(), 'nickname'), 'Deleting the nickname must not fall back to a self-controlled display name.');
authSame($beforeNativeName, $gateway->findById($createdName->userId())->nickname(), 'Rejected metadata writes must preserve the stored OJ name.');
authSame(true, update_user_meta($createdName->userId(), 'description', 'public bio'), 'Unrelated user metadata must remain editable.');
$ordinaryWpName = $gateway->createUser('wp-only', 'wp-only@example.test', 'password', 'WP only', 'subscriber');
authSame(true, update_user_meta($ordinaryWpName->userId(), 'nickname', 'WP nickname'), 'WordPress-only users without an OJ profile must retain their native nickname.');
authSame('WP nickname', $gateway->findById($ordinaryWpName->userId())->nickname(), 'Ordinary WordPress nickname updates must still persist.');
// Pre-existing empty nicknames otherwise inherit the self-controlled display name.
$GLOBALS['name_meta'][$createdName->userId()] = '';
$previousFallback = $gateway->findById($createdName->userId())->nickname();
wp_update_user(['ID' => $createdName->userId(), 'nickname' => '우회 입력', 'display_name' => '본인이 바꾼 새 공개 이름']);
authSame($previousFallback, $gateway->findById($createdName->userId())->nickname(), 'An empty legacy nickname must be fixed to the previous managed fallback before public-name updates.');
authSame('본인이 바꾼 새 공개 이름', $gateway->findById($createdName->userId())->displayName(), 'Fixing a legacy managed fallback must preserve valid public-name updates.');
authAssert(! WordPressManagedUserNameWriteScope::allows($createdName->userId()), 'Legacy name initialization must close its write scope.');

$managedAfterGuard = $gateway->updateProfileIdentity($createdName->userId(), $createdName->email(), '공개 이름', '승인된 관리자 수정');
authSame('승인된 관리자 수정', $managedAfterGuard->nickname(), 'Authorized OJ management writes must pass through the protected metadata boundary.');
$groupNameUpdater = new WordPressManagedUserNameUpdater($gateway, new \CodingPanda\OjPlatform\Modules\Auth\Application\AccountInputPolicy());
authSame('승인된 그룹 수정', $groupNameUpdater->update($createdName->userId(), '승인된 그룹 수정', '승인된 관리자 수정'), 'Authorized group management must still update a managed name.');
authAssert(! WordPressManagedUserNameWriteScope::allows($createdName->userId()), 'A successful write must close its authorization scope.');
WordPressManagedUserNameWriteScope::run($createdName->userId(), static function () use ($createdName): void {
    authAssert(WordPressManagedUserNameWriteScope::allows($createdName->userId()), 'The scope must allow its exact target.');
    authAssert(! WordPressManagedUserNameWriteScope::allows($createdName->userId() + 100), 'The scope must not authorize another account.');
    WordPressManagedUserNameWriteScope::run($createdName->userId(), static function (): void {});
    authAssert(WordPressManagedUserNameWriteScope::allows($createdName->userId()), 'Closing a nested scope must preserve the outer scope.');
});
try {
    WordPressManagedUserNameWriteScope::run($createdName->userId(), static function (): void { throw new RuntimeException('synthetic write failure'); });
} catch (RuntimeException) {}
authAssert(! WordPressManagedUserNameWriteScope::allows($createdName->userId()), 'An exception must close the managed-name write scope.');
authSame(false, update_user_meta($createdName->userId(), 'nickname', '예외 후 우회'), 'A failed trusted write must not leave native updates authorized.');
$failedLookupGuard = new WordPressManagedUserNameGuard(new WordPressUserProfileRepository(new stdClass()));
authSame(false, $failedLookupGuard->filterWrite(null, $createdName->userId(), 'nickname', 'DB failure'), 'A profile lookup error must reject managed-name writes.');
$queryErrorDatabase = new class {
    public string $last_error = '';
    public function prepare(string $sql, mixed ...$values): string { return $sql; }
    public function get_row(string $sql, string $format): ?array { $this->last_error = 'synthetic unavailable profile table'; return null; }
};
$queryErrorGuard = new WordPressManagedUserNameGuard(new WordPressUserProfileRepository($queryErrorDatabase));
authSame(false, $queryErrorGuard->filterWrite(null, $createdName->userId(), 'nickname', 'query failure'), 'A wpdb null caused by an SQL error must not be treated as a WordPress-only user.');
authAssert(! array_key_exists('display_name', $queryErrorGuard->preserveFallbackName(['display_name' => 'unsafe fallback change'], true, $createdName->userId(), [])), 'An SQL error must also refuse an unverifiable legacy fallback change.');
authSame(false, $nameGuard->filterDelete(null, 0, 'nickname', '', true), 'Deleting the nickname key across users must not bypass per-account protection.');

$nameAnonymizer = new WordPressUserIdentityAnonymizer(new class {
    public string $users = 'wp_users';
    public function update(string $table, array $data, array $where, array $formats, array $whereFormats): int {
        foreach ($data as $key => $value) { $GLOBALS['name_users'][$where['ID']]->{$key} = $value; }
        return 1;
    }
});
$nameAnonymizer->anonymize($createdName->userId());
authSame('삭제된 사용자', $gateway->findById($createdName->userId())->nickname(), 'Account erasure must still remove the protected managed name.');
authAssert(! WordPressManagedUserNameWriteScope::allows($createdName->userId()), 'Account erasure must also close its write scope.');

printf("User names: %d checks passed.\n", $authAssertions - $nameAssertions);
if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    $css = file_get_contents($pluginRoot . '/resources/ui/css/oj-ui-system.css');
    foreach (['settings' => $settingsHtml, 'users' => $listHtml, 'edit' => $editHtml, 'profile' => $ownerProfileResponse->body()] as $screen => $html) {
        file_put_contents($argv[2] . '/' . $screen . '.html', '<!doctype html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style><title>사용자 이름</title></head><body class="ojp-app"><main class="ojp-main"><div class="ojp-shell">' . $html . '</div></main></body></html>');
    }
}
