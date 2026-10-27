<?php

declare(strict_types=1);

require __DIR__ . '/../Support/render-quiz-editor.php';
$quizAssertions = 0;
foreach ($quizEditorFixtures as $name => $html) {
    preg_match_all('/<\/?form\b[^>]*>/i', $html, $matches);
    $depth = 0;
    foreach ($matches[0] as $tag) {
        $depth += str_starts_with(strtolower($tag), '</form') ? -1 : 1;
        quizAssert($depth >= 0 && $depth <= 1, 'Forms must not be nested or prematurely closed: ' . $name);
    }
    quizSame(0, $depth, 'Every action form must be closed.');
    $document = new DOMDocument();
    $document->loadHTML('<meta charset="utf-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $editor = $xpath->query('//form[@data-oj-quiz-editor]')->item(0);
    quizAssert($editor instanceof DOMElement, 'The main editor form must exist.');
    quizSame(1, $xpath->query('.//input[@name="action"]', $editor)->length, 'Only the editor action belongs inside the main form.');
    quizSame(1, $xpath->query('.//input[@name="_oj_nonce"]', $editor)->length, 'Auxiliary action nonces must not pollute draft saves.');
    if ($name !== 'bank') {
        quizSame(1, $xpath->query('.//button[@value="save_draft"]', $editor)->length, 'The draft button must remain in the main form.');
        quizSame(1, $xpath->query('//form[@id="ojp-quiz-export-form"]/input[@name="action" and @value="oj_quiz_export_to_bank"]')->length, 'Export has a separate action form.');
    }
    if (in_array($name, ['new_with_bank', 'edit'], true)) {
        quizSame(1, $xpath->query('//select[@name="bank_id" and @form="ojp-quiz-import-form"]')->length, 'The bank picker belongs to the separate import form.');
        quizSame(1, $xpath->query('//form[@id="ojp-quiz-import-form"]/input[@name="action" and @value="oj_quiz_import_bank_question"]')->length, 'Import has its own authenticated action.');
    }
}
fwrite(STDOUT, "Quiz editor forms: $quizAssertions checks passed.\n");
