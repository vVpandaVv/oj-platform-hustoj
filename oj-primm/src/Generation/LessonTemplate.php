<?php
declare(strict_types=1);
namespace OjPrimm\Generation;

/** Small deterministic fallback; teacher review establishes its relation to the target problem. */
final class LessonTemplate
{
    public static function create(string $language, string $kind): array
    {
        [$demo, $modify] = match ($language) {
            'python' => ["a = 2\nb = 5\nprint(a + b)\n", "a, b = map(int, input().split())\ntotal = 0\nfor value in [a, b]:\n    if value > 0:\n        total += value\nprint(total)\n"],
            'c' => ["#include <stdio.h>\nint main(void) {\n    int a = 2, b = 5;\n    printf(\"%d\\n\", a + b);\n    return 0;\n}\n", "#include <stdio.h>\nint main(void) {\n    int a, b, total = 0;\n    if (scanf(\"%d %d\", &a, &b) != 2) return 1;\n    if (a > 0) total += a;\n    if (b > 0) total += b;\n    printf(\"%d\\n\", total);\n    return 0;\n}\n"],
            'cpp' => ["#include <iostream>\nint main() {\n    int a = 2, b = 5;\n    std::cout << a + b << '\\n';\n    return 0;\n}\n", "#include <iostream>\nint main() {\n    int a, b, total = 0;\n    if (!(std::cin >> a >> b)) return 1;\n    if (a > 0) total += a;\n    if (b > 0) total += b;\n    std::cout << total << '\\n';\n    return 0;\n}\n"],
            'java' => ["public class Main {\n    public static void main(String[] args) {\n        int a = 2, b = 5;\n        System.out.println(a + b);\n    }\n}\n", "import java.util.Scanner;\npublic class Main {\n    public static void main(String[] args) {\n        Scanner input = new Scanner(System.in);\n        int a = input.nextInt(), b = input.nextInt(), total = 0;\n        if (a > 0) total += a;\n        if (b > 0) total += b;\n        System.out.println(total);\n    }\n}\n"],
            default => throw new \DomainException('안전한 예제의 언어를 확인해 주세요.'),
        };
        $stages = [
            ['key' => 'predict', 'label' => '예측', 'prompt' => '두 변수의 값을 읽고 출력 결과를 먼저 예상해 보세요.', 'questions' => ['a와 b를 더한 값은 무엇이며 왜 그렇게 예상했나요?']],
            ['key' => 'run', 'label' => '실행 비교', 'prompt' => '예측과 실행 결과를 비교하고 차이를 설명하세요.', 'questions' => ['예측한 결과와 실제 결과가 같았나요? 다른 경우 어떤 계산을 다시 살펴봤나요?']],
            ['key' => 'investigate', 'label' => '탐구', 'prompt' => '입력값과 계산식, 출력의 역할을 구별해 보세요.', 'questions' => ['a가 0이면 출력은 어떻게 바뀌나요?', '짝에게 변수에 저장한 값과 출력하는 계산식의 관계를 설명해 보세요.']],
            ['key' => 'modify', 'label' => '수정', 'prompt' => '입력한 두 수에서 양수만 더하도록 예제를 바꾸세요.', 'questions' => ['0과 음수를 합에서 제외하려면 어떤 조건이 필요한가요?'],
                'task' => '한 줄로 주어지는 -100부터 100까지의 정수 두 개 중 양수인 수만 더해 출력하세요. 0은 더하지 않습니다.',
                'starter_code' => $modify, 'tests' => [['input' => "2 5\n", 'expected_output' => "7\n"], ['input' => "0 -3\n", 'expected_output' => "0\n"]],
                'hints' => ['더하기 전에 0보다 큰지 확인하세요.']],
            ['key' => 'make', 'label' => '제작', 'prompt' => '이제 원래 OJ 문제의 입력·출력 조건을 분석하고 스스로 해결하세요.', 'questions' => ['원래 문제에서 어떤 조건과 경계값을 확인해야 하나요?'], 'task' => '원문 요구사항에 맞는 코드를 작성하고 직접 고른 시험 사례로 확인한 뒤 OJ에 제출하세요.'],
        ];
        $support = ['kind' => $kind];
        if ($kind === 'code_ordering') { $support['ordering_blocks'] = array_map(static fn (string $line): string => $line . "\n", explode("\n", rtrim($modify, "\n"))); }
        if ($kind === 'code_completion') {
            $offset = strpos($modify, '> 0');
            if ($offset === false) { throw new \DomainException('안전한 빈칸 예제를 만들 수 없습니다.'); }
            $support += ['template' => substr_replace($modify, '{{OJ_BLANK:threshold}}', $offset + 2, 1),
                'blanks' => [['id' => 'threshold', 'label' => '양수의 기준', 'kind' => 'inline', 'max_bytes' => 8, 'answer' => '0']]];
        }
        return LessonSchema::normalize(['schema_version' => 1, 'language' => $language, 'title' => '합계와 조건을 살펴보는 PRIMM',
            'objectives' => ['변수의 값을 읽고 출력을 예측한다.', '조건에 따라 계산에 포함할 수를 고른다.'],
            'demo' => ['code' => $demo, 'input' => '', 'expected_output' => "7\n", 'explanation' => '2와 5를 더한 값 7을 출력한다.'],
            'stages' => $stages, 'support' => $support, 'verification' => ['status' => 'not_executed', 'note' => LessonSchema::NOTE]], $language, $kind);
    }
}
