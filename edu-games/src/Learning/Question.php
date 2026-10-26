<?php
declare(strict_types=1);

namespace EduGames\Learning;

final class Question {
    public static function make(string $id, string $skill, string $level, string $prompt, array $data, array $fields, array $expected, string $explanation, string $hint, array $visual = array()): array {
        return compact('id', 'skill', 'level', 'prompt', 'data', 'fields', 'expected', 'explanation', 'hint', 'visual') + array('contentVersion'=>Catalog::VERSION,'rulesVersion'=>'learning-1','rubricVersion'=>'first-answer-1','conceptTags'=>array($skill));
    }

    public static function field(string $key, string $label, string $type = 'number', array $options = array()): array {
        return compact('key', 'label', 'type', 'options');
    }

    public static function public_view(array $question): array {
        return array_intersect_key($question, array_flip(array('id', 'skill', 'level', 'prompt', 'data', 'fields', 'visual')));
    }

    public static function grade(array $question, array $answer): array {
        $checks = array();
        foreach ($question['fields'] as $field) {
            $key = $field['key'];
            $expected = $question['expected'][$key];
            $actual = $answer[$key] ?? null;
            $ok = false;
            if ('number' === $field['type']) {
                $ok = (is_int($actual) || is_float($actual)) && is_finite((float) $actual) && abs($actual - $expected) < 0.00001;
            } elseif ('multi' === $field['type']) {
                if (is_array($actual) && array_is_list($actual) && count(array_filter($actual, 'is_string')) === count($actual) && count(array_unique($actual)) === count($actual)) {
                    sort($actual); sort($expected); $ok = $actual === $expected;
                }
            } elseif ('choice' === $field['type']) {
                $ok = is_string($actual) && in_array($actual, (array) $expected, true);
            } elseif ('tokens' === $field['type']) {
                $ok = is_array($actual) && array_is_list($actual) && $actual === $expected;
            } else {
                $ok = is_string($actual) && trim($actual) === $expected;
            }
            $checks[] = array('key' => $key, 'label' => $field['label'], 'correct' => $ok, 'expected' => $expected);
        }
        $correct = ! in_array(false, array_column($checks, 'correct'), true);
        return array('correct' => $correct, 'checks' => $checks, 'explanation' => $question['explanation']);
    }
}
