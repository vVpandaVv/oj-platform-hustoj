<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccount;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassRepository;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassStaffPolicy;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassProblem;
use CodingPanda\OjPlatform\Modules\Classes\Domain\ClassReport;
use CodingPanda\OjPlatform\Modules\Classes\Domain\LearningClass;
use CodingPanda\OjPlatform\Modules\Classes\Infrastructure\WordPress\WordPressClassRepository;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassHtml;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassRequestFactory;
use CodingPanda\OjPlatform\Modules\Classes\Presentation\Console\ClassManagementPageHandler;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\Permission\Contract\PermissionContext;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionProjection;
use CodingPanda\OjPlatform\Shared\Selection\ChoiceProvider;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;
use CodingPanda\OjPlatform\Ui\Icon\IconRenderer;

require __DIR__ . '/learning-access.php';
$startChecks = $learningAccessAssertions;
$classRepo = new LearningAccessClasses();
$classRepo->items[1] = new LearningClass(1, '알고리즘 기초', 'original', '수업 설명', 'password', 'participants_live', true, '2026-01-01 00:00:00', '2026-06-01 00:00:00', 'secret-hash', 'standard', 10, null);
$classRepo->groups[1] = [7, 8];
$classRepo->problemItems[1] = [new ClassProblem(1001, 0, false, '2026-01-02 00:00:00', '2026-02-01 00:00:00'), new ClassProblem(1000, 1, true, null, null)];
$classPermissions = new class implements Authorization {
    public array $creators = [1, 10, 20, 99];
    public function can(int $userId, string $capability, ?PermissionContext $context = null): bool { return $userId === 1 || ($capability === 'oj_create_classes' && in_array($userId, $this->creators, true)); }
    public function canAny(int $userId, array $capabilities): bool { foreach ($capabilities as $capability) { if ($this->can($userId, $capability)) return true; } return false; }
    public function isSuperAdmin(int $userId): bool { return $userId === 1; }
};
$classGroups = new class implements ChoiceProvider {
    public bool $available = true;
    public array $choices = [7 => '알고리즘 학습 그룹', 9 => '프로그래밍 입문 그룹'];
    public array $included = [];
    public function isAvailable(): bool { return $this->available; }
    public function choicesFor(int $userId, array $includedIds = []): array { $this->included = $includedIds; return $this->choices; }
};
$classUsers = new LearningAccessUsers();
$classProblems = new LearningAccessProblems();
$classClock = new LearningAccessClock();
$makeService = static fn (ClassRepository $repo): ClassService => new ClassService($repo, new ClassStaffPolicy($repo, $classPermissions), $classUsers, $classProblems, new LearningAccessSubmissions(), $classGroups, $classClock);
$classService = $makeService($classRepo);
$originalState = serialize($classRepo);
$draft = $classService->copyDraft(10, 1);
learningAccessAssert($draft->classId() === 0 && $draft->slug() === '' && $draft->name() === '알고리즘 기초 (복사본)', 'A copy must be a new class with a new address.');
learningAccessAssert($draft->description() === '수업 설명' && $draft->blocklyProfileKey() === 'standard', 'Preserve the description and Blockly configuration.');
learningAccessAssert($draft->visibility() === 'private' && $draft->progressVisibility() === 'private' && ! $draft->isEnabled(), 'Copies must not immediately publish another class.');
learningAccessAssert($draft->startsAt() === null && $draft->endsAt() === null && $draft->password() === null && $draft->instructorUserIds() === [], 'Do not copy old dates, credentials or staff grants.');
learningAccessAssert($draft->groupIds() === [7] && $classGroups->included === [], 'Only groups selectable for a new class can be copied.');
learningAccessAssert($draft->problemIds() === [1001, 1000], 'Keep the original problem order.');
learningAccessAssert(! $draft->problemConfigurations()[1001]->isVisible() && $draft->problemConfigurations()[1000]->isVisible(), 'Keep problem visibility.');
learningAccessAssert($draft->problemConfigurations()[1001]->availableFrom() === null && $draft->problemConfigurations()[1001]->availableUntil() === null, 'Reset expired per-problem windows.');
learningAccessAssert(serialize($classRepo) === $originalState, 'Opening a copy must not write data or modify the source.');
learningAccessAssert($classService->copyDraft(20, 1)->classId() === 0, 'A co-instructor with create permission can prepare a copy.');
learningAccessThrows(fn () => $classService->copyDraft(99, 1), 'Create permission must not reveal another owner\'s class.');
learningAccessThrows(fn () => $classService->copyDraft(30, 1), 'An ordinary participant cannot copy.');
$classPermissions->creators = [1];
learningAccessThrows(fn () => $classService->copyDraft(10, 1), 'Ownership alone is not create permission.');
$classPermissions->creators = [1, 10, 20, 99];
learningAccessThrows(fn () => $classService->copyDraft(1, 999), 'Missing source classes are rejected.');
$classGroups->available = false;
learningAccessAssert($classService->copyDraft(10, 1)->groupIds() === [], 'Disabled Groups must not break class copies.');
$classGroups->available = true;

$classAccounts = new class($classUsers) implements CurrentAccountProvider {
    public int $actor = 10;
    public function __construct(private LearningAccessUsers $users) {}
    public function current(): CurrentAccount { return new CurrentAccount($this->users->findById($this->actor), null); }
};
$classPages = new class implements ConsoleResponder {
    public function respond(string $title, string $content, string $currentMenu, HttpRequest $request, int $status = 200): HttpResponse { return HttpResponse::html($content, $status); }
};
$classActions = new class implements ActionUrlProvider {
    public function actionUrl(): string { return '/wp-admin/admin-post.php'; }
    public function ajaxUrl(): string { return '/wp-admin/admin-ajax.php'; }
};
$classSecurity = new class implements ActionSecurity {
    public function hiddenFields(string $key): string { return '<input type="hidden" name="action" value="' . $key . '"><input type="hidden" name="_nonce" value="fixture">'; }
    public function verify(string $key, HttpRequest $request): bool { return $request->bodyString('_nonce') === 'fixture'; }
};
$classHtml = new ClassHtml(new JudgeResultCatalog(), icons: new IconRenderer('/assets/lucide.svg'));
$classPage = new ClassManagementPageHandler($classPages, $classAccounts, $classService, $classActions, $classSecurity, $classHtml, $classGroups, new PaginationView());
$classRoute = static fn (string $screen, array $params = []): RouteMatch => new RouteMatch(RouteDefinition::fromArray(['name' => 'console.classes.' . $screen, 'owner_module' => 'classes', 'feature_key' => 'classes', 'path' => '/oj-console/classes', 'area' => 'console', 'methods' => ['GET'], 'authentication' => 'authenticated', 'response_type' => 'html']), $params);
$copyRequest = new HttpRequest('GET', '/oj-console/classes/new', ['copy_from' => '1']);
$classScreens = [
    'new' => $classPage->handle($classRoute('new'), new HttpRequest('GET', '/oj-console/classes/new'))->body(),
    'edit' => $classPage->handle($classRoute('edit', ['class_id' => '1']), new HttpRequest('GET', '/oj-console/classes/1/edit'))->body(),
    'copy' => $classPage->handle($classRoute('new'), $copyRequest)->body(),
    'index' => $classPage->handle($classRoute('index'), new HttpRequest('GET', '/oj-console/classes'))->body(),
];
learningAccessAssert(str_contains($classScreens['index'], 'copy_from=1') && str_contains($classScreens['edit'], 'copy_from=1'), 'Both the class list and edit screen offer copying.');
foreach ([10, 1, 20] as $actor) {
    $classAccounts->actor = $actor;
    foreach (['index', 'edit'] as $screen) {
        $body = $classPage->handle($classRoute($screen, ['class_id' => '1']), new HttpRequest('GET', '/oj-console/classes'))->body();
        learningAccessAssert(str_contains($body, 'href="/oj-console/classes/1/delete">보관</a>') === ($actor !== 20), 'Only owners and global admins receive archive links, not co-instructors.');
        learningAccessAssert(str_contains($body, 'copy_from=1'), 'Lifecycle restrictions preserve instructor copy controls.');
    }
}
$classPermissions->creators = [1];
$classAccounts->actor = 10;
learningAccessAssert(str_contains($classPage->handle($classRoute('index'), new HttpRequest('GET', '/oj-console/classes'))->body(), '/classes/1/delete'), 'Owners without creation permission still receive a management column.');
$classPermissions->creators = [1, 10, 20, 99];
$activeClass = $classRepo->items[1];
$classRepo->items[1] = new LearningClass(1, '보관 수업', 'archived', '', 'private', 'private', false, null, null, null, null, 10, '2026-10-14 00:00:00');
foreach (['deleted', 'edit'] as $screen) {
    $body = $classPage->handle($classRoute($screen, ['class_id' => '1']), new HttpRequest('GET', '/oj-console/classes'))->body();
    learningAccessAssert(str_contains($body, 'href="/oj-console/classes/1/delete">복원</a>'), 'Archived lists and edit screens link to restore confirmation.');
}
learningAccessAssert(! str_contains($classHtml->classTable([$classRepo->items[1]], false, true, static fn (): bool => true), '/delete'), 'Public class lists never expose lifecycle controls.');
learningAccessAssert(str_contains($classHtml->classTable([], true, false, static fn (): bool => true), 'colspan="7"'), 'Empty managed lists include the lifecycle column.');
$classRepo->items[1] = $activeClass;
$document = new DOMDocument();
$previousErrors = libxml_use_internal_errors(true);
$document->loadHTML('<?xml encoding="UTF-8">' . $classScreens['copy']);
libxml_clear_errors();
libxml_use_internal_errors($previousErrors);
$xpath = new DOMXPath($document);
learningAccessAssert($xpath->query('//input[@name="class_id" and @value="0"]')->length === 1, 'The copy form must post a new ID.');
learningAccessAssert($xpath->query('//input[@name="enabled" and @checked]')->length === 0, 'A copied class starts stopped.');
learningAccessAssert($xpath->query('//select[@name="group_ids[]" and @multiple and @size="8"]')->length === 1, 'Use the shared multiline selector.');
$classAccounts->actor = 30;
learningAccessAssert($classPage->handle($classRoute('new'), $copyRequest)->status() === 403, 'Direct copy URLs enforce source access and creation permission.');
$classAccounts->actor = 10;

// Submit the rendered copy through the same request factory and transactional repository as a new class.
$fields = [];
foreach ($xpath->query('//form//input | //form//textarea | //form//select') as $node) {
    $name = $node->getAttribute('name');
    if ($name === '' || ($node->getAttribute('type') === 'checkbox' && ! $node->hasAttribute('checked'))) continue;
    $values = $node->tagName === 'select'
        ? array_map(static fn (DOMNode $option): string => $option->getAttribute('value'), iterator_to_array($xpath->query('./option[@selected]', $node)))
        : [$node->tagName === 'textarea' ? $node->textContent : $node->getAttribute('value')];
    foreach ($values as $value) $fields[] = urlencode($name) . '=' . urlencode($value);
}
parse_str(implode('&', $fields), $copyPost);
$copyPost['slug'] = 'copied-class';
$factory = new ClassRequestFactory($classUsers);
$copyCommand = $factory->saveCommand(new HttpRequest('POST', '/wp-admin/admin-post.php', [], $copyPost));
learningAccessAssert($copyCommand->problemIds() === [1001, 1000] && ! $copyCommand->problemConfigurations()[1001]->isVisible(), 'Posting the copy preserves problem order and hidden state.');
$classDatabase = new class implements ApplicationDatabase {
    public array $writes = [];
    public array $transactions = [];
    public bool $fail = false;
    public function row(string $sql, array $values = []): ?array { return null; }
    public function rows(string $sql, array $values = []): array { return []; }
    public function value(string $sql, array $values = []): mixed { return null; }
    public function insert(string $table, array $data, array $formats = []): int { if ($this->fail && $table === 'class_problem_links') throw new RuntimeException('Write failed'); $this->writes[] = ['insert', $table, $data]; return $table === 'classes' ? 101 : 200 + count($this->writes); }
    public function update(string $table, array $data, array $where, array $formats = [], array $whereFormats = []): int { $this->writes[] = ['update', $table, $where]; return 1; }
    public function delete(string $table, array $where, array $whereFormats = []): int { $this->writes[] = ['delete', $table, $where]; return 1; }
    public function beginTransaction(): void { $this->transactions[] = 'begin'; }
    public function commit(): void { $this->transactions[] = 'commit'; }
    public function rollBack(): void { $this->transactions[] = 'rollback'; }
};
$classProblemChanges = new class($classDatabase) implements \CodingPanda\OjPlatform\Modules\Classes\Contract\ClassProblemChangePublisher {
    public array $events = [];
    public function __construct(private ApplicationDatabase $database) {}
    public function saved(int $classId, int $actorUserId, array $problemIds): void
    {
        $this->events[] = [$classId, $actorUserId, $problemIds, end($this->database->transactions)];
    }
};
$copyRepository = new WordPressClassRepository($classDatabase, $classClock);
$copySaveService = new ClassService($copyRepository, new ClassStaffPolicy($copyRepository, $classPermissions), $classUsers, $classProblems, new LearningAccessSubmissions(), $classGroups, $classClock, $classProblemChanges);
learningAccessAssert($copySaveService->save(10, $copyCommand) === 101, 'Saving the copy creates a different class ID.');
learningAccessAssert($classDatabase->transactions === ['begin', 'commit'], 'A copy uses the existing aggregate transaction.');
learningAccessAssert($classProblemChanges->events === [[101, 10, [1001, 1000], 'commit']], 'Class problem notifications publish only after the complete association transaction commits.');
learningAccessAssert($classDatabase->writes[0][2]['owner_user_id'] === 10 && $classDatabase->writes[0][2]['password_hash'] === null, 'The saving user owns the copy with no inherited password.');
foreach (array_slice($classDatabase->writes, 1) as [$operation, $table, $data]) {
    learningAccessAssert(in_array($table, ['class_instructors', 'class_group_links', 'class_problem_links'], true) && $data['class_id'] === 101, 'Only links for the new class may be written; no enrollments or submissions.');
}
$classDatabase->fail = true;
try { $copySaveService->save(10, $copyCommand); throw new LogicException('Expected failure'); } catch (RuntimeException $error) { learningAccessAssert($error->getMessage() === 'Write failed' && end($classDatabase->transactions) === 'rollback', 'A failed link write rolls the whole copy back.'); }
learningAccessAssert(count($classProblemChanges->events) === 1, 'Rolled-back associations must not trigger automatic activity generation.');
$classDatabase->fail = false;
$classGroups->choices = [9 => '다른 그룹'];
learningAccessThrows(fn () => $copySaveService->save(10, $copyCommand), 'Group permissions must be checked again on save.');
$classGroups->choices = [7 => '알고리즘 학습 그룹', 9 => '프로그래밍 입문 그룹'];
$classProblems->missingIds = [1001];
learningAccessThrows(fn () => $copySaveService->save(10, $copyCommand), 'Unavailable problems must be checked again on save.');
$classProblems->missingIds = [];

$progressProblems = array_map(static fn (int $id): SubmissionProblem => new SubmissionProblem($id, '문제 ' . $id), range(1000, 1019));
$progressPeople = [new UserIdentity(30, 'participant', 'student@example.test', '김하늘'), new UserIdentity(60, 'group-member', 'member@example.test', '이민준')];
$projection = static fn (int $id, int $problem, int $result, bool $valid = true, string $at = '2026-09-18 09:00:00'): SubmissionProjection => new SubmissionProjection($id, $problem, 'participant', $result, 6, 10, 1024, $at, $valid);
$progressRows = [$projection(1, 1000, 4), $projection(2, 1000, 4), $projection(3, 1001, 6), $projection(4, 1002, 4, false), $projection(5, 1003, 4, true, '2026-01-01 00:00:00')];
$progressReport = new ClassReport($classRepo->items[1], $progressPeople, $progressProblems, $progressRows, [30 => '2026-09-01 00:00:00']);
$progressHtml = $classHtml->reportTable($progressReport, 'progress-desc', '/oj-console/classes/1/progress');
learningAccessAssert(str_contains($progressHtml, '푼 문제 수') && str_contains($progressHtml, '<strong>1 / 20</strong>') && str_contains($progressHtml, '<strong>0 / 20</strong>'), 'Count distinct accepted problems, excluding invalid and pre-approval submissions.');
learningAccessAssert(strpos($progressHtml, '푼 문제 수') < strpos($progressHtml, '>1000</a>'), 'Place solved counts beside names before problem columns.');
learningAccessAssert(str_contains($progressHtml, 'ojp-table--matrix') && str_contains($progressHtml, 'tabindex="0"') && str_contains($progressHtml, 'role="region"'), 'The matrix provides its own keyboard-accessible scroll region.');
learningAccessAssert(substr_count($progressHtml, '>첫 해결</span>') === 1, 'Only one credited problem is marked first solved.');
$ascending = $classHtml->reportTable($progressReport, 'progress-asc');
learningAccessAssert(strpos($ascending, '이민준') < strpos($ascending, '>김하늘</a>'), 'Solved-count sorting still works.');
$empty = $classHtml->reportTable(new ClassReport($classRepo->items[1], [], [], []));
learningAccessAssert(str_contains($empty, 'colspan="2"'), 'Empty matrices retain the two identity/summary columns.');
learningAccessAssert(! str_contains($classHtml->classTable([$classRepo->items[1]], false, true), 'copy_from'), 'Public lists never expose management copy controls.');

printf("Class management: %d checks passed.\n", $learningAccessAssertions - $startChecks);
if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    for ($index = 1; $index <= 35; ++$index) {
        $progressPeople[] = new UserIdentity(100 + $index, 'learner' . $index, 'learner' . $index . '@example.test', '학습자 ' . $index);
    }
    $progressHtml = $classHtml->reportTable(new ClassReport($classRepo->items[1], $progressPeople, $progressProblems, $progressRows, [30 => '2026-09-01 00:00:00']), 'progress-desc', '/oj-console/classes/1/progress');
    $classScreens['progress'] = '<header class="ojp-page-header"><h1>수업 학습 현황</h1></header>' . $progressHtml;
    require __DIR__ . '/console-shell.php';
    foreach ($classScreens as $name => $content) {
        file_put_contents($argv[2] . '/class-' . $name . '.html', $console->render('수업 관리', $content, 'classes.index', new UserIdentity(10, 'owner', 'owner@example.test', '관리자'), $menu));
    }
}
