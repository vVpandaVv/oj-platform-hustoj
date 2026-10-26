<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Keyword_Rain;

use EduGames\Games\Contracts\Game_Module;

final class Keyword_Rain_Module implements Game_Module {
    public const ID = 'keyword-rain';
    public const SHORTCODE = 'keyword_rain';
    public const BLOCK = 'edu-games/keyword-rain';

    public function id(): string {
        return self::ID;
    }

    public function definition(): array {
        return array(
            'id' => $this->id(),
            'title' => __('키워드 레인', 'edu-games'),
            'category' => 'coding-skills',
            'description' => __('떨어지는 프로그래밍 키워드와 용어를 빠르게 입력해 제거하는 반응형 타이핑 게임입니다.', 'edu-games'),
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
            'defaultLanguage' => 'python',
            'defaultDifficulty' => 'easy',
            'rankedLives' => 5,
            'maxActiveDrops' => 8,
            'levelEvery' => 10,
            'difficulties' => array(
                'easy' => array(
                    'label' => __('기초', 'edu-games'),
                    'description' => __('짧은 키워드가 천천히 내려옵니다.', 'edu-games'),
                    'spawnMs' => 1850,
                    'minSpawnMs' => 950,
                    'fallMs' => 8200,
                    'minFallMs' => 4300,
                    'score' => 90,
                ),
                'normal' => array(
                    'label' => __('표준', 'edu-games'),
                    'description' => __('키워드와 용어가 섞이고 속도가 조금 빠릅니다.', 'edu-games'),
                    'spawnMs' => 1500,
                    'minSpawnMs' => 780,
                    'fallMs' => 6900,
                    'minFallMs' => 3600,
                    'score' => 120,
                ),
                'hard' => array(
                    'label' => __('심화', 'edu-games'),
                    'description' => __('긴 용어와 비슷한 단어가 빠르게 등장합니다.', 'edu-games'),
                    'spawnMs' => 1250,
                    'minSpawnMs' => 650,
                    'fallMs' => 5600,
                    'minFallMs' => 3000,
                    'score' => 155,
                ),
            ),
            'scoring' => array(
                'comboBonus' => 7,
                'maxComboBonus' => 84,
                'levelBonus' => 12,
                'missPenalty' => 20,
            ),
            'languages' => self::languages(),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function languages(): array {
        return array(
            'python' => self::language(
                __('Python', 'edu-games'),
                __('파이썬 키워드와 자주 쓰는 내장 함수입니다.', 'edu-games'),
                array(
                    'if', 'elif', 'else', 'for', 'while', 'break', 'continue', 'pass',
                    'def', 'return', 'lambda', 'class', 'self', 'import', 'from', 'as',
                    'try', 'except', 'finally', 'raise', 'with', 'yield', 'async', 'await',
                    'True', 'False', 'None', 'and', 'or', 'not', 'in', 'is',
                    'list', 'dict', 'set', 'tuple', 'range', 'len', 'sum', 'print',
                    'input', 'int', 'float', 'str', 'bool', 'append', 'split', 'join',
                    'enumerate', 'zip', 'sorted', 'open', 'read', 'write',
                )
            ),
            'c' => self::language(
                __('C 언어', 'edu-games'),
                __('C 언어 키워드, 자료형, 표준 입출력 용어입니다.', 'edu-games'),
                array(
                    'int', 'char', 'float', 'double', 'void', 'short', 'long', 'signed', 'unsigned',
                    'if', 'else', 'for', 'while', 'do', 'switch', 'case', 'default',
                    'break', 'continue', 'return', 'struct', 'enum', 'typedef', 'union',
                    'const', 'static', 'extern', 'sizeof', 'volatile', 'register',
                    'include', 'define', 'stdio.h', 'stdlib.h', 'printf', 'scanf',
                    'main', 'array', 'pointer', 'malloc', 'free', 'NULL', 'FILE',
                )
            ),
            'javascript' => self::language(
                __('JavaScript', 'edu-games'),
                __('웹 프로그래밍에서 자주 만나는 자바스크립트 키워드입니다.', 'edu-games'),
                array(
                    'let', 'const', 'var', 'function', 'return', 'if', 'else', 'for', 'while',
                    'break', 'continue', 'switch', 'case', 'default', 'try', 'catch', 'finally',
                    'throw', 'class', 'extends', 'new', 'this', 'super', 'import', 'export',
                    'async', 'await', 'Promise', 'Array', 'Object', 'Map', 'Set', 'JSON',
                    'fetch', 'document', 'querySelector', 'addEventListener', 'console.log',
                    'true', 'false', 'null', 'undefined', 'NaN',
                )
            ),
            'web' => self::language(
                __('HTML/CSS', 'edu-games'),
                __('웹 문서와 스타일에서 자주 쓰는 태그와 속성입니다.', 'edu-games'),
                array(
                    'html', 'head', 'body', 'title', 'meta', 'link', 'script', 'main',
                    'section', 'article', 'header', 'footer', 'nav', 'div', 'span',
                    'h1', 'p', 'a', 'img', 'button', 'input', 'label', 'form',
                    'class', 'id', 'href', 'src', 'alt', 'type', 'value',
                    'display', 'grid', 'flex', 'gap', 'margin', 'padding', 'border',
                    'color', 'background', 'font-size', 'line-height', 'position',
                )
            ),
            'data' => self::language(
                __('데이터 용어', 'edu-games'),
                __('자료 처리와 데이터 분석에서 자주 쓰는 용어입니다.', 'edu-games'),
                array(
                    'data', 'dataset', 'table', 'row', 'column', 'field', 'record',
                    'mean', 'median', 'mode', 'range', 'min', 'max', 'sum',
                    'count', 'filter', 'sort', 'group', 'join', 'merge',
                    'outlier', 'missing', 'null', 'cleaning', 'encoding',
                    'label', 'feature', 'sample', 'chart', 'bar', 'line',
                    'histogram', 'scatter', 'axis', 'scale', 'trend', 'pattern',
                )
            ),
            'mixed' => self::language(
                __('혼합 연습', 'edu-games'),
                __('프로그래밍 언어와 데이터 용어를 섞어 연습합니다.', 'edu-games'),
                array(
                    'if', 'else', 'for', 'while', 'return', 'function', 'class', 'import',
                    'int', 'float', 'char', 'printf', 'array', 'pointer', 'def', 'lambda',
                    'list', 'dict', 'console.log', 'querySelector', 'display', 'grid',
                    'dataset', 'filter', 'sort', 'mean', 'median', 'outlier', 'missing',
                    'histogram', 'scatter', 'axis', 'trend', 'JSON', 'fetch', 'async', 'await',
                )
            ),
        );
    }

    /**
     * @param array<int, string> $words
     * @return array<string, mixed>
     */
    private static function language(string $label, string $description, array $words): array {
        return array(
            'label' => $label,
            'description' => $description,
            'words' => $words,
        );
    }
}
