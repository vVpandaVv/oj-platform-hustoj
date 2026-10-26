<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

use DomainException;
use JsonException;

final class LessonSchema
{
    public const NOTE = 'OJ 자동 실행 검증 전인 AI 수업 초안입니다.';
    public const LANGUAGES = ['python', 'c', 'cpp', 'java'];
    public const KINDS = ['none', 'code_ordering', 'code_completion'];
    private const KEYS = ['predict', 'run', 'investigate', 'modify', 'make'];

    /** Parse a provider JSON map (the normal API result), or an explicit text wrapper. */
    public static function normalize(array|string $output, string $language, string $supportKind): array
    {
        return self::lesson(self::decode($output), $language, $supportKind, false);
    }

    /** A second server-side check preserves IDs already assigned by normalize(). */
    public static function validateNormalized(array $lesson, string $language, string $supportKind): array
    {
        return self::lesson($lesson, $language, $supportKind, true);
    }

    private static function decode(array|string $output): array
    {
        if (is_array($output) && count($output) === 1) {
            foreach (['content', 'text'] as $key) {
                if (array_key_exists($key, $output) && is_string($output[$key])) { $output = $output[$key]; break; }
            }
        }
        if (is_string($output)) {
            $output = self::text($output, 262144, 'AI JSON 응답');
            $trimmed = trim($output);
            if (preg_match('/\A```(?:json)?[ \t]*\r?\n([\s\S]*)\r?\n```\s*\z/D', $trimmed, $match) === 1) { $trimmed = $match[1]; }
            try { $output = json_decode($trimmed, true, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException $error) { throw new DomainException('AI 수업 응답은 하나의 올바른 JSON 객체여야 합니다.', 0, $error); }
        }
        if (! is_array($output) || array_is_list($output)) { throw new DomainException('AI 수업 응답은 JSON 객체여야 합니다.'); }
        self::boundedJson($output);
        return $output;
    }

    private static function lesson(array $raw, string $language, string $kind, bool $normalized): array
    {
        if (! in_array($language, self::LANGUAGES, true) || ! in_array($kind, self::KINDS, true)) { throw new DomainException('수업 언어와 도움 방식을 확인해 주세요.'); }
        self::boundedJson($raw);
        self::fields($raw, ['schema_version', 'language', 'title', 'objectives', 'demo', 'stages', 'support', 'verification']);
        if ($raw['schema_version'] !== 1 || $raw['language'] !== $language) { throw new DomainException('AI 수업의 버전 또는 언어가 요청과 일치하지 않습니다.'); }
        $demo = self::map($raw['demo'], '도입 예제');
        self::fields($demo, ['code', 'input', 'expected_output', 'explanation']);
        $demo = ['code' => self::code($demo['code'], '도입 예제 코드'),
            'input' => self::text($demo['input'], 4096, '도입 입력', true),
            'expected_output' => self::text($demo['expected_output'], 4096, '예상 출력', true),
            'explanation' => self::text($demo['explanation'], 3000, '예제 설명')];
        if (! is_array($raw['stages']) || ! array_is_list($raw['stages']) || count($raw['stages']) !== 5) { throw new DomainException('PRIMM 다섯 단계가 모두 필요합니다.'); }
        $stages = [];
        $questions = [];
        $prompts = [];
        foreach ($raw['stages'] as $index => $stage) {
            $stage = self::map($stage, '단계');
            $key = self::KEYS[$index];
            self::fields($stage, ['key', 'label', 'prompt', 'questions'], $key === 'modify' ? ['task', 'starter_code', 'tests', 'hints'] : ($key === 'make' ? ['task', 'hints'] : ['hints']));
            if ($stage['key'] !== $key) { throw new DomainException('PRIMM 단계의 종류와 순서를 확인해 주세요.'); }
            $prompt = self::text($stage['prompt'], 3000, $key . ' 안내');
            $canonicalPrompt = preg_replace('/\s+/u', ' ', trim($prompt));
            if (isset($prompts[$canonicalPrompt])) { throw new DomainException('단계마다 서로 다른 구체적인 안내가 필요합니다.'); }
            $prompts[$canonicalPrompt] = true;
            $items = self::strings($stage['questions'], 1, 5, 1200, $key . ' 질문');
            foreach ($items as $question) {
                $canonical = preg_replace('/\s+/u', ' ', trim($question));
                if (isset($questions[$canonical])) { throw new DomainException('같은 질문을 여러 단계에 반복할 수 없습니다.'); }
                $questions[$canonical] = true;
            }
            $item = ['key' => $key, 'label' => self::text($stage['label'], 80, '단계 이름'), 'prompt' => $prompt, 'questions' => $items];
            if (array_key_exists('hints', $stage)) { $item['hints'] = self::strings($stage['hints'], 0, 4, 1000, '단계 힌트'); }
            if ($key === 'modify') {
                foreach (['task', 'starter_code', 'tests'] as $required) { if (! array_key_exists($required, $stage)) { throw new DomainException('수정 단계에는 구체적인 과제·코드·시험 사례가 필요합니다.'); } }
                $item['task'] = self::text($stage['task'], 3000, '수정 과제');
                $item['starter_code'] = self::code($stage['starter_code'], '수정 활동 코드');
                if (! is_array($stage['tests']) || ! array_is_list($stage['tests']) || count($stage['tests']) < 1 || count($stage['tests']) > 8) { throw new DomainException('수정 과제 시험 사례는 1개부터 8개까지 필요합니다.'); }
                $item['tests'] = [];
                foreach ($stage['tests'] as $test) {
                    $test = self::map($test, '시험 사례'); self::fields($test, ['input', 'expected_output']);
                    $item['tests'][] = ['input' => self::text($test['input'], 4096, '시험 입력', true), 'expected_output' => self::text($test['expected_output'], 4096, '시험 예상 출력', true)];
                }
            } elseif ($key === 'make' && array_key_exists('task', $stage)) { $item['task'] = self::text($stage['task'], 3000, '제작 과제'); }
            $stages[] = $item;
        }
        $support = self::map($raw['support'], '도움 활동');
        $support = $normalized ? LessonSupport::validateNormalized($support, $stages[3]['starter_code'], $kind)
            : LessonSupport::normalize($support, $stages[3]['starter_code'], $kind);
        $verification = self::map($raw['verification'], '검증 상태');
        self::fields($verification, ['status', 'note']);
        if ($verification['status'] !== 'not_executed') { throw new DomainException('AI 응답을 실행 검증된 수업으로 표시할 수 없습니다.'); }
        self::text($verification['note'], 2000, '검증 설명', true);
        $lesson = ['schema_version' => 1, 'language' => $language, 'title' => self::text($raw['title'], 191, '수업 제목'),
            'objectives' => self::strings($raw['objectives'], 1, 6, 500, '학습 목표'), 'demo' => $demo,
            'stages' => $stages, 'support' => $support, 'verification' => ['status' => 'not_executed', 'note' => self::NOTE]];
        self::boundedJson($lesson);
        return $lesson;
    }

    public static function fields(array $map, array $required, array $optional = []): void
    {
        if (array_is_list($map) || array_diff($required, array_keys($map)) !== [] || array_diff(array_keys($map), [...$required, ...$optional]) !== []) { throw new DomainException('수업 자료에 누락되었거나 허용되지 않은 필드가 있습니다.'); }
    }
    public static function map(mixed $value, string $label): array
    {
        if (! is_array($value) || array_is_list($value)) { throw new DomainException($label . '는 객체 형식이어야 합니다.'); }
        return $value;
    }
    public static function text(mixed $value, int $maximum, string $label, bool $empty = false): string
    {
        if (! is_string($value) || strlen($value) > $maximum || str_contains($value, "\0") || preg_match('//u', $value) !== 1
            || (! $empty && trim($value) === '')) { throw new DomainException($label . '의 형식과 길이를 확인해 주세요.'); }
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
    public static function code(mixed $value, string $label): string
    {
        $value = self::text($value, 16384, $label);
        if (preg_match('/\A```(?:python|c|cpp|java)?[ \t]*\n([\s\S]*)\n```\s*\z/D', trim($value), $match) === 1) { $value = $match[1]; }
        self::text($value, 16384, $label);
        $value = str_ends_with($value, "\n") ? $value : $value . "\n";
        if (strlen($value) > 16384) { throw new DomainException($label . '는 마지막 줄바꿈을 포함해 16KB 이하여야 합니다.'); }
        return $value;
    }
    private static function strings(mixed $value, int $minimum, int $maximum, int $bytes, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $minimum || count($value) > $maximum) { throw new DomainException($label . ' 개수를 확인해 주세요.'); }
        return array_map(static fn ($item): string => self::text($item, $bytes, $label), $value);
    }
    private static function boundedJson(array $value): void
    {
        try { $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        catch (JsonException $error) { throw new DomainException('AI 자료를 UTF-8 JSON으로 읽을 수 없습니다.', 0, $error); }
        if (strlen($encoded) > 262144) { throw new DomainException('AI 수업 자료가 256KB를 초과합니다.'); }
    }
}
