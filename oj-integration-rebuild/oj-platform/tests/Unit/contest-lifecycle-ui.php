<?php

declare(strict_types=1);

require __DIR__ . '/class-management.php';
$before = $learningAccessAssertions;
$judge = new LearningAccessContestJudge();
$platform = new LearningAccessContestPlatform();
$clock = new LearningAccessClock();
$service = new CodingPanda\OjPlatform\Modules\Contests\Application\ContestService(
    $judge, $platform,
    new CodingPanda\OjPlatform\Modules\Contests\Application\ContestStaffPolicy($platform, new LearningAccessAuthorization()),
    $classUsers, new LearningAccessProblems(), new LearningAccessSubmissions(),
    new CodingPanda\OjPlatform\Modules\Contests\Application\ContestRankingCalculator(),
    new CodingPanda\OjPlatform\Modules\Contests\Application\ContestStatisticsCalculator(),
    $clock, $classGroups
);
$html = new CodingPanda\OjPlatform\Modules\Contests\Presentation\ContestHtml(new CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog(), $clock);
$handler = new CodingPanda\OjPlatform\Modules\Contests\Presentation\Console\ContestManagementPageHandler($classPages, $classAccounts, $service, $classActions, $classSecurity, $html, $classGroups, $clock, new CodingPanda\OjPlatform\Ui\Component\PaginationView());
$route = static fn (string $screen, array $params = []) => new CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch(CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition::fromArray(['name' => 'console.contests.' . $screen, 'owner_module' => 'contests', 'feature_key' => 'classes', 'path' => '/oj-console/contests', 'area' => 'console', 'methods' => ['GET'], 'authentication' => 'authenticated', 'response_type' => 'html']), $params);
$request = new CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest('GET', '/oj-console/contests');
$classAccounts->actor = 1;
$body = $handler->handle($route('index'), $request)->body();
learningAccessAssert(str_contains($body, '/contests/102/delete">보관</a>'), 'Ended contests remain available for explicit archiving.');
learningAccessAssert(! str_contains($handler->handle($route('deleted'), $request)->body(), '/contests/102/delete'), 'Ending alone does not archive a contest.');
$service->setArchived(10, 102, true);
learningAccessAssert(! str_contains($handler->handle($route('index'), $request)->body(), '/contests/102/delete'), 'Archived contests leave the active management list.');
$body = $handler->handle($route('deleted'), $request)->body();
learningAccessAssert(str_contains($body, '/contests/102/delete">복원</a>') && ! str_contains($body, '/contests/100/delete'), 'The archive list separates archived contests and offers restoration.');
learningAccessAssert(str_contains($body, '/contests/102/submissions'), 'Archived contest submissions remain reachable.');
$classAccounts->actor = 20;
learningAccessAssert(! str_contains($handler->handle($route('deleted'), $request)->body(), '/contests/102/delete'), 'Co-managers cannot restore contests they do not own.');
learningAccessAssert($handler->handle($route('delete', ['contest_id' => '102']), $request)->status() === 403, 'Direct lifecycle URLs enforce owner privileges.');
learningAccessAssert(! str_contains($html->contestTable([$judge->items[102]], false, [], [102]), '/delete'), 'Public lists do not expose lifecycle controls.');
$classAccounts->actor = 1;
$service->setArchived(10, 102, false);
learningAccessAssert(str_contains($handler->handle($route('index'), $request)->body(), '/contests/102/delete">보관</a>'), 'Restoration returns the contest to the ordinary list.');
echo 'Contest lifecycle UI: ' . ($learningAccessAssertions - $before) . " checks passed.\n";
