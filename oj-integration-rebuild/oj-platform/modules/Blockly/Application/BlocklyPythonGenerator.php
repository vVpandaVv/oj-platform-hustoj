<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Application;

use CodingPanda\OjPlatform\Modules\Blockly\Domain\ValidatedBlocklyWorkspace;
use DomainException;

final class BlocklyPythonGenerator
{
    /**
     * @var array<string, string>
     */
    private array $variables = [];

    /**
     * @var array<string, string>
     */
    private array $procedures = [];

    /**
     * @var array<string, true>
     */
    private array $usedIdentifiers = [];

    private bool $needsMath = false;

    private bool $needsPrime = false;

    private int $temporarySequence = 0;

    public function generate(ValidatedBlocklyWorkspace $validated): string
    {
        $workspace = $validated->workspace();
        $this->variables = [];
        $this->procedures = [];
        $this->usedIdentifiers = [];
        $this->needsMath = false;
        $this->needsPrime = false;
        $this->temporarySequence = 0;
        $this->indexVariables($workspace['variables'] ?? []);

        $topBlocks = $workspace['blocks']['blocks'] ?? [];
        $start = null;
        $procedures = [];
        foreach ($topBlocks as $block) {
            if (($block['type'] ?? '') === 'oj_start') {
                $start = $block;
            } elseif (str_starts_with(
                (string) ($block['type'] ?? ''),
                'procedures_def'
            )) {
                $procedures[] = $block;
            }
        }
        if (! is_array($start)) {
            throw new DomainException('Blockly 프로그램 시작 블록이 없습니다.');
        }
        foreach ($procedures as $procedure) {
            $rawName = $this->field($procedure, 'NAME', 'function');
            $this->procedures[$rawName] = $this->identifier(
                $rawName,
                'function'
            );
        }
        $procedureCode = '';
        foreach ($procedures as $procedure) {
            $procedureCode .= $this->procedureDefinition($procedure) . "\n";
        }
        $startBlock = $this->inputBlock($start, 'DO');
        if ($startBlock === null) {
            throw new DomainException(
                '프로그램 시작 블록에 실행할 블록을 연결해 주세요.'
            );
        }
        $body = rtrim($this->statementChain($startBlock, 0));
        if ($body === '') {
            throw new DomainException('실행할 Blockly 블록이 없습니다.');
        }

        $prefix = [];
        if ($validated->usesInput()) {
            $prefix[] = $this->inputPrelude();
        }
        if ($this->needsMath) {
            array_unshift($prefix, 'import math');
        }
        if ($this->needsPrime) {
            $prefix[] = $this->primeHelper();
        }
        $parts = array_filter([
            implode("\n\n", $prefix),
            trim($procedureCode),
            $body,
        ], static fn (string $part): bool => trim($part) !== '');

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * @param array<string, mixed> $block
     */
    private function statementChain(array $block, int $indent): string
    {
        $code = '';
        $current = $block;
        $seen = 0;
        while (true) {
            if (++$seen > 500) {
                throw new DomainException('Blockly 문장 연결이 너무 깁니다.');
            }
            $code .= $this->statement($current, $indent);
            $next = $current['next']['block'] ?? null;
            if (! is_array($next)) {
                break;
            }
            $current = $next;
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function statement(array $block, int $indent): string
    {
        $type = (string) ($block['type'] ?? '');
        $line = match ($type) {
            'variables_set' => $this->variable($block, 'VAR')
                . ' = ' . $this->requiredValue($block, 'VALUE'),
            'math_change' => $this->variable($block, 'VAR')
                . ' += ' . $this->requiredValue($block, 'DELTA'),
            'oj_print' => $this->printStatement($block),
            'oj_print_list' => 'print('
                . $this->pythonString($this->field($block, 'DELIM', ' '))
                . '.join(map(str, ' . $this->requiredValue($block, 'LIST')
                . ')))',
            'py_list_set' => '(' . $this->requiredValue($block, 'LIST')
                . ')[int(' . $this->requiredValue($block, 'INDEX')
                . ')] = ' . $this->requiredValue($block, 'VALUE'),
            'py_list_append' => '(' . $this->requiredValue($block, 'LIST')
                . ').append(' . $this->requiredValue($block, 'VALUE') . ')',
            'py_dict_set' => '(' . $this->requiredValue($block, 'DICT')
                . ')[' . $this->requiredValue($block, 'KEY') . '] = '
                . $this->requiredValue($block, 'VALUE'),
            'py_set_add' => '(' . $this->requiredValue($block, 'SET')
                . ').add(' . $this->requiredValue($block, 'VALUE') . ')',
            'py_set_discard' => '(' . $this->requiredValue($block, 'SET')
                . ').discard(' . $this->requiredValue($block, 'VALUE') . ')',
            'procedures_callnoreturn' => $this->procedureCall($block),
            'procedures_ifreturn' => $this->procedureIfReturn($block),
            'controls_flow_statements' => $this->flowStatement($block),
            default => null,
        };
        if (is_string($line)) {
            return $this->indent($indent) . $line . "\n";
        }

        return match ($type) {
            'controls_repeat_ext' => $this->repeatStatement($block, $indent),
            'controls_if' => $this->ifStatement($block, $indent),
            'controls_whileUntil' => $this->whileStatement($block, $indent),
            'controls_for' => $this->forStatement($block, $indent),
            'controls_forEach' => $this->forEachStatement($block, $indent),
            default => throw new DomainException(
                '문장 위치에서 지원하지 않는 Blockly 블록입니다: ' . $type
            ),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function expression(array $block): string
    {
        $type = (string) ($block['type'] ?? '');

        return match ($type) {
            'math_number' => $this->number($this->field($block, 'NUM', '0')),
            'text' => $this->pythonString($this->field($block, 'TEXT', '')),
            'logic_boolean' => $this->field($block, 'BOOL', 'FALSE') === 'TRUE'
                ? 'True'
                : 'False',
            'logic_null' => 'None',
            'variables_get' => $this->variable($block, 'VAR'),
            'oj_input_int' => 'int(__oj_next())',
            'oj_input_float' => 'float(__oj_next())',
            'oj_input_token' => '__oj_next()',
            'oj_input_line' => '__oj_next_line()',
            'oj_split_ints' => 'list(map(int, str('
                . $this->requiredValue($block, 'TEXT') . ').split()))',
            'py_to_int' => 'int(' . $this->requiredValue($block, 'VALUE') . ')',
            'py_to_float' => 'float(' . $this->requiredValue($block, 'VALUE') . ')',
            'py_to_string' => 'str(' . $this->requiredValue($block, 'VALUE') . ')',
            'py_to_bool' => 'bool(' . $this->requiredValue($block, 'VALUE') . ')',
            'logic_compare' => $this->logicCompare($block),
            'logic_operation' => $this->logicOperation($block),
            'logic_negate' => '(not ' . $this->requiredValue($block, 'BOOL') . ')',
            'math_arithmetic' => $this->mathArithmetic($block),
            'math_modulo' => '(' . $this->requiredValue($block, 'DIVIDEND')
                . ' % ' . $this->requiredValue($block, 'DIVISOR') . ')',
            'math_single' => $this->mathSingle($block),
            'math_on_list' => $this->mathOnList($block),
            'math_number_property' => $this->numberProperty($block),
            'math_round' => $this->roundExpression($block),
            'math_trig' => $this->trigExpression($block),
            'math_constant' => $this->constantExpression($block),
            'math_constrain' => 'min(max('
                . $this->requiredValue($block, 'VALUE') . ', '
                . $this->requiredValue($block, 'LOW') . '), '
                . $this->requiredValue($block, 'HIGH') . ')',
            'text_join' => $this->textJoin($block),
            'text_length' => 'len(str('
                . $this->requiredValue($block, 'VALUE') . '))',
            'text_isEmpty' => '(len(str('
                . $this->requiredValue($block, 'VALUE') . ')) == 0)',
            'text_changeCase' => $this->changeCase($block),
            'text_trim' => $this->trimExpression($block),
            'py_string_get' => '(str(' . $this->requiredValue($block, 'TEXT')
                . '))[int(' . $this->requiredValue($block, 'INDEX') . ')]',
            'py_string_slice' => '(str(' . $this->requiredValue($block, 'TEXT')
                . '))[int(' . $this->requiredValue($block, 'START')
                . '):int(' . $this->requiredValue($block, 'END') . ')]',
            'py_string_split_ws' => '(str('
                . $this->requiredValue($block, 'TEXT') . ')).split()',
            'py_string_split' => '(str('
                . $this->requiredValue($block, 'TEXT') . ')).split(str('
                . $this->requiredValue($block, 'DELIMITER') . '))',
            'py_string_join' => 'str('
                . $this->requiredValue($block, 'DELIMITER')
                . ').join(map(str, ' . $this->requiredValue($block, 'LIST')
                . '))',
            'py_string_find' => '(str('
                . $this->requiredValue($block, 'TEXT') . ')).find(str('
                . $this->requiredValue($block, 'NEEDLE') . '))',
            'py_string_replace' => '(str('
                . $this->requiredValue($block, 'TEXT') . ')).replace(str('
                . $this->requiredValue($block, 'OLD') . '), str('
                . $this->requiredValue($block, 'NEW') . '))',
            'lists_create_with' => $this->listExpression($block),
            'lists_repeat' => '([' . $this->requiredValue($block, 'ITEM')
                . '] * int(' . $this->requiredValue($block, 'NUM') . '))',
            'lists_length', 'py_length' => 'len('
                . $this->requiredValue(
                    $block,
                    $type === 'lists_length' ? 'VALUE' : 'VALUE'
                ) . ')',
            'lists_isEmpty' => '(len('
                . $this->requiredValue($block, 'VALUE') . ') == 0)',
            'lists_reverse' => 'list(reversed('
                . $this->requiredValue($block, 'LIST') . '))',
            'py_list_get' => '(' . $this->requiredValue($block, 'LIST')
                . ')[int(' . $this->requiredValue($block, 'INDEX') . ')]',
            'py_list_slice' => '(' . $this->requiredValue($block, 'LIST')
                . ')[int(' . $this->requiredValue($block, 'START')
                . '):int(' . $this->requiredValue($block, 'END') . ')]',
            'py_list_find' => '(' . $this->requiredValue($block, 'LIST')
                . ').index(' . $this->requiredValue($block, 'VALUE') . ')',
            'py_list_sort' => 'sorted(' . $this->requiredValue($block, 'LIST')
                . ($this->field($block, 'ORDER', 'ASC') === 'DESC'
                    ? ', reverse=True'
                    : '')
                . ')',
            'py_tuple_from_list' => 'tuple('
                . $this->requiredValue($block, 'LIST') . ')',
            'py_tuple_get' => '(' . $this->requiredValue($block, 'TUPLE')
                . ')[int(' . $this->requiredValue($block, 'INDEX') . ')]',
            'py_dict_empty' => '{}',
            'py_dict_from_pairs' => 'dict('
                . $this->requiredValue($block, 'PAIRS') . ')',
            'py_dict_get' => '(' . $this->requiredValue($block, 'DICT')
                . ')[' . $this->requiredValue($block, 'KEY') . ']',
            'py_dict_get_default' => '('
                . $this->requiredValue($block, 'DICT') . ').get('
                . $this->requiredValue($block, 'KEY') . ', '
                . $this->requiredValue($block, 'DEFAULT') . ')',
            'py_dict_contains' => '(' . $this->requiredValue($block, 'KEY')
                . ' in ' . $this->requiredValue($block, 'DICT') . ')',
            'py_dict_view' => $this->dictionaryView($block),
            'py_set_empty' => 'set()',
            'py_set_from_list' => 'set('
                . $this->requiredValue($block, 'LIST') . ')',
            'py_set_contains' => '(' . $this->requiredValue($block, 'VALUE')
                . ' in ' . $this->requiredValue($block, 'SET') . ')',
            'py_set_operation' => $this->setOperation($block),
            'py_set_to_list' => 'list('
                . $this->requiredValue($block, 'SET') . ')',
            'procedures_callreturn' => $this->procedureCall($block),
            default => throw new DomainException(
                '값 위치에서 지원하지 않는 Blockly 블록입니다: ' . $type
            ),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function printStatement(array $block): string
    {
        $end = match ($this->field($block, 'END', 'NEWLINE')) {
            'SPACE' => ', end=" "',
            'NONE' => ', end=""',
            default => '',
        };

        return 'print(' . $this->requiredValue($block, 'VALUE') . $end . ')';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function repeatStatement(array $block, int $indent): string
    {
        $counter = $this->temporary('repeat');
        $body = $this->suite($block, 'DO', $indent + 1);

        return $this->indent($indent) . 'for ' . $counter . ' in range(max(0, int('
            . $this->requiredValue($block, 'TIMES') . '))):' . "\n" . $body;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function ifStatement(array $block, int $indent): string
    {
        $code = '';
        $index = 0;
        while ($this->inputBlock($block, 'IF' . $index) !== null) {
            $code .= $this->indent($indent)
                . ($index === 0 ? 'if ' : 'elif ')
                . $this->requiredValue($block, 'IF' . $index) . ":\n"
                . $this->suite($block, 'DO' . $index, $indent + 1);
            ++$index;
        }
        if ($index === 0) {
            throw new DomainException('조건문에 조건이 필요합니다.');
        }
        if ($this->inputBlock($block, 'ELSE') !== null) {
            $code .= $this->indent($indent) . "else:\n"
                . $this->suite($block, 'ELSE', $indent + 1);
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function whileStatement(array $block, int $indent): string
    {
        $condition = $this->requiredValue($block, 'BOOL');
        if ($this->field($block, 'MODE', 'WHILE') === 'UNTIL') {
            $condition = 'not (' . $condition . ')';
        }

        return $this->indent($indent) . 'while ' . $condition . ":\n"
            . $this->suite($block, 'DO', $indent + 1);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function forStatement(array $block, int $indent): string
    {
        $variable = $this->variable($block, 'VAR');
        $start = $this->temporary('from');
        $end = $this->temporary('to');
        $step = $this->temporary('step');
        $pad = $this->indent($indent);

        return $pad . $start . ' = int('
            . $this->requiredValue($block, 'FROM') . ")\n"
            . $pad . $end . ' = int('
            . $this->requiredValue($block, 'TO') . ")\n"
            . $pad . $step . ' = int('
            . $this->requiredValue($block, 'BY') . ")\n"
            . $pad . 'if ' . $step . " == 0:\n"
            . $this->indent($indent + 1)
            . "raise ValueError(\"반복 간격은 0일 수 없습니다.\")\n"
            . $pad . 'for ' . $variable . ' in range(' . $start . ', '
            . $end . ' + (1 if ' . $step . ' > 0 else -1), ' . $step
            . "):\n" . $this->suite($block, 'DO', $indent + 1);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function forEachStatement(array $block, int $indent): string
    {
        return $this->indent($indent) . 'for '
            . $this->variable($block, 'VAR') . ' in '
            . $this->requiredValue($block, 'LIST') . ":\n"
            . $this->suite($block, 'DO', $indent + 1);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function logicCompare(array $block): string
    {
        $operator = [
            'EQ' => '==',
            'NEQ' => '!=',
            'LT' => '<',
            'LTE' => '<=',
            'GT' => '>',
            'GTE' => '>=',
        ][$this->field($block, 'OP', 'EQ')] ?? '==';

        return '(' . $this->requiredValue($block, 'A') . ' '
            . $operator . ' ' . $this->requiredValue($block, 'B') . ')';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function logicOperation(array $block): string
    {
        $operator = $this->field($block, 'OP', 'AND') === 'OR'
            ? 'or'
            : 'and';

        return '(' . $this->requiredValue($block, 'A') . ' '
            . $operator . ' ' . $this->requiredValue($block, 'B') . ')';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function mathArithmetic(array $block): string
    {
        $operator = [
            'ADD' => '+',
            'MINUS' => '-',
            'MULTIPLY' => '*',
            'DIVIDE' => '/',
            'POWER' => '**',
        ][$this->field($block, 'OP', 'ADD')] ?? '+';

        return '(' . $this->requiredValue($block, 'A') . ' '
            . $operator . ' ' . $this->requiredValue($block, 'B') . ')';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function mathSingle(array $block): string
    {
        $value = $this->requiredValue($block, 'NUM');
        $operation = $this->field($block, 'OP', 'ROOT');

        return match ($operation) {
            'ABS' => 'abs(' . $value . ')',
            'NEG' => '(-(' . $value . '))',
            'ROOT' => $this->math('sqrt', $value),
            'LN' => $this->math('log', $value),
            'LOG10' => $this->math('log10', $value),
            'EXP' => $this->math('exp', $value),
            'POW10' => '(10 ** (' . $value . '))',
            'ROUND' => 'round(' . $value . ')',
            'ROUNDUP' => $this->math('ceil', $value),
            'ROUNDDOWN' => $this->math('floor', $value),
            default => throw new DomainException(
                '지원하지 않는 수학 단항 연산입니다.'
            ),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function mathOnList(array $block): string
    {
        $list = $this->requiredValue($block, 'LIST');

        return match ($this->field($block, 'OP', 'SUM')) {
            'SUM' => 'sum(' . $list . ')',
            'MIN' => 'min(' . $list . ')',
            'MAX' => 'max(' . $list . ')',
            'AVERAGE' => '(sum(' . $list . ') / len(' . $list . '))',
            default => throw new DomainException(
                '난수 또는 지원하지 않는 목록 수학 연산은 사용할 수 없습니다.'
            ),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function numberProperty(array $block): string
    {
        $number = $this->requiredValue($block, 'NUMBER_TO_CHECK');

        return match ($this->field($block, 'PROPERTY', 'EVEN')) {
            'EVEN' => '((' . $number . ') % 2 == 0)',
            'ODD' => '((' . $number . ') % 2 != 0)',
            'WHOLE' => '(float(' . $number . ').is_integer())',
            'POSITIVE' => '((' . $number . ') > 0)',
            'NEGATIVE' => '((' . $number . ') < 0)',
            'DIVISIBLE_BY' => '((' . $number . ') % ('
                . $this->requiredValue($block, 'DIVISOR') . ') == 0)',
            'PRIME' => $this->primeExpression($number),
            default => throw new DomainException(
                '지원하지 않는 수 속성입니다.'
            ),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function roundExpression(array $block): string
    {
        $number = $this->requiredValue($block, 'NUM');
        $places = 'int(' . $this->requiredValue($block, 'PLACES') . ')';
        $operation = $this->field($block, 'OP', 'ROUND');
        if ($operation === 'ROUND') {
            return 'round(' . $number . ', ' . $places . ')';
        }
        $this->needsMath = true;
        $function = $operation === 'ROUNDUP'
            ? 'math.ceil'
            : ($operation === 'ROUNDDOWN' ? 'math.floor' : null);
        if ($function === null) {
            throw new DomainException('반올림 종류가 올바르지 않습니다.');
        }
        $factor = '(10 ** (' . $places . '))';

        return '(' . $function . '((' . $number . ') * ' . $factor
            . ') / ' . $factor . ')';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function trigExpression(array $block): string
    {
        $this->needsMath = true;
        $value = $this->requiredValue($block, 'NUM');

        return match ($this->field($block, 'OP', 'SIN')) {
            'SIN' => 'math.sin(math.radians(' . $value . '))',
            'COS' => 'math.cos(math.radians(' . $value . '))',
            'TAN' => 'math.tan(math.radians(' . $value . '))',
            'ASIN' => 'math.degrees(math.asin(' . $value . '))',
            'ACOS' => 'math.degrees(math.acos(' . $value . '))',
            'ATAN' => 'math.degrees(math.atan(' . $value . '))',
            default => throw new DomainException(
                '삼각함수 종류가 올바르지 않습니다.'
            ),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function constantExpression(array $block): string
    {
        $this->needsMath = true;

        return match ($this->field($block, 'CONSTANT', 'PI')) {
            'PI' => 'math.pi',
            'E' => 'math.e',
            'GOLDEN_RATIO' => '((1 + math.sqrt(5)) / 2)',
            'SQRT2' => 'math.sqrt(2)',
            'SQRT1_2' => 'math.sqrt(0.5)',
            'INFINITY' => 'math.inf',
            default => throw new DomainException('수학 상수가 올바르지 않습니다.'),
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function textJoin(array $block): string
    {
        $parts = [];
        foreach ($this->indexedInputs($block, 'ADD') as $input) {
            $parts[] = 'str(' . $this->expression($input) . ')';
        }

        return $parts === [] ? '""' : '(' . implode(' + ', $parts) . ')';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function changeCase(array $block): string
    {
        $method = match ($this->field($block, 'CASE', 'UPPERCASE')) {
            'LOWERCASE' => 'lower',
            'TITLECASE' => 'title',
            default => 'upper',
        };

        return '(str(' . $this->requiredValue($block, 'TEXT')
            . ')).' . $method . '()';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function trimExpression(array $block): string
    {
        $method = match ($this->field($block, 'MODE', 'BOTH')) {
            'LEFT' => 'lstrip',
            'RIGHT' => 'rstrip',
            default => 'strip',
        };

        return '(str(' . $this->requiredValue($block, 'TEXT')
            . ')).' . $method . '()';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function listExpression(array $block): string
    {
        $items = [];
        foreach ($this->indexedInputs($block, 'ADD') as $input) {
            $items[] = $this->expression($input);
        }

        return '[' . implode(', ', $items) . ']';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function dictionaryView(array $block): string
    {
        $method = match ($this->field($block, 'VIEW', 'KEYS')) {
            'VALUES' => 'values',
            'ITEMS' => 'items',
            default => 'keys',
        };

        return 'list((' . $this->requiredValue($block, 'DICT')
            . ').' . $method . '())';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function setOperation(array $block): string
    {
        $operator = match ($this->field($block, 'OP', 'UNION')) {
            'INTERSECTION' => '&',
            'DIFFERENCE' => '-',
            default => '|',
        };

        return '((' . $this->requiredValue($block, 'LEFT') . ') '
            . $operator . ' (' . $this->requiredValue($block, 'RIGHT') . '))';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function procedureDefinition(array $block): string
    {
        $name = $this->procedureName(
            $this->field($block, 'NAME', 'function')
        );
        $params = $this->procedureParameters($block);
        $bodyBlock = $this->inputBlock($block, 'STACK');
        $body = $bodyBlock === null
            ? ''
            : $this->statementChain($bodyBlock, 1);
        if (($block['type'] ?? '') === 'procedures_defreturn') {
            $return = $this->inputBlock($block, 'RETURN');
            if ($return !== null) {
                $body .= $this->indent(1) . 'return '
                    . $this->expression($return) . "\n";
            }
        }
        if (trim($body) === '') {
            $body = $this->indent(1) . "pass\n";
        }

        return 'def ' . $name . '(' . implode(', ', $params) . "):\n"
            . $body;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function procedureCall(array $block): string
    {
        $name = $this->procedureName(
            $this->field(
                $block,
                'NAME',
                (string) ($block['extraState']['name'] ?? 'function')
            )
        );
        $arguments = [];
        foreach ($this->indexedInputs($block, 'ARG') as $input) {
            $arguments[] = $this->expression($input);
        }

        return $name . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function procedureIfReturn(array $block): string
    {
        $condition = $this->requiredValue($block, 'CONDITION');
        $value = $this->inputBlock($block, 'VALUE');

        return 'if ' . $condition . ': '
            . ($value === null ? 'return' : 'return ' . $this->expression($value));
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private function procedureParameters(array $block): array
    {
        $params = $block['extraState']['params'] ?? [];
        if (! is_array($params)) {
            return [];
        }
        $result = [];
        foreach ($params as $index => $param) {
            $name = is_array($param)
                ? (string) ($param['name'] ?? 'arg' . $index)
                : (string) $param;
            $id = is_array($param)
                ? (string) ($param['id'] ?? '')
                : '';
            if ($id !== '' && isset($this->variables[$id])) {
                $result[] = $this->variables[$id];
                continue;
            }
            $identifier = $this->identifier($name, 'arg' . $index);
            if ($id !== '') {
                $this->variables[$id] = $identifier;
            }
            $result[] = $identifier;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function flowStatement(array $block): string
    {
        return $this->field($block, 'FLOW', 'BREAK') === 'CONTINUE'
            ? 'continue'
            : 'break';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function suite(array $block, string $name, int $indent): string
    {
        $child = $this->inputBlock($block, $name);

        return $child === null
            ? $this->indent($indent) . "pass\n"
            : $this->statementChain($child, $indent);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function requiredValue(array $block, string $name): string
    {
        $input = $this->inputBlock($block, $name);
        if ($input === null) {
            throw new DomainException(
                $block['type'] . ' 블록의 ' . $name . ' 입력이 필요합니다.'
            );
        }

        return $this->expression($input);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private function inputBlock(array $block, string $name): ?array
    {
        $input = $block['inputs'][$name] ?? null;
        if (! is_array($input)) {
            return null;
        }
        $child = $input['block'] ?? $input['shadow'] ?? null;

        return is_array($child) ? $child : null;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<array<string, mixed>>
     */
    private function indexedInputs(array $block, string $prefix): array
    {
        $result = [];
        for ($index = 0; $index < 100; ++$index) {
            $input = $this->inputBlock($block, $prefix . $index);
            if ($input === null) {
                break;
            }
            $result[] = $input;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function field(
        array $block,
        string $name,
        string $default = ''
    ): string {
        $value = $block['fields'][$name] ?? $default;
        if (is_array($value)) {
            foreach (['value', 'name', 'id'] as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    return (string) $value[$key];
                }
            }

            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function variable(array $block, string $field): string
    {
        $value = $block['fields'][$field] ?? '';
        $id = is_array($value)
            ? (string) ($value['id'] ?? $value['value'] ?? '')
            : (string) $value;
        if (isset($this->variables[$id])) {
            return $this->variables[$id];
        }
        $name = is_array($value)
            ? (string) ($value['name'] ?? $id)
            : (string) $value;

        $key = $id === '' ? 'name:' . $name : $id;
        $this->variables[$key] = $this->identifier($name, 'value');

        return $this->variables[$key];
    }

    /**
     * @param list<mixed> $variables
     */
    private function indexVariables(array $variables): void
    {
        foreach ($variables as $index => $variable) {
            if (! is_array($variable)) {
                continue;
            }
            $id = (string) ($variable['id'] ?? '');
            $name = (string) ($variable['name'] ?? 'value' . $index);
            if ($id !== '') {
                $this->variables[$id] = $this->identifier(
                    $name,
                    'value' . $index
                );
            }
        }
    }

    private function identifier(string $value, string $fallback): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($value)) ?? '';
        if ($value === '' || preg_match('/^[0-9]/', $value) === 1) {
            $value = $fallback . ($value === '' ? '' : '_' . $value);
        }
        $reserved = [
            'False', 'None', 'True', 'and', 'as', 'assert', 'async',
            'await', 'break', 'class', 'continue', 'def', 'del', 'elif',
            'else', 'except', 'finally', 'for', 'from', 'global', 'if',
            'import', 'in', 'is', 'lambda', 'nonlocal', 'not', 'or',
            'pass', 'raise', 'return', 'try', 'while', 'with', 'yield',
        ];
        if (in_array($value, $reserved, true) || str_starts_with($value, '__oj_')) {
            $value = 'value_' . $value;
        }
        $base = $value;
        $suffix = 2;
        while (isset($this->usedIdentifiers[$value])) {
            $value = $base . '_' . $suffix++;
        }
        $this->usedIdentifiers[$value] = true;

        return $value;
    }

    private function temporary(string $label): string
    {
        ++$this->temporarySequence;

        return '__oj_' . $label . '_' . $this->temporarySequence;
    }

    private function procedureName(string $rawName): string
    {
        if (! isset($this->procedures[$rawName])) {
            $this->procedures[$rawName] = $this->identifier(
                $rawName,
                'function'
            );
        }

        return $this->procedures[$rawName];
    }

    private function number(string $value): string
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new DomainException('Blockly 숫자 값이 올바르지 않습니다.');
        }

        return str_contains(strtolower($value), 'e')
            || str_contains($value, '.')
                ? (string) (float) $value
                : (string) (int) $value;
    }

    private function pythonString(string $value): string
    {
        return (string) json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );
    }

    private function math(string $function, string $argument): string
    {
        $this->needsMath = true;

        return 'math.' . $function . '(' . $argument . ')';
    }

    private function primeExpression(string $number): string
    {
        $this->needsPrime = true;

        return '__oj_is_prime(int(' . $number . '))';
    }

    private function indent(int $level): string
    {
        return str_repeat('    ', max(0, $level));
    }

    private function inputPrelude(): string
    {
        return <<<'PYTHON'
import sys

class __OjInput:
    def __init__(self, text):
        self.text = text
        self.position = 0

    def next(self):
        size = len(self.text)
        while self.position < size and self.text[self.position].isspace():
            self.position += 1
        start = self.position
        while self.position < size and not self.text[self.position].isspace():
            self.position += 1
        return self.text[start:self.position]

    def line(self):
        size = len(self.text)
        while self.position < size and self.text[self.position] in "\r\n":
            self.position += 1
        start = self.position
        while self.position < size and self.text[self.position] not in "\r\n":
            self.position += 1
        return self.text[start:self.position].lstrip(" \t")

__oj_reader = __OjInput(sys.stdin.read())

def __oj_next():
    return __oj_reader.next()

def __oj_next_line():
    return __oj_reader.line()
PYTHON;
    }

    private function primeHelper(): string
    {
        return <<<'PYTHON'
def __oj_is_prime(value):
    if value < 2:
        return False
    divisor = 2
    while divisor * divisor <= value:
        if value % divisor == 0:
            return False
        divisor += 1
    return True
PYTHON;
    }
}
