<?php
declare(strict_types=1);

namespace FocusTracker\Checks;

/**
 * Builds small, self-contained questions without executing student code.
 *
 * This is deliberately a recogniser for a bounded grammar, not an interpreter.
 * Code questions concern an isolated comparison with newly assigned integers,
 * or Python's built-in range. They never claim to predict the whole program.
 * Published samples establish only the input/output pair stated by the problem.
 * Context must already have been authorised by the platform integration.
 */
final class Verified_Question_Generator {
    private const MAX_SOURCE_BYTES = 65536;
    private const MAX_SAMPLE_BYTES = 2000;
    private const MAX_SAMPLE_COUNT = 16;
    private const MAX_INTEGER = 1000000;
    private const OPERAND = '(?:[A-Za-z_][A-Za-z0-9_]{0,31}|[+-]?(?:0|[1-9][0-9]{0,6}))';
    private const RESERVED = array('True', 'False', 'None', 'true', 'false', 'null', 'if', 'while', 'for', 'int', 'long', 'double', 'float', 'char', 'bool', 'return', 'class', 'new', 'this', 'and', 'or', 'not', 'in', 'is', 'else', 'elif', 'break', 'continue', 'pass', 'lambda', 'def', 'import', 'from', 'as', 'assert', 'async', 'await', 'del', 'except', 'finally', 'global', 'nonlocal', 'raise', 'try', 'with', 'yield', 'auto', 'const', 'static', 'switch', 'case', 'default', 'void', 'unsigned', 'signed', 'sizeof', 'struct', 'enum', 'union', 'volatile', 'do', 'public', 'private', 'protected', 'package', 'interface', 'boolean', 'byte', 'short', 'final', 'extends', 'implements', 'instanceof', 'throws', 'throw', 'catch', 'super', 'native', 'synchronized', 'transient', 'abstract', 'strictfp', 'record', 'var', 'alignas', 'alignof', 'asm', 'bitand', 'bitor', 'compl', 'concept', 'consteval', 'constexpr', 'constinit', 'co_await', 'co_return', 'co_yield', 'decltype', 'delete', 'dynamic_cast', 'explicit', 'export', 'extern', 'friend', 'goto', 'inline', 'mutable', 'namespace', 'noexcept', 'not_eq', 'nullptr', 'operator', 'or_eq', 'register', 'reinterpret_cast', 'requires', 'restrict', 'static_assert', 'static_cast', 'template', 'thread_local', 'typedef', 'typeid', 'typename', 'using', 'virtual', 'wchar_t', 'xor', 'xor_eq', 'and_eq', 'char8_t', 'char16_t', 'char32_t', '_Alignas', '_Alignof', '_Atomic', '_Bool', '_Complex', '_Generic', '_Imaginary', '_Noreturn', '_Static_assert', '_Thread_local');

    /** @return array<int,array<string,mixed>> */
    public function generate_candidates(array $context, ?array $draft = null): array {
        $candidates = $this->build($context, $draft);
        return array_values(array_filter($candidates, fn (array $candidate): bool => $this->verify($candidate, $context, $draft)));
    }

    public function generate(array $context, ?array $draft = null): ?array {
        return $this->generate_candidates($context, $draft)[0] ?? null;
    }

    /**
     * Recomputes the answer from the displayed data, independently of generation.
     * Passing current context also binds every displayed field to its real source.
     * Without context this validates consistency only, not source authenticity.
     */
    public function verify(array $candidate, ?array $context = null, ?array $draft = null): bool {
        if (! $this->verify_display($candidate)) {
            return false;
        }
        if (null === $context) {
            return true;
        }
        $fingerprint = $this->fingerprint($candidate);
        foreach ($this->build($context, $draft) as $expected) {
            if (hash_equals($fingerprint, $this->fingerprint($expected))) {
                return true;
            }
        }
        return false;
    }

    private function build(array $context, ?array $draft): array {
        $result = array();
        foreach (array('draft' => $draft, 'submission' => $context['submission'] ?? null) as $kind => $code) {
            if (is_array($code)) {
                $result = array_merge($result, $this->code_candidates($code, $kind));
            }
        }
        if (isset($context['problem']) && is_array($context['problem'])) {
            $result = array_merge($result, $this->sample_candidates($context['problem']));
        }
        return $result;
    }

    private function language(mixed $value): ?string {
        if (! is_string($value)) {
            return null;
        }
        return match (strtolower(trim($value))) {
            'python', 'python3', 'python 3', 'py', 'pypy', 'pypy3' => 'python',
            'c', 'c11', 'c17', 'c99', 'gcc' => 'c',
            'c++', 'cpp', 'c++11', 'c++14', 'c++17', 'c++20', 'g++' => 'cpp',
            'java', 'java8', 'java11', 'java17', 'java21' => 'java',
            default => null,
        };
    }

    private function code_candidates(array $code, string $source_kind): array {
        $source = $code['source'] ?? null;
        $language = $this->language($code['language'] ?? null);
        if (null === $language || ! $this->text($source, self::MAX_SOURCE_BYTES)) {
            return array();
        }
        $clean = $this->without_comments_and_strings($source, $language);
        if (null === $clean) {
            return array();
        }
        $hash = hash('sha256', $language . "\n" . $source);
        $expressions = array();
        if ($language === 'python') {
            foreach (explode("\n", $clean) as $line) {
                if (preg_match('/^\s*(?:if|elif|while)\s+(.+?)\s*:\s*$/D', $line, $match)) {
                    $expression = trim($match[1]);
                    if (str_starts_with($expression, '(') && str_ends_with($expression, ')')) {
                        $expression = trim(substr($expression, 1, -1));
                    }
                    $expressions[] = $expression;
                }
            }
        } else {
            preg_match_all('/(?<![A-Za-z0-9_.])(?:if|while)\s*\(([^()]*)\)/', $clean, $matches);
            $expressions = $matches[1];
        }
        $result = array();
        $seen = array();
        foreach ($expressions as $expression) {
            $parsed = $this->comparison(trim($expression));
            if (null === $parsed || isset($seen[$parsed['expression']])) {
                continue;
            }
            $seen[$parsed['expression']] = true;
            $value = max(-self::MAX_INTEGER, min(self::MAX_INTEGER, $parsed['constant'] + (hexdec(substr($hash, 0, 2)) % 3) - 1));
            $assignment = ($language === 'python' ? '' : 'int ') . $parsed['variable'] . ' = ' . $value . ($language === 'python' ? '' : ';');
            $left = $parsed['left'] === $parsed['variable'] ? $value : (int) $parsed['left'];
            $right = $parsed['right'] === $parsed['variable'] ? $value : (int) $parsed['right'];
            $answer = $this->compare($left, $parsed['operator'], $right) ? 'true' : 'false';
            $result[] = array(
                'kind' => 'condition',
                'prompt' => '본인 코드의 비교식을 따로 떼어 냈습니다. 아래처럼 정수 값을 새로 정했을 때, 마지막 비교식은 참인가요? 전체 프로그램의 실행 결과를 묻는 문항은 아닙니다.',
                'codeSnippet' => $assignment . "\n" . $parsed['expression'],
                'assignments' => $assignment,
                'input' => '',
                'choices' => array(array('id' => 'true', 'label' => '참'), array('id' => 'false', 'label' => '거짓')),
                'correctAnswer' => $answer,
                'sourceKind' => $source_kind,
                'sourceHash' => $hash,
                'verification' => array('method' => 'bounded_integer_comparison', 'version' => 1),
                'explanation' => $parsed['expression'] . '에 ' . $parsed['variable'] . ' = ' . $value . '을 대입하면 ' . ($answer === 'true' ? '참' : '거짓') . '입니다.',
                'proof' => array('language' => $language, 'expression' => $parsed['expression']),
            );
            if (count($result) >= 3) {
                break;
            }
        }
        if ($language === 'python') {
            foreach (explode("\n", $clean) as $line) {
                if (preg_match('/^\s*for\s+([A-Za-z_][A-Za-z0-9_]{0,31})\s+in\s+range\s*\(([^()]*)\)\s*:\s*$/D', $line, $match) && ! in_array($match[1], self::RESERVED, true)) {
                    $args = $this->range_arguments($match[2]);
                    if (null !== $args) {
                        $range = $this->range_candidate($args, $source_kind, $hash);
                        if (null !== $range && ! isset($seen[$range['codeSnippet']])) {
                            $seen[$range['codeSnippet']] = true;
                            $result[] = $range;
                        }
                    }
                }
                if (count($result) >= 5) {
                    break;
                }
            }
        }
        return $result;
    }

    private function comparison(string $expression): ?array {
        if (! preg_match('/^(' . self::OPERAND . ')\s*(<=|>=|==|!=|<|>)\s*(' . self::OPERAND . ')$/D', $expression, $match)) {
            return null;
        }
        $left_number = $this->integer($match[1]);
        $right_number = $this->integer($match[3]);
        if ((null === $left_number) === (null === $right_number)) {
            return null;
        }
        $variable = null === $left_number ? $match[1] : $match[3];
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/D', $variable) || in_array($variable, self::RESERVED, true) || in_array($variable, array('__debug__', '_', '_Pragma'), true)) {
            return null;
        }
        // A numeric token outside the bound must not be reclassified as a name.
        $constant_text = null === $left_number ? $match[3] : $match[1];
        $constant = $this->integer($constant_text);
        if (null === $constant) {
            return null;
        }
        return array('left' => $match[1], 'right' => $match[3], 'operator' => $match[2], 'variable' => $variable, 'constant' => $constant, 'expression' => $match[1] . ' ' . $match[2] . ' ' . $match[3]);
    }

    private function compare(int $left, string $operator, int $right): bool {
        return match ($operator) {
            '<' => $left < $right, '<=' => $left <= $right,
            '>' => $left > $right, '>=' => $left >= $right,
            '==' => $left === $right, '!=' => $left !== $right,
        };
    }

    private function range_arguments(string $text): ?array {
        $tokens = explode(',', $text);
        if (count($tokens) < 1 || count($tokens) > 3) {
            return null;
        }
        $args = array();
        foreach ($tokens as $token) {
            $number = $this->integer(trim($token));
            if (null === $number) {
                return null;
            }
            $args[] = $number;
        }
        if (isset($args[2]) && $args[2] === 0) {
            return null;
        }
        return $args;
    }

    private function range_values(array $args): ?array {
        $start = count($args) === 1 ? 0 : $args[0];
        $stop = count($args) === 1 ? $args[0] : $args[1];
        $step = $args[2] ?? 1;
        $result = array();
        for ($value = $start; $step > 0 ? $value < $stop : $value > $stop; $value += $step) {
            $result[] = $value;
            if (count($result) > 8) {
                return null;
            }
        }
        return $result;
    }

    private function range_candidate(array $args, string $source_kind, string $hash): ?array {
        $values = $this->range_values($args);
        if (null === $values) {
            return null;
        }
        $correct = $this->list_label($values);
        $alternatives = array($correct, $this->list_label(array_reverse($values)), $this->list_label(array_merge($values, array((count($args) === 1 ? $args[0] : $args[1])))), '[]', '[0]', '[1]');
        [$choices, $answer] = $this->choices($correct, $alternatives, $hash);
        return array(
            'kind' => 'range_values',
            'prompt' => '본인 코드에서 가져온 range의 인수입니다. 파이썬 내장 range를 사용할 때, 아래 식이 만드는 정수 목록은 무엇인가요?',
            'codeSnippet' => 'list(range(' . implode(', ', $args) . '))',
            'input' => '',
            'choices' => $choices,
            'correctAnswer' => $answer,
            'sourceKind' => $source_kind,
            'sourceHash' => $hash,
            'verification' => array('method' => 'bounded_builtin_range', 'version' => 1),
            'explanation' => '내장 range는 끝값을 포함하지 않으며, 이 인수에서는 ' . $correct . '을 만듭니다.',
            'proof' => array('language' => 'python', 'arguments' => $args),
        );
    }

    private function sample_candidates(array $problem): array {
        $samples = array();
        if (isset($problem['sample_input'], $problem['sample_output'])) {
            $samples[] = array('input' => $problem['sample_input'], 'output' => $problem['sample_output']);
        }
        if (isset($problem['samples']) && is_array($problem['samples'])) {
            if (count($problem['samples']) > self::MAX_SAMPLE_COUNT) {
                return array();
            }
            foreach ($problem['samples'] as $sample) {
                if (is_array($sample)) {
                    $samples[] = $sample;
                }
            }
        }
        // Conflicting published pairs for the same input cannot establish one
        // unambiguous answer. Decline every conflicting pair.
        $by_input = array();
        foreach ($samples as $sample) {
            if ($this->text($sample['input'] ?? null, self::MAX_SAMPLE_BYTES) && $this->text($sample['output'] ?? null, self::MAX_SAMPLE_BYTES)) {
                $key = $this->normalise_sample($sample['input']);
                $by_input[$key][] = $this->normalise_sample($sample['output']);
            }
        }
        $result = array();
        $seen = array();
        foreach ($samples as $sample) {
            if (! $this->text($sample['input'] ?? null, self::MAX_SAMPLE_BYTES) || ! $this->text($sample['output'] ?? null, self::MAX_SAMPLE_BYTES)) {
                continue;
            }
            $input = $this->normalise_sample($sample['input']);
            $output = $this->normalise_sample($sample['output']);
            if (count(array_unique($by_input[$input])) !== 1) {
                continue;
            }
            $hash = hash('sha256', json_encode(array($problem['id'] ?? null, $input, $output), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $distractor = preg_match('/^-?[0-9]{1,6}$/D', $output) ? (string) ((int) $output + 1) : $output . "\n?";
            [$choices, $answer] = $this->choices($output, array($output, $distractor, '출력 없음', '?'), $hash);
            $result[] = array(
                'kind' => 'published_sample',
                'prompt' => '현재 문제의 공개 예제 내용을 확인합니다. 다음 예제 입력과 짝을 이루는 공개 예제 출력은 무엇인가요? 코드 실행 결과를 새로 판정하는 문항은 아닙니다.',
                'codeSnippet' => '',
                'input' => $input,
                'choices' => $choices,
                'correctAnswer' => $answer,
                'sourceKind' => 'problem',
                'sourceHash' => $hash,
                'verification' => array('method' => 'published_sample_pair', 'version' => 1),
                'explanation' => '문제에 공개된 해당 입력의 예제 출력은 다음과 같습니다.' . "\n" . $output,
                'proof' => array('input' => $input, 'output' => $output),
            );
            if (count($result) >= 3) {
                break;
            }
        }
        return $result;
    }

    private function choices(string $correct, array $alternatives, string $hash): array {
        $labels = array_slice(array_values(array_unique($alternatives)), 0, 3);
        $shift = hexdec(substr($hash, 2, 2)) % count($labels);
        $labels = array_merge(array_slice($labels, $shift), array_slice($labels, 0, $shift));
        $choices = array();
        $answer = '';
        foreach ($labels as $index => $label) {
            $id = chr(97 + $index);
            $choices[] = array('id' => $id, 'label' => $label);
            if ($label === $correct) {
                $answer = $id;
            }
        }
        return array($choices, $answer);
    }

    private function verify_display(array $candidate): bool {
        if (! isset($candidate['kind'], $candidate['codeSnippet'], $candidate['input'], $candidate['correctAnswer'], $candidate['choices'], $candidate['proof'], $candidate['verification'], $candidate['sourceKind'], $candidate['sourceHash']) || ! is_array($candidate['proof']) || ! is_array($candidate['choices']) || ! is_array($candidate['verification'])) {
            return false;
        }
        if (! in_array($candidate['sourceKind'], array('draft', 'submission', 'problem'), true) || ! is_string($candidate['sourceHash']) || ! preg_match('/^[a-f0-9]{64}$/D', $candidate['sourceHash']) || ($candidate['verification']['version'] ?? null) !== 1) {
            return false;
        }
        $labels = array();
        $ids = array();
        foreach ($candidate['choices'] as $choice) {
            if (! is_array($choice) || ! isset($choice['id'], $choice['label']) || ! is_string($choice['id']) || ! is_string($choice['label']) || isset($ids[$choice['id']]) || in_array($choice['label'], $labels, true)) {
                return false;
            }
            $ids[$choice['id']] = true;
            $labels[$choice['id']] = $choice['label'];
        }
        if (count($labels) < 2 || count($labels) > 3 || ! is_string($candidate['correctAnswer']) || ! isset($labels[$candidate['correctAnswer']])) {
            return false;
        }
        $proof = $candidate['proof'];
        if ($candidate['kind'] === 'condition') {
            if (! in_array($proof['language'] ?? null, array('python', 'c', 'cpp', 'java'), true) || ($candidate['verification']['method'] ?? null) !== 'bounded_integer_comparison' || $candidate['sourceKind'] === 'problem' || $candidate['input'] !== '') {
                return false;
            }
            $prefix = $proof['language'] === 'python' ? '' : 'int ';
            $suffix = $proof['language'] === 'python' ? '' : ';';
            if (! is_string($candidate['codeSnippet']) || ! preg_match('/^' . $prefix . '([A-Za-z_][A-Za-z0-9_]{0,31}) = (-?[0-9]{1,7})' . $suffix . '\n(.+)$/D', $candidate['codeSnippet'], $match)) {
                return false;
            }
            $parsed = $this->comparison($match[3]);
            $value = $this->integer($match[2]);
            if (null === $parsed || null === $value || $parsed['variable'] !== $match[1] || ($proof['expression'] ?? null) !== $parsed['expression'] || ($candidate['assignments'] ?? null) !== $prefix . $match[1] . ' = ' . $match[2] . $suffix) {
                return false;
            }
            // Independent oracle: compare order, then use the operator's truth set.
            $left = $parsed['left'] === $match[1] ? $value : (int) $parsed['left'];
            $right = $parsed['right'] === $match[1] ? $value : (int) $parsed['right'];
            $order = $left <=> $right;
            $truth_orders = array('<' => array(-1), '<=' => array(-1, 0), '>' => array(1), '>=' => array(0, 1), '==' => array(0), '!=' => array(-1, 1));
            $expected = in_array($order, $truth_orders[$parsed['operator']], true) ? 'true' : 'false';
            return $candidate['correctAnswer'] === $expected && $labels === array('true' => '참', 'false' => '거짓');
        }
        if ($candidate['kind'] === 'range_values') {
            if (($proof['language'] ?? null) !== 'python' || ($candidate['verification']['method'] ?? null) !== 'bounded_builtin_range' || $candidate['sourceKind'] === 'problem' || $candidate['input'] !== '' || ! is_string($candidate['codeSnippet']) || ! preg_match('/^list\(range\(([^()]*)\)\)$/D', $candidate['codeSnippet'], $match)) {
                return false;
            }
            $args = $this->range_arguments($match[1]);
            if (null === $args || ($proof['arguments'] ?? null) !== $args) {
                return false;
            }
            // Independent oracle: calculate cardinality, then indexed values.
            $start = count($args) === 1 ? 0 : $args[0];
            $end = count($args) === 1 ? $args[0] : $args[1];
            $step = $args[2] ?? 1;
            $distance = $step > 0 ? $end - $start : $start - $end;
            $count = $distance <= 0 ? 0 : intdiv($distance + abs($step) - 1, abs($step));
            if ($count > 8) {
                return false;
            }
            $values = array();
            for ($i = 0; $i < $count; ++$i) {
                $values[] = $start + $i * $step;
            }
            return $labels[$candidate['correctAnswer']] === $this->list_label($values);
        }
        if ($candidate['kind'] === 'published_sample') {
            return $candidate['sourceKind'] === 'problem' && ($candidate['verification']['method'] ?? null) === 'published_sample_pair' && $candidate['codeSnippet'] === '' && $this->text($proof['input'] ?? null, self::MAX_SAMPLE_BYTES) && $this->text($proof['output'] ?? null, self::MAX_SAMPLE_BYTES) && $candidate['input'] === $proof['input'] && $labels[$candidate['correctAnswer']] === $proof['output'];
        }
        return false;
    }

    private function fingerprint(array $candidate): string {
        $fields = array();
        foreach (array('kind', 'prompt', 'codeSnippet', 'assignments', 'input', 'choices', 'correctAnswer', 'sourceKind', 'sourceHash', 'verification', 'explanation', 'proof') as $field) {
            $fields[$field] = $candidate[$field] ?? null;
        }
        return hash('sha256', (string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function integer(string $text): ?int {
        if (! preg_match('/^[+-]?(?:0|[1-9][0-9]{0,6})$/D', $text)) {
            return null;
        }
        $integer = (int) $text;
        return abs($integer) <= self::MAX_INTEGER ? $integer : null;
    }

    private function text(mixed $text, int $maximum): bool {
        return is_string($text) && strlen($text) <= $maximum && trim($text) !== '' && preg_match('//u', $text) === 1 && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text);
    }

    private function normalise_sample(string $text): string {
        return trim(str_replace(array("\r\n", "\r"), "\n", $text), "\n");
    }

    private function list_label(array $values): string {
        return '[' . implode(', ', $values) . ']';
    }

    /** Removes lexical comments/literals while preserving token separation. */
    private function without_comments_and_strings(string $source, string $language): ?string {
        $source = str_replace(array("\r\n", "\r"), "\n", $source);
        if ($language !== 'python' && (str_contains($source, "\\\n") || str_contains($source, '??/') || str_contains($source, '\\u'))) {
            return null;
        }
        $length = strlen($source);
        $output = '';
        for ($i = 0; $i < $length; ++$i) {
            $char = $source[$i];
            $next = $source[$i + 1] ?? '';
            if (($language === 'python' && $char === '#') || ($language !== 'python' && $char === '/' && $next === '/') || ($language !== 'python' && $char === '#' && preg_match('/^\s*$/D', substr($output, (int) strrpos("\n" . $output, "\n"))))) {
                while ($i < $length && $source[$i] !== "\n") { $output .= ' '; ++$i; }
                if ($i < $length) { $output .= "\n"; }
                continue;
            }
            if ($language !== 'python' && $char === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                if (false === $end) { return null; }
                $comment = substr($source, $i, $end + 2 - $i);
                $output .= preg_replace('/[^\n]/', ' ', $comment);
                $i = $end + 1;
                continue;
            }
            if ($char === '"' || $char === "'") {
                // Interpolation, C++ raw strings and Java text blocks have different
                // nested lexing rules. Reject the source rather than guess them.
                $before = substr($source, max(0, $i - 2), min(2, $i));
                if (($language === 'python' && preg_match('/[fF][rR]?$|[rR][fF]$/D', $before)) || ($language !== 'python' && str_ends_with($before, 'R')) || ($language === 'java' && substr($source, $i, 3) === '"""')) { return null; }
                $triple = $language === 'python' && substr($source, $i, 3) === str_repeat($char, 3);
                $delimiter = $triple ? str_repeat($char, 3) : $char;
                $start = $i;
                $i += strlen($delimiter);
                $closed = false;
                while ($i < $length) {
                    if ($source[$i] === '\\') { $i += 2; continue; }
                    if (substr($source, $i, strlen($delimiter)) === $delimiter) { $i += strlen($delimiter) - 1; $closed = true; break; }
                    if (! $triple && $source[$i] === "\n") { return null; }
                    ++$i;
                }
                if (! $closed) { return null; }
                // Non-whitespace marker prevents a string operand from vanishing.
                $literal = substr($source, $start, $i - $start + 1);
                $output .= preg_replace('/[^\n]/', '@', $literal);
                continue;
            }
            // C line splices and Java Unicode escapes are translated before
            // comments are lexed; this recogniser intentionally declines them.
            if ($language !== 'python' && ($char === '\\' && ($next === "\n" || $next === 'u'))) { return null; }
            $output .= $char;
        }
        return $output;
    }
}
