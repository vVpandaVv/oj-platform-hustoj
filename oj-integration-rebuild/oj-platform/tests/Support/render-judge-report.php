<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeResultCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\JudgeExecutionParser;
use CodingPanda\OjPlatform\Modules\Submissions\Application\RuntimeErrorParser;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\JudgeExecutionHtml;

require_once dirname(__DIR__, 2) . '/src/Kernel/Autoloader.php';
Autoloader::register(dirname(__DIR__, 2));
$case = in_array($argv[1] ?? '', ['wrong-answer', 'whitespace-answer'], true) ? $argv[1] : 'runtime-error';
$renderer = new JudgeExecutionHtml(new JudgeExecutionParser(new RuntimeErrorParser()), new JudgeResultCatalog());
$raw = file_get_contents(dirname(__DIR__) . '/Fixtures/judge-' . $case . '.txt');
echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>채점 결과 회귀 검사</title>'
    . '</head><body class="ojp-public-shell"><main class="ojp-public-main">'
    . '<header class="ojp-page-header"><div><h1>문제 1001 채점 결과</h1></div></header>'
    . $renderer->render($raw) . '</main></body></html>';
