<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Data_Structure_Lab;

use EduGames\Games\Contracts\Game_Module;

final class Data_Structure_Lab_Module implements Game_Module {
    public const ID = 'data-structure-lab';
    public const SHORTCODE = 'data_structure_lab';
    public const BLOCK = 'edu-games/data-structure-lab';

    public function id(): string {
        return self::ID;
    }

    public function definition(): array {
        return array(
            'id' => $this->id(),
            'title' => __('자료구조 운용', 'edu-games'),
            'category' => 'algorithms',
            'description' => __('물건을 스택에 쌓고 큐에 줄 세워 주문 순서대로 배송합니다. 제한된 임시 보관 공간에서 재배치를 줄이며 기록에 도전합니다.', 'edu-games'),
            'status' => 'playable',
            'statusLabel' => __('플레이 가능', 'edu-games'),
            'block' => self::BLOCK,
            'shortcode' => self::SHORTCODE,
            'rules' => self::rules(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array {
        return array(
            'rankedRounds' => 10,
            'defaultDifficulty' => 'easy',
            'structures' => array(
                'stack' => __('스택', 'edu-games'),
                'queue' => __('큐', 'edu-games'),
                'priority-queue' => __('우선순위 큐', 'edu-games'),
            ),
            'difficulties' => array(
                'easy' => array(
                    'label' => __('기초', 'edu-games'),
                    'description' => __('명령 수가 적고 출력 순서가 분명합니다.', 'edu-games'),
                    'baseScore' => 100,
                    'samples' => array('stack-basic-pop', 'queue-basic-dequeue', 'stack-peek', 'queue-front', 'priority-basic', 'stack-pop-after-peek', 'queue-double-dequeue', 'stack-empty-result', 'priority-number-order', 'queue-front-after-add'),
                ),
                'normal' => array(
                    'label' => __('표준', 'edu-games'),
                    'description' => __('삽입과 삭제가 섞인 명령을 순서대로 추적합니다.', 'edu-games'),
                    'baseScore' => 135,
                    'samples' => array('stack-mixed-output', 'queue-mixed-state', 'priority-mixed', 'stack-final-state', 'queue-output-chain', 'stack-number-output', 'queue-service-line', 'priority-insert-between', 'stack-undo-history', 'queue-final-after-mix'),
                ),
                'hard' => array(
                    'label' => __('심화', 'edu-games'),
                    'description' => __('여러 번의 출력과 최종 상태를 함께 판단합니다.', 'edu-games'),
                    'baseScore' => 175,
                    'samples' => array('priority-tie', 'stack-parenthesis', 'queue-task-order', 'priority-final-state', 'stack-queue-compare', 'priority-triple-pop-tie', 'stack-bracket-error', 'queue-buffer-flow', 'priority-state-after-tie', 'stack-nested-output'),
                ),
            ),
            'scoring' => array(
                'streakBonus' => 10,
                'maxStreakBonus' => 70,
                'wrongPenalty' => 25,
                'minimumCorrectScore' => 45,
            ),
            'samples' => self::samples(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function samples(): array {
        return array(
            self::sample(
                'stack-basic-pop',
                __('스택: 마지막에 넣은 값', 'edu-games'),
                'stack',
                __('스택은 나중에 들어온 값이 먼저 나갑니다. 다음 명령 뒤 pop의 출력은 무엇일까요?', 'edu-games'),
                array('push A', 'push B', 'pop'),
                array('A', 'B', 'A, B', '비어 있음'),
                'B',
                __('스택은 LIFO 구조이므로 마지막으로 들어온 B가 먼저 나옵니다.', 'edu-games'),
                'easy'
            ),
            self::sample(
                'queue-basic-dequeue',
                __('큐: 먼저 들어온 값', 'edu-games'),
                'queue',
                __('큐는 먼저 들어온 값이 먼저 나갑니다. dequeue의 출력은 무엇일까요?', 'edu-games'),
                array('enqueue A', 'enqueue B', 'dequeue'),
                array('A', 'B', 'A, B', '비어 있음'),
                'A',
                __('큐는 FIFO 구조이므로 먼저 들어온 A가 먼저 나옵니다.', 'edu-games'),
                'easy'
            ),
            self::sample(
                'stack-peek',
                __('스택: top 확인', 'edu-games'),
                'stack',
                __('peek은 값을 꺼내지 않고 맨 위 값을 확인합니다. 마지막 peek의 결과는 무엇일까요?', 'edu-games'),
                array('push 3', 'push 5', 'peek', 'push 7', 'peek'),
                array('3', '5', '7', '5, 7'),
                '7',
                __('peek은 삭제하지 않습니다. 7을 넣은 뒤 맨 위 값은 7입니다.', 'edu-games'),
                'easy'
            ),
            self::sample(
                'queue-front',
                __('큐: front 확인', 'edu-games'),
                'queue',
                __('front는 큐의 맨 앞 값을 확인합니다. 마지막 front의 결과는 무엇일까요?', 'edu-games'),
                array('enqueue 4', 'enqueue 8', 'front', 'dequeue', 'front'),
                array('4', '8', '4, 8', '비어 있음'),
                '8',
                __('처음 front는 4이고, dequeue로 4가 나간 뒤 맨 앞은 8입니다.', 'edu-games'),
                'easy'
            ),
            self::sample(
                'priority-basic',
                __('우선순위 큐: 높은 우선순위', 'edu-games'),
                'priority-queue',
                __('숫자가 클수록 우선순위가 높다고 할 때, pop의 출력은 무엇일까요?', 'edu-games'),
                array('push A:2', 'push B:5', 'push C:3', 'pop'),
                array('A', 'B', 'C', 'A, B'),
                'B',
                __('가장 높은 우선순위는 5이므로 B가 먼저 나옵니다.', 'edu-games'),
                'easy'
            ),
            self::sample(
                'stack-pop-after-peek',
                __('스택: peek 뒤 pop', 'edu-games'),
                'stack',
                __('peek은 값을 꺼내지 않습니다. 다음 pop의 출력은 무엇일까요?', 'edu-games'),
                array('push 사과', 'push 배', 'peek', 'pop'),
                array('사과', '배', '사과, 배', '비어 있음'),
                '배',
                __('peek으로 확인해도 배는 스택에 남아 있으므로 pop에서 배가 나옵니다.', 'edu-games'),
                'easy'
            ),
            self::sample(
                'queue-double-dequeue',
                __('큐: 두 번째 dequeue', 'edu-games'),
                'queue',
                __('두 번 dequeue했을 때 두 번째 출력은 무엇일까요?', 'edu-games'),
                array('enqueue 빨강', 'enqueue 파랑', 'enqueue 초록', 'dequeue', 'dequeue'),
                array('빨강', '파랑', '초록', '비어 있음'),
                '파랑',
                __('큐는 먼저 들어온 빨강이 먼저 나가고 두 번째로 파랑이 나갑니다.', 'edu-games'),
                'easy'
            ),
            self::sample(
                'stack-empty-result',
                __('스택: 모두 꺼낸 뒤 상태', 'edu-games'),
                'stack',
                __('명령을 모두 수행한 뒤 스택 상태는 무엇일까요?', 'edu-games'),
                array('push 10', 'push 20', 'pop', 'pop'),
                array('10', '20', '10, 20', '비어 있음'),
                '비어 있음',
                __('20과 10이 모두 pop으로 나가서 스택이 비어 있습니다.', 'edu-games'),
                'easy'
            ),
            self::sample(
                'priority-number-order',
                __('우선순위 큐: 숫자 우선순위', 'edu-games'),
                'priority-queue',
                __('숫자가 클수록 먼저 나갑니다. pop의 출력은 무엇일까요?', 'edu-games'),
                array('push 낮음:1', 'push 보통:3', 'push 높음:7', 'pop'),
                array('낮음', '보통', '높음', '비어 있음'),
                '높음',
                __('우선순위 7이 가장 크므로 높음이 먼저 나옵니다.', 'edu-games'),
                'easy'
            ),
            self::sample(
                'queue-front-after-add',
                __('큐: 추가 후 front', 'edu-games'),
                'queue',
                __('새 값을 뒤에 넣어도 front는 맨 앞 값을 보여줍니다. 마지막 front의 결과는?', 'edu-games'),
                array('enqueue 1', 'enqueue 2', 'front', 'enqueue 3', 'front'),
                array('1', '2', '3', '비어 있음'),
                '1',
                __('3은 뒤에 들어갔기 때문에 맨 앞은 계속 1입니다.', 'edu-games'),
                'easy'
            ),
            self::sample(
                'stack-mixed-output',
                __('스택: 출력 순서 추적', 'edu-games'),
                'stack',
                __('모든 pop의 출력 순서를 고르세요.', 'edu-games'),
                array('push A', 'push B', 'pop', 'push C', 'pop', 'pop'),
                array('B, C, A', 'A, B, C', 'B, A, C', 'C, B, A'),
                'B, C, A',
                __('B가 먼저 나오고, C를 넣은 뒤 C, 마지막으로 A가 나옵니다.', 'edu-games'),
                'normal'
            ),
            self::sample(
                'queue-mixed-state',
                __('큐: 최종 상태', 'edu-games'),
                'queue',
                __('명령을 모두 수행한 뒤 큐에 남아 있는 값의 순서를 고르세요.', 'edu-games'),
                array('enqueue A', 'enqueue B', 'dequeue', 'enqueue C', 'enqueue D', 'dequeue'),
                array('C, D', 'A, D', 'D, C', 'B, C, D'),
                'C, D',
                __('A와 B가 차례로 나가고 C, D가 남습니다.', 'edu-games'),
                'normal'
            ),
            self::sample(
                'priority-mixed',
                __('우선순위 큐: 두 번 pop', 'edu-games'),
                'priority-queue',
                __('숫자가 클수록 먼저 나갑니다. 두 번의 pop 출력 순서는 무엇일까요?', 'edu-games'),
                array('push A:1', 'push B:4', 'push C:2', 'pop', 'push D:5', 'pop'),
                array('B, D', 'D, B', 'A, B', 'B, C'),
                'B, D',
                __('첫 pop은 B입니다. 그 뒤 D:5가 들어와 두 번째 pop은 D입니다.', 'edu-games'),
                'normal'
            ),
            self::sample(
                'stack-final-state',
                __('스택: 남은 값', 'edu-games'),
                'stack',
                __('명령을 모두 수행한 뒤 스택의 아래에서 위 순서를 고르세요.', 'edu-games'),
                array('push 1', 'push 2', 'push 3', 'pop', 'push 4'),
                array('1, 2, 4', '4, 2, 1', '1, 3, 4', '2, 4'),
                '1, 2, 4',
                __('3이 pop으로 나가고 4가 맨 위에 쌓입니다.', 'edu-games'),
                'normal'
            ),
            self::sample(
                'queue-output-chain',
                __('큐: 출력 연쇄', 'edu-games'),
                'queue',
                __('모든 dequeue의 출력 순서를 고르세요.', 'edu-games'),
                array('enqueue 1', 'enqueue 2', 'enqueue 3', 'dequeue', 'enqueue 4', 'dequeue', 'dequeue'),
                array('1, 2, 3', '3, 2, 1', '1, 4, 2', '2, 3, 4'),
                '1, 2, 3',
                __('큐는 들어온 순서대로 1, 2, 3이 나갑니다. 4는 아직 남아 있습니다.', 'edu-games'),
                'normal'
            ),
            self::sample(
                'stack-number-output',
                __('스택: 숫자 출력 순서', 'edu-games'),
                'stack',
                __('모든 pop의 출력 순서를 고르세요.', 'edu-games'),
                array('push 5', 'push 8', 'push 2', 'pop', 'pop', 'push 9', 'pop'),
                array('2, 8, 9', '5, 8, 2', '2, 9, 8', '9, 2, 8'),
                '2, 8, 9',
                __('2와 8이 차례로 나오고, 9를 새로 넣은 뒤 9가 나옵니다.', 'edu-games'),
                'normal'
            ),
            self::sample(
                'queue-service-line',
                __('큐: 대기열 처리', 'edu-games'),
                'queue',
                __('모든 dequeue의 출력 순서를 고르세요.', 'edu-games'),
                array('enqueue 손님A', 'enqueue 손님B', 'dequeue', 'enqueue 손님C', 'enqueue 손님D', 'dequeue', 'dequeue'),
                array('손님A, 손님B, 손님C', '손님B, 손님C, 손님D', '손님A, 손님C, 손님D', '손님D, 손님C, 손님B'),
                '손님A, 손님B, 손님C',
                __('A가 먼저 처리되고, 그 다음은 먼저 들어와 있던 B, 이후 C가 처리됩니다.', 'edu-games'),
                'normal'
            ),
            self::sample(
                'priority-insert-between',
                __('우선순위 큐: 중간 삽입', 'edu-games'),
                'priority-queue',
                __('숫자가 클수록 먼저 나갑니다. 두 번의 pop 출력 순서는?', 'edu-games'),
                array('push A:4', 'push B:2', 'pop', 'push C:3', 'push D:5', 'pop'),
                array('A, D', 'D, A', 'B, C', 'A, C'),
                'A, D',
                __('처음에는 A:4가 가장 높고, 이후 D:5가 들어와 두 번째로 나갑니다.', 'edu-games'),
                'normal'
            ),
            self::sample(
                'stack-undo-history',
                __('스택: 실행 취소 기록', 'edu-games'),
                'stack',
                __('작업 기록을 스택에 저장합니다. 두 번 undo했을 때 되돌아가는 작업 순서는?', 'edu-games'),
                array('push 그리기', 'push 색칠', 'push 이동', 'undo', 'undo'),
                array('이동, 색칠', '그리기, 색칠', '색칠, 이동', '이동, 그리기'),
                '이동, 색칠',
                __('가장 최근 작업 이동이 먼저 취소되고 그 다음 색칠이 취소됩니다.', 'edu-games'),
                'normal'
            ),
            self::sample(
                'queue-final-after-mix',
                __('큐: 남은 대기 항목', 'edu-games'),
                'queue',
                __('명령을 모두 수행한 뒤 큐에 남은 값을 앞에서 뒤 순서로 고르세요.', 'edu-games'),
                array('enqueue A', 'enqueue B', 'enqueue C', 'dequeue', 'enqueue D', 'dequeue', 'enqueue E'),
                array('C, D, E', 'D, E', 'B, C, D', 'E, D, C'),
                'C, D, E',
                __('A와 B가 나가고, 남은 C 뒤에 D와 E가 들어옵니다.', 'edu-games'),
                'normal'
            ),
            self::sample(
                'priority-tie',
                __('우선순위 큐: 같은 우선순위', 'edu-games'),
                'priority-queue',
                __('우선순위가 같으면 먼저 들어온 값이 먼저 나갑니다. 두 번의 pop 출력은 무엇일까요?', 'edu-games'),
                array('push A:3', 'push B:5', 'push C:5', 'push D:2', 'pop', 'pop'),
                array('B, C', 'C, B', 'A, D', 'B, A'),
                'B, C',
                __('B와 C의 우선순위가 5로 같고 B가 먼저 들어왔으므로 B, C 순서입니다.', 'edu-games'),
                'hard'
            ),
            self::sample(
                'stack-parenthesis',
                __('스택: 괄호 검사', 'edu-games'),
                'stack',
                __('괄호 검사에서 여는 괄호는 push, 닫는 괄호는 pop합니다. 문자열 (()()) 처리 뒤 스택 상태는?', 'edu-games'),
                array('(', '(', ')', '(', ')', ')'),
                array('비어 있음', '(', '(, (', '오류'),
                '비어 있음',
                __('모든 여는 괄호가 대응되는 닫는 괄호로 제거되어 스택이 비어 있습니다.', 'edu-games'),
                'hard'
            ),
            self::sample(
                'queue-task-order',
                __('큐: 작업 처리 순서', 'edu-games'),
                'queue',
                __('프린터 큐에서 모든 출력 작업의 순서를 고르세요.', 'edu-games'),
                array('enqueue 문서A', 'enqueue 문서B', 'print', 'enqueue 문서C', 'print', 'enqueue 문서D', 'print'),
                array('문서A, 문서B, 문서C', '문서B, 문서C, 문서D', '문서A, 문서C, 문서D', '문서C, 문서B, 문서A'),
                '문서A, 문서B, 문서C',
                __('큐는 먼저 들어온 문서부터 처리합니다. 문서D는 마지막에 남아 있습니다.', 'edu-games'),
                'hard'
            ),
            self::sample(
                'priority-final-state',
                __('우선순위 큐: 남은 작업', 'edu-games'),
                'priority-queue',
                __('숫자가 클수록 중요합니다. 명령 후 큐에 남은 작업을 우선순위 높은 순서로 고르세요.', 'edu-games'),
                array('push A:2', 'push B:9', 'push C:4', 'pop', 'push D:6', 'pop'),
                array('C, A', 'D, C, A', 'B, D', 'A, C, D'),
                'C, A',
                __('B와 D가 pop으로 나갔고 남은 값은 C:4, A:2입니다.', 'edu-games'),
                'hard'
            ),
            self::sample(
                'stack-queue-compare',
                __('스택과 큐 비교', 'edu-games'),
                'stack',
                __('같은 입력 A, B, C를 넣은 뒤 모두 꺼냅니다. 스택의 출력 순서는 무엇일까요?', 'edu-games'),
                array('push A', 'push B', 'push C', 'pop', 'pop', 'pop'),
                array('A, B, C', 'C, B, A', 'B, C, A', 'C, A, B'),
                'C, B, A',
                __('스택은 마지막 입력 C부터 거꾸로 출력됩니다.', 'edu-games'),
                'hard'
            ),
            self::sample(
                'priority-triple-pop-tie',
                __('우선순위 큐: 동점 포함 세 번 pop', 'edu-games'),
                'priority-queue',
                __('숫자가 클수록 먼저 나가고, 우선순위가 같으면 먼저 들어온 값이 먼저 나갑니다. 세 번의 pop 출력은?', 'edu-games'),
                array('push A:4', 'push B:6', 'push C:6', 'push D:5', 'pop', 'pop', 'pop'),
                array('B, C, D', 'C, B, D', 'B, D, C', 'D, B, C'),
                'B, C, D',
                __('B와 C는 우선순위 6으로 같고 B가 먼저 들어왔습니다. 그 다음은 D:5입니다.', 'edu-games'),
                'hard'
            ),
            self::sample(
                'stack-bracket-error',
                __('스택: 괄호 오류 찾기', 'edu-games'),
                'stack',
                __('괄호 검사에서 닫는 괄호가 나왔는데 스택이 비어 있으면 오류입니다. 문자열 ())의 처리 결과는?', 'edu-games'),
                array('(', ')', ')'),
                array('비어 있음', '(', '오류', '(, )'),
                '오류',
                __('첫 닫는 괄호는 대응되지만 두 번째 닫는 괄호를 처리할 여는 괄호가 없어 오류입니다.', 'edu-games'),
                'hard'
            ),
            self::sample(
                'queue-buffer-flow',
                __('큐: 버퍼 처리 흐름', 'edu-games'),
                'queue',
                __('스트리밍 버퍼에서 처리된 데이터의 순서를 고르세요.', 'edu-games'),
                array('enqueue 패킷1', 'enqueue 패킷2', 'process', 'enqueue 패킷3', 'enqueue 패킷4', 'process', 'process', 'enqueue 패킷5'),
                array('패킷1, 패킷2, 패킷3', '패킷2, 패킷3, 패킷4', '패킷1, 패킷3, 패킷4', '패킷4, 패킷3, 패킷2'),
                '패킷1, 패킷2, 패킷3',
                __('큐는 먼저 들어온 패킷부터 처리하므로 1, 2, 3 순서로 나갑니다.', 'edu-games'),
                'hard'
            ),
            self::sample(
                'priority-state-after-tie',
                __('우선순위 큐: 남은 항목 정렬', 'edu-games'),
                'priority-queue',
                __('우선순위가 같으면 먼저 들어온 값이 앞섭니다. 명령 후 남은 항목을 나갈 순서대로 고르세요.', 'edu-games'),
                array('push A:3', 'push B:7', 'push C:5', 'push D:5', 'pop', 'push E:4'),
                array('C, D, E, A', 'D, C, E, A', 'B, C, D, E', 'E, C, D, A'),
                'C, D, E, A',
                __('B:7은 pop으로 나갔고 C와 D는 5로 동점이어서 먼저 들어온 C가 앞섭니다.', 'edu-games'),
                'hard'
            ),
            self::sample(
                'stack-nested-output',
                __('스택: 중첩 작업 출력', 'edu-games'),
                'stack',
                __('모든 pop의 출력 순서를 고르세요.', 'edu-games'),
                array('push A', 'push B', 'push C', 'pop', 'push D', 'pop', 'pop', 'pop'),
                array('C, D, B, A', 'A, B, C, D', 'C, B, D, A', 'D, C, B, A'),
                'C, D, B, A',
                __('C가 먼저 나오고 D를 새로 넣어 D, 그 다음 남아 있던 B와 A가 나옵니다.', 'edu-games'),
                'hard'
            ),
        );
    }

    /**
     * @param array<int, string> $operations
     * @param array<int, string> $options
     * @return array<string, mixed>
     */
    private static function sample(
        string $id,
        string $title,
        string $structure,
        string $question,
        array $operations,
        array $options,
        string $answer,
        string $explanation,
        string $difficulty
    ): array {
        return array(
            'id' => $id,
            'title' => $title,
            'structure' => $structure,
            'question' => $question,
            'operations' => $operations,
            'options' => $options,
            'answer' => $answer,
            'explanation' => $explanation,
            'difficulty' => $difficulty,
        );
    }
}
