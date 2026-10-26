<?php
declare(strict_types=1);

namespace OjCodeArena\Support;

/**
 * Server-side twin of shared/arena-bank.js checks (checkNormal/checkBoss).
 * Keep the limits in sync with that file.
 */
final class Problem_Validator {
    public const MAX_NORMAL = 200;
    public const MAX_BOSS = 1200;
    public const MAX_ANSWER = 300;
    public const MAX_TITLE = 80;
    public const MAX_INPUT = 400;
    public const MAX_TAG = 20;
    private const HANGUL = '/[\x{1100}-\x{11FF}\x{3130}-\x{318F}\x{AC00}-\x{D7AF}]/u';

    /**
     * @param array<string,mixed> $input
     * @return array{kind: string, title: string, code: string, input: string, answers: list<string>, tags: list<string>}
     */
    public static function clean(array $input): array {
        $kind = 'boss' === ($input['kind'] ?? '') ? 'boss' : 'normal';
        $code = str_replace("\r\n", "\n", (string) ($input['code'] ?? ''));
        $code = 'normal' === $kind ? trim($code) : rtrim($code);
        $tags = array();
        foreach ((array) ($input['tags'] ?? array()) as $t) {
            $t = mb_substr(trim(wp_strip_all_tags((string) $t)), 0, self::MAX_TAG);
            if ('' !== $t) {
                $tags[$t] = true;
            }
        }
        $p = array(
            'kind' => $kind,
            'title' => 'boss' === $kind ? mb_substr(trim(wp_strip_all_tags((string) ($input['title'] ?? ''))), 0, self::MAX_TITLE) : '',
            'code' => $code,
            'input' => 'boss' === $kind ? mb_substr(str_replace("\r\n", "\n", (string) ($input['input'] ?? '')), 0, self::MAX_INPUT) : '',
            'answers' => array(),
            'tags' => array_slice(array_keys($tags), 0, 10),
        );
        if ('boss' === $kind) {
            foreach ((array) ($input['answers'] ?? array()) as $a) {
                $a = trim((string) $a);
                if ('' !== $a) {
                    $p['answers'][] = $a;
                }
            }
            $p['answers'] = array_slice($p['answers'], 0, 8);
            if ('' === $p['title']) {
                $p['title'] = '보스 문제';
            }
        }

        $error = self::error($p);
        if ('' !== $error) {
            throw new \InvalidArgumentException($error);
        }
        return $p;
    }

    /** @param array<string,mixed> $p */
    private static function error(array $p): string {
        $code = (string) $p['code'];
        if ('' === trim($code)) {
            return '코드가 비어 있어요.';
        }
        if (preg_match(self::HANGUL, $code)) {
            return '코드에 한글은 쓸 수 없어요.';
        }
        if ('normal' === $p['kind']) {
            if (str_contains($code, "\n")) {
                return '일반 문제는 한 줄이어야 해요.';
            }
            return mb_strlen($code) > self::MAX_NORMAL ? '일반 문제는 ' . self::MAX_NORMAL . '자까지예요.' : '';
        }
        if (mb_strlen($code) > self::MAX_BOSS) {
            return '보스 코드는 ' . self::MAX_BOSS . '자까지예요.';
        }
        if (substr_count($code, '___') > 1) {
            return '빈칸(___)은 하나만 둘 수 있어요.';
        }
        if (! $p['answers']) {
            return '정답이 없어요.';
        }
        foreach ($p['answers'] as $a) {
            if (preg_match(self::HANGUL, $a)) {
                return '정답에 한글은 쓸 수 없어요.';
            }
            if (mb_strlen($a) > self::MAX_ANSWER) {
                return '정답이 너무 길어요.';
            }
        }
        return '';
    }
}
