<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

final class LessonQuality
{
    public static function baselineCases(array $lesson): array
    {
        $cases = [];
        foreach ($lesson['stages'][3]['tests'] as $index => $test) {
            $cases[] = ['case_id' => 'baseline:' . $index, 'phase' => 'modify', 'language' => $lesson['language'],
                'source' => $lesson['demo']['code'], 'input' => $test['input'], 'expected_output' => $test['expected_output']];
        }
        return $cases;
    }
    public static function assess(array $lesson, array $profile, array $baseline): array
    {
        $reasons = []; $source = $lesson['demo']['code'] . "\n" . $lesson['stages'][3]['starter_code'];
        if (preg_match('/\b(?:eval|exec|compile|__import__|open|system|popen|fopen|freopen|socket|fork|ProcessBuilder|Runtime\.getRuntime)\s*\(|\b(?:import|from)\s+(?:os|subprocess|socket|requests|urllib|pathlib)\b|#include\s*[<"](?:fstream|filesystem|unistd\.h|sys\/socket\.h)[>"]/u', $source) === 1) {
            $reasons[] = '파일·네트워크·프로세스·동적 실행 등 수업 범위를 벗어난 코드가 있어 교사 검토가 필요합니다.';
        }
        $lines = substr_count($source, "\n"); $limit = ($profile['level'] ?? 'beginner') === 'beginner' ? 50 : 100;
        if ($lines > $limit || (($profile['minutes'] ?? 45) <= 30 && $lines > 30)) { $reasons[] = '예제 길이가 학급 수준 또는 수업 시간에 비해 길어 검토가 필요합니다.'; }
        $contrasts = 0; $normal = 0;
        foreach ($baseline as $result) {
            if (($result['verdict'] ?? '') === 'wrong_answer') { ++$contrasts; }
            if (in_array($result['verdict'] ?? '', ['accepted', 'wrong_answer'], true)) { ++$normal; }
        }
        if ($baseline === [] || $contrasts === 0) { $reasons[] = '기존 예제가 수정 과제의 시험 사례도 통과하여 수정 필요성이 충분히 드러나지 않습니다.'; }
        if ($normal !== count($baseline)) { $reasons[] = '기존 코드의 실행 오류 때문에 수정 과제와의 행동 차이를 명확히 비교하기 어렵습니다.'; }
        if (count($lesson['stages'][3]['tests']) < 2) { $reasons[] = '수정 과제에 정상 사례와 경계 사례를 모두 추가하는 것이 좋습니다.'; }
        return ['status' => $reasons === [] ? 'passed' : 'needs_review', 'reasons' => $reasons,
            'baseline_case_count' => count($baseline), 'baseline_failures' => $contrasts,
            'semantic_note' => '검사는 제공된 실행 사례와 자료 구조의 일관성을 확인합니다. 과제 의미와 수업 적합성은 교사가 검토할 수 있습니다.'];
    }
}
