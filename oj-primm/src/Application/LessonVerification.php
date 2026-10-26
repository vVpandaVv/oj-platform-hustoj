<?php
declare(strict_types=1);
namespace OjPrimm\Application;

use DomainException;

/** Server-owned execution evidence. AI output can never supply this state. */
final class LessonVerification
{
    public static function cases(array $lesson): array
    {
        $cases = [['case_id' => 'demo', 'phase' => 'demo', 'language' => $lesson['language'], 'source' => $lesson['demo']['code'],
            'input' => $lesson['demo']['input'], 'expected_output' => $lesson['demo']['expected_output']]];
        foreach ($lesson['stages'][3]['tests'] as $index => $test) {
            $cases[] = ['case_id' => 'modify:' . $index, 'phase' => 'modify', 'language' => $lesson['language'],
                'source' => $lesson['stages'][3]['starter_code'], 'input' => $test['input'], 'expected_output' => $test['expected_output']];
        }
        return $cases;
    }

    public static function lessonHash(array $lesson): string
    {
        return hash('sha256', json_encode([$lesson['language'], self::cases($lesson)], JSON_THROW_ON_ERROR));
    }

    public static function compare(array $result, array $case): array
    {
        if (($result['status'] ?? '') !== 'completed' || ! is_string($result['actual_output'] ?? null)
            || ! in_array($result['verdict'] ?? null, ['executed', 'compile_error', 'runtime_error', 'time_limit', 'memory_limit', 'output_limit', 'output_incomplete'], true)) {
            throw new DomainException('OJ 실행 결과를 확인하지 못했습니다. 채점기 연결을 확인한 뒤 다시 시도해 주세요.');
        }
        $verdict = $result['verdict'];
        if ($verdict === 'executed') {
            // Normalize transport newlines only. Whitespace, blank lines and trailing LF are meaningful.
            $verdict = self::newlines($result['actual_output']) === self::newlines($case['expected_output']) ? 'accepted' : 'wrong_answer';
        }
        $diagnostics = self::diagnostic($result['diagnostics'] ?? '');
        return ['case_id' => $case['case_id'], 'phase' => $case['phase'], 'verdict' => $verdict,
            'actual_output' => self::diagnostic($result['actual_output']), 'diagnostics' => $diagnostics,
            'case_hash' => hash('sha256', json_encode($case, JSON_THROW_ON_ERROR)),
            'checked_at' => gmdate('Y-m-d H:i:s')];
    }

    public static function feedback(array $lesson, array $results, int $attempt): array
    {
        $failures = [];
        foreach (self::cases($lesson) as $case) {
            $result = $results[$case['case_id']] ?? null;
            if (! is_array($result)) { throw new DomainException('모든 예제의 OJ 실행 결과가 필요합니다.'); }
            self::assertCase($case, $result);
            if ($result['verdict'] === 'accepted') { continue; }
            $failure = array_intersect_key($case, array_flip(['case_id', 'phase', 'input', 'expected_output']));
            $failure += ['actual_output' => $result['actual_output'], 'verdict' => $result['verdict']];
            $failure[$result['verdict'] === 'compile_error' ? 'compiler_output' : 'runtime_error'] = $result['diagnostics'];
            $failures[] = $failure;
        }
        return ['attempt' => $attempt, 'failures' => $failures];
    }

    public static function metadata(array $lesson, array $results, int $repairs): array
    {
        if (self::feedback($lesson, $results, $repairs + 1)['failures'] !== []) { throw new DomainException('OJ 검증에 실패한 자료는 배포할 수 없습니다.'); }
        return ['status' => 'verified', 'engine' => 'oj', 'checked_at' => gmdate('Y-m-d H:i:s'),
            'case_count' => count(self::cases($lesson)), 'repair_attempts' => $repairs, 'source_hash' => self::lessonHash($lesson),
            'note' => '도입 예제와 수정 과제의 기준 코드를 OJ에서 실행해 제공된 모든 시험 사례의 예상 출력과 일치함을 확인했습니다.'];
    }

    private static function assertCase(array $case, array $result): void
    {
        if (! is_string($result['case_hash'] ?? null)
            || ! hash_equals(hash('sha256', json_encode($case, JSON_THROW_ON_ERROR)), $result['case_hash'])
            || ! in_array($result['verdict'] ?? null, ['accepted', 'wrong_answer', 'compile_error', 'runtime_error', 'time_limit', 'memory_limit', 'output_limit', 'output_incomplete'], true)) {
            throw new DomainException('검증한 예제 코드·입출력이 변경되었습니다. 새 검증이 필요합니다.');
        }
    }
    private static function newlines(string $value): string { return str_replace(["\r\n", "\r"], "\n", $value); }
    private static function diagnostic(mixed $value): string
    {
        if (! is_string($value) || preg_match('//u', $value) !== 1) { return '출력을 UTF-8로 읽지 못했습니다.'; }
        $value = str_replace("\0", '', $value);
        if (strlen($value) <= 16384) { return $value; }
        if (function_exists('mb_strcut')) { return mb_strcut($value, 0, 16384, 'UTF-8'); }
        $value = substr($value, 0, 16384);
        while (preg_match('//u', $value) !== 1) { $value = substr($value, 0, -1); }
        return $value;
    }
}
