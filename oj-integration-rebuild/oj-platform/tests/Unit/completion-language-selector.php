<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\Problems\Application\CodeCompletionTemplatePolicy;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;

require __DIR__ . '/problems.php';
$assertions = 0;
$options = $judgeLanguages->keyedLanguages();
problemsSame(['c', 'cpp', 'java', 'python', 'php', 'csharp', 'javascript', 'go'], array_keys($options), 'Offer one canonical key per supported language.');
problemsSame(8, count(array_unique(array_values($options))), 'Aliases must not create duplicate language choices.');
$completionLanguageFixtures = [];
$readSelect = static function (string $html): array {
    $document = new DOMDocument();
    $document->loadHTML('<meta charset="utf-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $select = $xpath->query('//select[@name="completion_language_key"]')->item(0);
    problemsAssert($select instanceof DOMElement, 'The language field must be a select.');
    problemsSame(0, $xpath->query('//input[@name="completion_language_key"]')->length, 'Do not keep a free-text language key input.');
    $selected = $xpath->query('.//option[@selected]', $select)->item(0);
    return [$select, $selected, $xpath];
};
$newHtml = $problemEditForm->render(null, null, 7001);
[$select, $selected] = $readSelect($newHtml);
problemsSame('', $selected?->getAttribute('value'), 'New problems must explicitly choose a language.');
problemsSame('빈칸 문제 언어', $select->parentNode->firstChild->textContent, 'Use a user-facing field label, not an internal key label.');
problemsSame(9, $select->getElementsByTagName('option')->length, 'Include a placeholder and exactly eight supported languages.');
$completionLanguageFixtures['new'] = ['html' => $newHtml, 'key' => ''];

foreach ([...array_keys($options), 'python3', 'c++', 'js', 'PYTHON3', 'unsupported'] as $key) {
    $id = new ProblemId(7001);
    $mask = $judgeLanguages->disabledMaskForEnabled([$key === 'unsupported' ? 6 : $judgeLanguages->idForKey($key)]);
    $problem = problemFromDraft($id, problemDraft(languageMask: $mask));
    $schema = json_encode(['language_key' => $key, 'blanks' => [['id' => 'answer', 'label' => '정답', 'kind' => 'inline', 'max_bytes' => 100]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $prepared = (new CodeCompletionTemplatePolicy())->prepare($problem->starterCode(), $schema, $mask);
    $meta = new ProblemMetadata($id, null, [], CodeEntryMode::CodeCompletion, $prepared['schema'], $prepared['hash'], 'manual', '', 1);
    $html = $problemEditForm->render($problem, $meta, 7001);
    [$select, $selected, $xpath] = $readSelect($html);
    problemsSame($key === 'unsupported' ? '' : $key, $selected?->getAttribute('value'), 'Preserve supported stored aliases; never silently substitute an unsupported key.');
    problemsSame($prepared['schema'], $xpath->query('//input[@name="code_completion_schema"]')->item(0)->getAttribute('value'), 'Rendering must not mutate the stored schema.');
    problemsSame(9, $select->getElementsByTagName('option')->length, 'A saved alias must replace its canonical option, not duplicate the language.');
    if ($key !== 'unsupported') {
        problemsSame($judgeLanguages->label($judgeLanguages->idForKey($key)), $selected?->textContent, 'Show the language name for canonical and legacy keys.');
    } else {
        problemsAssert(str_contains($selected?->textContent ?? '', '다시 선택'), 'Unsupported saved values must ask for an explicit choice.');
    }
    $completionLanguageFixtures[$key] = ['html' => $html, 'key' => $key, 'schema' => $prepared['schema']];
}
fwrite(STDOUT, "Completion language selector: $assertions checks passed.\n");
if (getenv('OJ_COMPLETION_LANGUAGE_FIXTURE') === '1') {
    fwrite(STDOUT, 'COMPLETION_LANGUAGE_FIXTURES=' . json_encode($completionLanguageFixtures, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}
