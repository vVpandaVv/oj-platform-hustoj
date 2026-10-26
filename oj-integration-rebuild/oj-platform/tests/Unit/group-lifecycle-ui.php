<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Groups\Application\GroupService;
use CodingPanda\OjPlatform\Modules\Groups\Domain\LearningGroup;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\GroupHtml;
use CodingPanda\OjPlatform\Modules\Groups\Presentation\Console\GroupManagementPageHandler;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;

require __DIR__ . '/learning-spaces.php';
$lifecycleStart = $learningAssertions;
$lifecycleRepository = new LearningGroups();
$lifecycleUsers = new LearningUsers();
$lifecycleService = new GroupService($lifecycleRepository, new LearningAuthorization(), $lifecycleUsers, new LearningProvisioner($lifecycleUsers), new LearningPasswordResetter(), new LearningSubmissions());
$lifecycleHtml = new GroupHtml(new JudgeResultCatalog());
$lifecycleReflection = new ReflectionClass(GroupManagementPageHandler::class);
$lifecyclePage = $lifecycleReflection->newInstanceWithoutConstructor();
foreach (['groups' => $lifecycleService, 'html' => $lifecycleHtml] as $key => $value) {
    $lifecycleReflection->getProperty($key)->setValue($lifecyclePage, $value);
}
$lifecycleReflection->getProperty('actionUrls')->setValue($lifecyclePage, new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider {
    public function actionUrl(): string { return '/wp-admin/admin-post.php'; }
    public function ajaxUrl(): string { return '/wp-admin/admin-ajax.php'; }
});
$lifecycleReflection->getProperty('security')->setValue($lifecyclePage, new class implements \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity {
    public function hiddenFields(string $key): string { return ''; }
    public function verify(string $key, \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest $request): bool { return true; }
});
foreach ([false, true] as $archived) {
    $group = new LearningGroup(17, 'Group', 'group', '', 'private', 'manual', true, null, 'normal', null, 2, $archived ? '2026-10-14 00:00:00' : null);
    $lifecycleRepository->groups[17] = $group;
    $lifecycleRepository->managers[17] = [3];
    foreach ([2, 1, 3] as $actor) {
        $table = $lifecycleHtml->groupTable([$group], true, fn (LearningGroup $item): bool => $lifecycleService->canArchive($actor, $item));
        $edit = $lifecycleReflection->getMethod('editForm')->invoke($lifecyclePage, $group, $actor);
        foreach ([$table, $edit] as $body) {
            learningSame($actor !== 3, str_contains($body, 'href="/oj-console/groups/17/delete">' . ($archived ? '복원' : '보관') . '</a>'), 'Lifecycle links respect owner/global-admin boundaries and archived state.');
        }
    }
    learningAssert(! str_contains($lifecycleHtml->groupTable([$group], false, static fn (): bool => true), '/delete'), 'Public group lists never expose lifecycle controls.');
}
learningAssert(str_contains($lifecycleHtml->groupTable([], true, static fn (): bool => true), 'colspan="6"'), 'Empty group management tables retain the actions column.');
printf("Group lifecycle UI: %d checks passed.\n", $learningAssertions - $lifecycleStart);
