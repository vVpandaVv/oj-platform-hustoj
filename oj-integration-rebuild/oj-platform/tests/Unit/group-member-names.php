<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Auth\Application\AccountInputPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserProfile;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressManagedUserNameUpdater;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupMemberNamesService;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Action\SaveGroupMemberNamesActionHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Console\GroupMemberPageHandler;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\GroupHtml;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;

require __DIR__ . '/learning-spaces.php';
$startAssertions = $learningAssertions;
$nameUsers = new LearningUsers();
foreach ([2 => '공개하늘', 4 => '공개민준', 5 => '다른그룹', 6 => '대기학생', 7 => '제외학생', 8 => '전역관리자'] as $id => $display) {
    $nameUsers->items[$id] = new UserIdentity($id, 'learner' . $id, 'learner' . $id . '@example.test', $display, $id === 8 ? ['administrator'] : ['subscriber'], '관리용' . $id);
}
$nameRepository = new LearningGroups();
$nameGroup = new LearningGroup(1, '이름 관리 그룹', 'names', '', 'private', 'manual', true, null, 'normal', null, 1, null);
$nameRepository->groups[1] = $nameGroup;
$nameRepository->members[1] = [1 => 'active', 2 => 'active', 3 => 'active', 4 => 'active', 6 => 'pending', 7 => 'removed', 8 => 'active'];
$nameRepository->managers[1] = [3];
$nameGroups = new GroupService($nameRepository, new LearningAuthorization(), $nameUsers, new LearningProvisioner($nameUsers), new LearningPasswordResetter(), new LearningSubmissions());
$GLOBALS['managed_name_users'] = $nameUsers;
$GLOBALS['managed_name_meta'] = [];
foreach ($nameUsers->items as $id => $user) { $GLOBALS['managed_name_meta'][$id] = $user->nickname(); }
$GLOBALS['managed_name_writes'] = [];

function wp_slash(string $value): string { return addslashes($value); }
function get_user_meta(int $id, string $key, bool $single): string
{
    learningAssert($key === 'nickname' && $single, 'Only the existing nickname metadata may be read.');
    return $GLOBALS['managed_name_meta'][$id] ?? '';
}
function update_user_meta(int $id, string $key, string $value, string $previous): bool
{
    learningSame('nickname', $key, 'The group action must only write nickname metadata.');
    if (($GLOBALS['managed_name_fail'] ?? false) || ($previous !== '' && ($GLOBALS['managed_name_meta'][$id] ?? '') !== $previous)) { return false; }
    $value = stripslashes($value);
    $GLOBALS['managed_name_writes'][] = [$id, $key, $value];
    $GLOBALS['managed_name_meta'][$id] = $value;
    $users = $GLOBALS['managed_name_users'];
    $user = $users->items[$id];
    $users->items[$id] = new UserIdentity($id, $user->userLogin(), $user->email(), $user->displayName(), $user->roles(), $value, $user->registeredAt());
    return true;
}
$nameErrors = new class implements ErrorReporter {
    public array $errors = [];
    public function report(Throwable $error): void { $this->errors[] = $error; }
};
$nameUpdater = new WordPressManagedUserNameUpdater($nameUsers, new AccountInputPolicy());
$nameService = new GroupMemberNamesService($nameGroups, $nameUsers, $nameUpdater, new LearningAuthorization(), $nameErrors);
$change = static fn (int $id, string $name): array => ['user_id' => $id, 'name' => $name, 'expected_name' => $nameUsers->items[$id]->nickname()];
$before = $nameUsers->items[2];
$results = $nameService->save(3, 1, [$change(2, '김하늘'), $change(4, '최민준')]);
learningSame([true, true], array_column($results, 'saved'), 'A delegated group manager can save multiple managed names at once.');
learningSame('김하늘', $nameUsers->items[2]->nickname(), 'First member name is saved.');
learningSame('최민준', $nameUsers->items[4]->nickname(), 'Second member name is saved.');
learningSame([$before->userLogin(), $before->displayName(), $before->email(), $before->roles()], [$nameUsers->items[2]->userLogin(), $nameUsers->items[2]->displayName(), $nameUsers->items[2]->email(), $nameUsers->items[2]->roles()], 'Managed-name edits leave public identity and roles unchanged.');
learningSame([2, 4], $nameService->editableUserIds($nameGroup, $nameUsers->findMany(array_keys($nameUsers->items))), 'Only active ordinary group members can be edited.');

foreach ([1, 3, 5, 6, 7, 8, 999] as $target) {
    $writes = count($GLOBALS['managed_name_writes']);
    learningThrows(fn () => $nameService->save(3, 1, [$change(2, '변경하면 안 됨'), ['user_id' => $target, 'name' => '금지', 'expected_name' => '이전']]), 'Mixed batches containing protected or out-of-scope members must be rejected.');
    learningSame($writes, count($GLOBALS['managed_name_writes']), 'Invalid scope must not partially change an earlier valid row.');
}
learningThrows(fn () => $nameService->save(2, 1, [$change(4, '금지')]), 'Ordinary members cannot edit names.');
$nameRepository->managers[1] = [];
learningThrows(fn () => $nameService->save(3, 1, [$change(2, '금지')]), 'Revoked managers must be rejected at save time.');
$nameRepository->managers[1] = [3];
foreach ([[], array_fill(0, 101, $change(2, '이름')), [$change(2, '이름'), $change(2, '중복')], [['user_id' => '2', 'name' => '이름', 'expected_name' => '이전']], [['user_id' => 2, 'name' => [], 'expected_name' => '이전']], ['not-list' => $change(2, '이름')]] as $invalid) {
    learningThrows(fn () => $nameService->save(3, 1, $invalid), 'Malformed, duplicate and oversized batches must be rejected.');
}
$results = $nameService->save(3, 1, [$change(2, '  '), $change(4, '수정민준')]);
learningSame([false, true], array_column($results, 'saved'), 'Validation failures are reported per member without losing successful updates.');
learningSame('김하늘', $nameUsers->items[2]->nickname(), 'Blank names must not erase the current name.');
$results = $nameService->save(3, 1, [$change(2, str_repeat('가', 101))]);
learningSame(false, $results[0]['saved'], 'Managed names retain the shared 100-character limit.');
$results = $nameService->save(3, 1, [['user_id' => 2, 'name' => '덮어쓰기', 'expected_name' => '오래된 이름']]);
learningSame(false, $results[0]['saved'], 'Stale edits must not overwrite another administrator.');
learningSame('김하늘', $nameUsers->items[2]->nickname(), 'A stale edit preserves the saved name.');
$results = $nameService->save(3, 1, [['user_id' => 2, 'name' => '김하늘', 'expected_name' => '오래된 이름']]);
learningSame(true, $results[0]['saved'], 'Retry after a lost response is idempotent when the requested name is already saved.');
$specialName = "김'학생\\이름";
learningSame($specialName, $nameUpdater->update(2, $specialName, '김하늘'), 'WordPress unslashing must preserve backslashes and quotes.');
$GLOBALS['managed_name_fail'] = true;
$results = $nameService->save(3, 1, [$change(2, '실패하는 저장')]);
learningSame(false, $results[0]['saved'], 'A failed metadata write must not be presented as saved.');
learningAssert(count($nameErrors->errors) === 1 && ! str_contains($results[0]['error'], 'WordPress'), 'Unexpected failures are recorded and shown safely.');
$GLOBALS['managed_name_fail'] = false;
$GLOBALS['managed_name_meta'][4] = '';
$nameUsers->items[4] = new UserIdentity(4, 'learner4', 'learner4@example.test', '공개민준');
learningSame('최민준', $nameUpdater->update(4, '최민준', '공개민준'), 'Legacy accounts with missing nickname can be initialized from the displayed fallback.');
$nameRepository->groups[1] = new LearningGroup(1, '보관', 'archived', '', 'private', 'manual', true, null, 'normal', null, 1, '2026-09-17 00:00:00');
learningThrows(fn () => $nameService->save(3, 1, [$change(2, '금지')]), 'Archived groups cannot edit account names.');
$nameRepository->groups[1] = $nameGroup;

$nameSecurity = new class implements ActionSecurity {
    public bool $valid = true;
    public function hiddenFields(string $key): string { return '<input type="hidden" name="action" value="' . $key . '"><input type="hidden" name="_nonce" value="fixture">'; }
    public function verify(string $key, HttpRequest $request): bool { return $this->valid && $key === SaveGroupMemberNamesActionHandler::ACTION; }
};
$nameAccount = new class($nameUsers) implements CurrentAccountProvider {
    public int $actor = 3;
    public bool $active = true;
    public function __construct(private LearningUsers $users) {}
    public function current(): CurrentAccount { return new CurrentAccount($this->users->findById($this->actor), new UserProfile($this->actor, null, null, $this->active ? AccessStatus::Active : AccessStatus::Pending, false)); }
};
$nameLimiter = new class implements AttemptLimiter {
    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds): bool { return true; }
    public function resetAll(): int { return 0; }
};
$nameGuard = new ActionGuard($nameSecurity, $nameAccount, new LearningAuthorization(), $nameLimiter);
$nameAction = new SaveGroupMemberNamesActionHandler($nameService, $nameGuard, $nameErrors);
$nameRequest = static fn (string $payload): HttpRequest => new HttpRequest('POST', '/wp-admin/admin-post.php', [], ['group_id' => 1, 'name_changes' => $payload]);
$response = $nameAction->handle($nameRequest(json_encode([$change(2, '김하늘'), $change(4, '최민준')], JSON_THROW_ON_ERROR)));
learningSame(200, $response->status(), 'The authenticated batch action returns JSON success.');
learningSame('private, no-store', $response->headers()['Cache-Control'], 'Name responses must never be cached.');
learningSame(2, count(json_decode($response->body(), true)['results']), 'The action returns one result per edited member.');
learningSame(400, $nameAction->handle($nameRequest('{invalid'))->status(), 'Malformed JSON is rejected.');
learningSame(403, $nameAction->handle($nameRequest(str_repeat(' ', 131073)))->status(), 'Oversized payloads are rejected.');
$nameSecurity->valid = false;
learningSame(403, $nameAction->handle($nameRequest('[]'))->status(), 'Invalid nonce must be rejected.');
$nameSecurity->valid = true;
$nameAccount->active = false;
learningSame(403, $nameAction->handle($nameRequest('[]'))->status(), 'Inactive accounts must be rejected.');
$nameAccount->active = true;
$nameAccount->actor = 2;
learningSame(403, $nameAction->handle($nameRequest(json_encode([$change(4, '금지')], JSON_THROW_ON_ERROR)))->status(), 'An ordinary active account cannot call the management action.');
$nameAccount->actor = 3;

$nameActions = new class implements ActionUrlProvider {
    public function actionUrl(): string { return '/wp-admin/admin-post.php'; }
    public function ajaxUrl(): string { return '/wp-admin/admin-ajax.php'; }
};
$nameHtml = new GroupHtml(new JudgeResultCatalog());
$reflection = new ReflectionClass(GroupMemberPageHandler::class);
$page = $reflection->newInstanceWithoutConstructor();
foreach (['users' => $nameUsers, 'groups' => $nameGroups, 'memberNames' => $nameService, 'actionUrls' => $nameActions, 'security' => $nameSecurity, 'html' => $nameHtml] as $key => $value) {
    $reflection->getProperty($key)->setValue($page, $value);
}
$membersHtml = $reflection->getMethod('members')->invoke($page, $nameGroup);
learningAssert(str_contains($membersHtml, '>표시 이름</th>') && str_contains($membersHtml, '>관리용 이름</th>'), 'Management tables distinguish the two names.');
learningSame(2, substr_count($membersHtml, ' data-ojp-managed-name '), 'Only the two editable members receive text fields.');
learningAssert(str_contains($membersHtml, 'oj_group_save_member_names') && str_contains($membersHtml, '관리용 이름 일괄 저장'), 'The table has one shared batch-save action.');
learningAssert(str_contains($membersHtml, 'group-reset-passwords') && str_contains($membersHtml, 'oj_group_remove_membership'), 'Existing membership and password actions remain available.');
$publicHtml = $nameHtml->memberTable($nameUsers->findMany([2, 4]), [], 1, [], true);
learningAssert(! str_contains($publicHtml, '김하늘') && ! str_contains($publicHtml, '최민준') && ! str_contains($publicHtml, '관리용'), 'Ordinary group members receive public names only.');
foreach (['approvals', 'managers'] as $screen) {
    $html = $reflection->getMethod($screen)->invoke($page, $nameGroup);
    learningAssert(str_contains($html, '<th>표시 이름</th><th>관리용 이름</th>'), 'Other management tables use consistent name labels.');
}
printf("Group member names: %d checks passed.\n", $learningAssertions - $startAssertions);
if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    $css = file_get_contents($pluginRoot . '/resources/ui/css/oj-ui-system.css');
    $js = file_get_contents($pluginRoot . '/resources/ui/js/oj-ui.js');
    file_put_contents($argv[2] . '/group-members.html', '<!doctype html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' . $css . '</style><title>그룹 구성원</title></head><body class="ojp-app"><main class="ojp-main"><div class="ojp-shell"><header class="ojp-page-header"><h1>그룹 구성원</h1></header>' . $membersHtml . '</div></main><script>' . $js . '</script></body></html>');
}
