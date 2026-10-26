<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemDataFile;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTestDataInspection;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Console\ProblemTestDataPageHandler;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

require_once dirname(__DIR__, 2) . '/src/Kernel/Autoloader.php';
Autoloader::register(dirname(__DIR__, 2));

// Exercise production fragment renderers without connecting to Judge DB or mutating test files.
$reflection = new ReflectionClass(ProblemTestDataPageHandler::class);
$handler = $reflection->newInstanceWithoutConstructor();
$htmlReflection = new ReflectionClass(ProblemHtml::class);
$html = $htmlReflection->newInstanceWithoutConstructor();
$htmlReflection->getProperty('dates')->setValue($html, new SiteDateFormatter());
$reflection->getProperty('html')->setValue($handler, $html);
$reflection->getProperty('actionUrls')->setValue($handler, new class implements ActionUrlProvider {
    public function actionUrl(): string { return '/action'; }
    public function ajaxUrl(): string { return '/ajax'; }
});
$reflection->getProperty('security')->setValue($handler, new class implements ActionSecurity {
    public function hiddenFields(string $action): string { return ''; }
    public function verify(string $action, HttpRequest $request): bool { return false; }
});
$id = new ProblemId(1001);
$files = array_map(static fn (string $name): ProblemDataFile =>
    new ProblemDataFile($name, 14000, str_repeat('a', 64), '2026-09-11 00:00:00'),
    ['01.in', '01.out', 'spj', 'spj.c', 'spj-build.log', 'cases.zip']
);
$problem = new JudgeProblem($id, '두 수의 합', '', '', '', '', '', '', '', 1, 128, true, ProblemVisibility::Published, 0, 0, '', '', '', '', null);
$render = static fn (string $name, array $args): string => $reflection->getMethod($name)->invokeArgs($handler, $args);
echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>테스트데이터 화면 회귀 검사</title></head><body class="ojp-console-shell">'
    . '<header class="ojp-console-header"><div class="ojp-console-header__inner">관리자 콘솔</div></header>'
    . '<div class="ojp-console-layout"><aside class="ojp-console-sidebar">문제</aside><main class="ojp-console-main">'
    . '<header class="ojp-page-header"><div><h1>테스트데이터 편집기</h1><p>두 수의 합</p></div></header>'
    . '<div class="ojp-file-workbench"><section class="ojp-stack">'
    . $render('fileCommands', [$id])
    . $render('fileTable', [$id, $files, ProblemTestDataInspection::inspect($files)])
    . '</section><div class="ojp-file-side">'
    . $render('editor', [$id, null, false, 1])
    . $render('spjPanel', [$id, $problem, $files])
    . '</div></div></main></div></body></html>';
