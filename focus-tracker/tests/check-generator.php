<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Checks/Verified_Question_Generator.php';

use FocusTracker\Checks\Verified_Question_Generator;

$generator = new Verified_Question_Generator();
$checks = 0;
function check_same(mixed $expected, mixed $actual, string $label): void {
    global $checks;
    ++$checks;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function draft_questions(string $source, string $language = 'python'): array {
    global $generator;
    return $generator->generate_candidates(array(), array('source' => $source, 'language' => $language));
}
function correct_label(array $question): string {
    foreach ($question['choices'] as $choice) {
        if ($choice['id'] === $question['correctAnswer']) {
            return $choice['label'];
        }
    }
    throw new RuntimeException('Missing answer option');
}

check_same(null, $generator->generate(array()), 'no invented question without evidence');
check_same(array(), draft_questions('if x > 1:', 'unknown'), 'unknown language fails closed');
check_same(array(), draft_questions('if x > 1:', 'javascript'), 'JavaScript coercion is not claimed supported');
check_same(array(), $generator->generate_candidates(array('submission' => array('source' => 'if x > 1:', 'language' => 6))), 'numeric language identifiers must be normalised by integration');

$draft = array('source' => "score = 72\nif score >= 60:\n    print('pass')", 'language' => 'python3');
$context = array(
    'problem' => array('id' => 7, 'sample_input' => "1 2\n", 'sample_output' => "3\n"),
    'submission' => array('id' => 23, 'source' => 'int main() { if (count < 4) return 0; }', 'language' => 'c++17'),
);
$questions = $generator->generate_candidates($context, $draft);
check_same(array('draft', 'submission', 'problem'), array_column($questions, 'sourceKind'), 'all three authorised sources contribute');
check_same($questions, $generator->generate_candidates($context, $draft), 'stable generation permits deduplication');
foreach ($questions as $question) {
    check_same(true, $generator->verify($question), 'independent displayed answer verification');
    check_same(true, $generator->verify($question, $context, $draft), 'source-bound verification');
    $tampered = $question;
    $tampered['correctAnswer'] = $question['choices'][0]['id'] === $question['correctAnswer'] ? $question['choices'][1]['id'] : $question['choices'][0]['id'];
    check_same(false, $generator->verify($tampered, $context, $draft), 'wrong answer cannot pass verification');
    $tampered = $question;
    $tampered['sourceHash'] = str_repeat('a', 64);
    check_same(false, $generator->verify($tampered, $context, $draft), 'unrelated source hash rejected');
    $tampered = $question;
    $tampered['prompt'] = '<script>alert(1)</script>';
    check_same(false, $generator->verify($tampered, $context, $draft), 'question text is bound to source generator');
    $tampered = $question;
    $tampered['choices'][1]['id'] = $tampered['choices'][0]['id'];
    check_same(false, $generator->verify($tampered), 'ambiguous duplicate option identifiers rejected');
    $tampered = $question;
    $tampered['choices'][1]['label'] = $tampered['choices'][0]['label'];
    check_same(false, $generator->verify($tampered), 'duplicate option labels rejected');
    $tampered = $question;
    $tampered['verification']['method'] = 'unverified_ai';
    check_same(false, $generator->verify($tampered), 'unknown verification method rejected');
}
$changed = $draft;
$changed['source'] .= "\n# a newer draft";
check_same(false, $generator->verify($questions[0], $context, $changed), 'new draft invalidates source snapshot even when comparison unchanged');
check_same(false, $generator->verify($questions[1], array('problem' => $context['problem']), $draft), 'missing original submission invalidates its candidate');
$changed_context = $context;
$changed_context['problem']['sample_output'] = '4';
check_same(false, $generator->verify($questions[2], $changed_context, $draft), 'changed official reference invalidates sample candidate');
check_same(false, $generator->verify(array()), 'empty candidate rejected');

// Independent numeric oracle exercises every comparison, orientation, sign and
// supported language. Expected truth uses mathematical ordering rather than the
// generator's switch or source parser.
$operators = array('<' => array(-1), '<=' => array(-1, 0), '>' => array(1), '>=' => array(0, 1), '==' => array(0), '!=' => array(-1, 1));
foreach (array('python', 'c', 'cpp', 'java') as $language) {
    foreach ($operators as $operator => $orders) {
        foreach (array(-1000000, -3, 0, 7, 1000000) as $constant) {
            foreach (array(false, true) as $reverse) {
                $expression = $reverse ? $constant . ' ' . $operator . ' value' : 'value ' . $operator . ' ' . $constant;
                $source = $language === 'python' ? 'if ' . $expression . ":\n    pass" : 'if (' . $expression . ') {}';
                $items = draft_questions($source, $language);
                check_same(1, count($items), 'supported bounded comparison yields one question');
                $question = $items[0];
                preg_match('/value = (-?\d+)/', $question['assignments'], $assigned);
                $value = (int) $assigned[1];
                $left = $reverse ? $constant : $value;
                $right = $reverse ? $value : $constant;
                $expected = in_array($left <=> $right, $orders, true) ? 'true' : 'false';
                check_same($expected, $question['correctAnswer'], 'mathematical comparison oracle');
                check_same(true, abs($value) <= 1000000, 'synthesised assignments stay bounded');
                check_same(true, $generator->verify($question), 'independent verification agrees');
            }
        }
    }
}

foreach (array(
    'if x + 1 > 2:', 'if x > y:', 'if 2 > 1:', 'if x > 1 and x < 2:',
    'if 0 < x < 3:', 'if fn(x) > 0:', 'if x[0] > 0:', 'if obj.x > 0:',
    'if x > 1.5:', 'if x > 01:', 'if x > 0x10:', 'if x > 1_000:',
    'if x > 1000001:', 'if x > -1000001:', 'if x is None:', 'if True == 1:',
    'if async > 1:', 'if (' . str_repeat('x', 33) . ' > 0):', 'if ((x > 0)):',
    'if __debug__ > 1:',
    'if x == "value":', 'if x > 0: print(1)',
) as $source) {
    check_same(array(), draft_questions($source), 'unsupported Python grammar omitted: ' . $source);
}
foreach (array('if (x < 2 && x > 1) {}', 'if (fn(x) < 2) {}', 'if ((x < 2)) {}', 'if (x++ < 2) {}', 'if (x < 2u) {}', 'if (x == "value") {}', 'if (nullptr > 0) {}', 'if (inline > 0) {}') as $source) {
    check_same(array(), draft_questions($source, 'cpp'), 'unsupported C-family grammar omitted');
}
check_same(array(), draft_questions('if (_ > 0) {}', 'java'), 'Java reserved underscore cannot produce invalid assignment');

$lexical_decoys = array(
    array('python', "# if ghost > 1:\ntext = 'if phantom > 2:'"),
    array('python', "'''\nif ghost > 1:\n'''"),
    array('python', "text = \"\"\"\nif ghost > 1:\n\"\"\""),
    array('python', "text = r'''\nif ghost > 1:\n'''"),
    array('python', "text = f'{call(1)}'\nif x > 1:"),
    array('python', "text = 'unterminated\nif x > 1:"),
    array('c', "// if (ghost > 1) {}\nchar *s = \"if (phantom > 2) {}\";"),
    array('cpp', "/* if (ghost > 1) {} */\nchar *s = \"escaped \\\" if (phantom > 2) {}\";"),
    array('c', "/* unterminated\nif (ghost > 1) {}"),
    array('c', "#define EXPRESSION if (ghost > 1) {}"),
    array('cpp', 'auto s = R"tag(if (ghost > 1) {})tag"; if (x > 1) {}'),
    array('java', "String s = \"\"\"\nif (ghost > 1) {}\n\"\"\"; if (x > 1) {}"),
    array('java', '\\u002f\\u002f comment if (ghost > 1) {}'),
    array('java', "// \\u000a\\u002f\\u002a\nif (ghost > 1) {}"),
    array('c', "// comment \\\nif (ghost > 1) {}"),
    array('c', "// comment ??/\nif (ghost > 1) {}"),
);
foreach ($lexical_decoys as [$language, $source]) {
    check_same(array(), draft_questions($source, $language), 'comment/string/pre-lexing decoy omitted');
}
check_same(1, count(draft_questions("# if ghost > 1:\nif real >= 5: # student code\n    pass")), 'Python comments do not hide following real condition');
check_same(1, count(draft_questions('if (real /* comment */ >= 5) {}', 'c')), 'comments separate valid C tokens');
check_same(0, count(draft_questions('if (rea/**/l >= 5) {}', 'c')), 'comment removal cannot concatenate identifiers');
check_same(1, count(draft_questions("while (real != -2):\n    pass")), 'Python parenthesised while condition');
check_same(1, count(draft_questions("elif real != -2:\n    pass")), 'isolated elif comparison supported in unfinished draft');
check_same(1, count(draft_questions("if real > 5:\n    pass\nif real > 5:\n    pass")), 'duplicate source expressions deduplicated');

$range_cases = array(
    '3' => '[0, 1, 2]', '1, 4' => '[1, 2, 3]', '6, -1, -2' => '[6, 4, 2, 0]',
    '0' => '[]', '5, 1' => '[]', '1, 5, -1' => '[]', '-2, 3' => '[-2, -1, 0, 1, 2]',
    '-1000000, -999992' => '[-1000000, -999999, -999998, -999997, -999996, -999995, -999994, -999993]',
    '1000000, 999999, -1000000' => '[1000000]',
);
foreach ($range_cases as $arguments => $expected) {
    $items = draft_questions('for i in range(' . $arguments . "):\n    print(i)");
    check_same(1, count($items), 'bounded range yields question: ' . $arguments);
    check_same($expected, correct_label($items[0]), 'range independent fixture');
    check_same(true, $generator->verify($items[0]), 'range independent cardinality verifier');
    check_same(true, str_contains($items[0]['prompt'], '내장 range'), 'builtin semantics explicitly stated');
}
foreach (array('1, 4, 0', '9', '1000001', '1, n', '1, 3, 1, 1', '', '0x10', '1_000', '1.0', 'fn()', '1, 3,', '01') as $arguments) {
    check_same(array(), draft_questions('for i in range(' . $arguments . "):\n    pass"), 'unsafe or unsupported range omitted');
}

$fallback = $generator->generate_candidates($context, array('source' => 'unfinished(', 'language' => 'python'));
check_same(array('submission', 'problem'), array_column($fallback, 'sourceKind'), 'unfinished draft still permits own submission and problem');
$sample_only = $generator->generate_candidates(array('problem' => $context['problem']), array('source' => '', 'language' => 'python'));
check_same(1, count($sample_only), 'no draft or submission still receives grounded sample question');
check_same('3', correct_label($sample_only[0]), 'published pair is the reference');
check_same(true, str_contains($sample_only[0]['prompt'], '공개 예제'), 'sample recognition is explicitly labelled');
foreach (array(
    array('sample_input' => '1'), array('sample_output' => '2'),
    array('sample_input' => ' ', 'sample_output' => '2'),
    array('sample_input' => '1', 'sample_output' => ''),
    array('sample_input' => array('1'), 'sample_output' => '2'),
    array('sample_input' => '1', 'sample_output' => str_repeat('2', 2001)),
    array('sample_input' => "1\0", 'sample_output' => '2'),
) as $problem) {
    check_same(array(), $generator->generate_candidates(array('problem' => $problem)), 'missing/malformed/unbounded samples fail closed');
}
$conflict = array('id' => 7, 'samples' => array(array('input' => '1', 'output' => '2'), array('input' => '1', 'output' => '3')));
check_same(array(), $generator->generate_candidates(array('problem' => $conflict)), 'conflicting official output for same input omitted');
$later_conflict = array('id' => 7, 'samples' => array(array('input' => '1', 'output' => '2'), array('input' => '2', 'output' => '4'), array('input' => '3', 'output' => '6'), array('input' => '1', 'output' => '3')));
check_same(2, count($generator->generate_candidates(array('problem' => $later_conflict))), 'conflicting later pair also excludes earlier same input');
check_same(array(), $generator->generate_candidates(array('problem' => array('samples' => array_fill(0, 17, array('input' => '1', 'output' => '2'))))), 'excessive sample collection fails closed');
$multiple = array('id' => 7, 'samples' => array(array('input' => '1', 'output' => '2'), array('input' => '2', 'output' => '4')));
check_same(2, count($generator->generate_candidates(array('problem' => $multiple))), 'multiple paired samples supported');
$injection = array('id' => 7, 'sample_input' => '<script>not markup</script>', 'sample_output' => '<img src=x onerror=alert(1)>');
$injected_question = $generator->generate_candidates(array('problem' => $injection))[0];
check_same($injection['sample_input'], $injected_question['input'], 'HTML-like input retained as literal source data');
check_same($injection['sample_output'], correct_label($injected_question), 'HTML-like output retained as literal source data for textContent rendering');
check_same(true, $generator->verify($injected_question, array('problem' => $injection)), 'literal sample pair verified without HTML interpretation');
check_same(array(), draft_questions(str_repeat(' ', 65536) . 'if x > 1:'), 'source size bound enforced');
check_same(array(), draft_questions("if x > 1:\n\xFF"), 'invalid UTF-8 source omitted');

echo 'check-generator: ' . $checks . " checks passed\n";
