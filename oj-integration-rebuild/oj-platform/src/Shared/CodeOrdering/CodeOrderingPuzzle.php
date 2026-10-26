<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\CodeOrdering;

use DomainException;
use JsonException;

/** Server-owned Parsons puzzle. Only publicBlocks() may be sent to learners. */
final class CodeOrderingPuzzle
{
    private const MAX_BYTES = 65536;
    private const MAX_BLOCKS = 200;

    /** @return array{version:int,language_key:string,blocks:list<array{id:string,code:string}>} */
    public static function create(string $source, string $languageKey): array
    {
        self::assertLanguage($languageKey);
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        if (trim($source) === '' || strlen($source) > self::MAX_BYTES
            || str_contains($source, "\0") || preg_match('//u', $source) !== 1) {
            throw new DomainException('배열할 완성 코드는 UTF-8 텍스트로 64KB 이하여야 합니다.');
        }
        // A stable final newline keeps moving the original last block safe.
        if (! str_ends_with($source, "\n")) {
            $source .= "\n";
        }
        if (strlen($source) > self::MAX_BYTES) {
            throw new DomainException('마지막 줄바꿈을 포함해 완성 코드는 64KB 이하여야 합니다.');
        }
        $blocks = [];
        foreach (self::split($source, $languageKey) as $code) {
            $blocks[] = ['id' => bin2hex(random_bytes(16)), 'code' => $code];
        }
        $schema = ['version' => 1, 'language_key' => $languageKey, 'blocks' => $blocks];
        self::validate($schema);
        return $schema;
    }

    public static function encode(array $schema): string
    {
        self::validate($schema);
        return json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(string $schema): string
    {
        $canonical = self::decode($schema);
        // This value is public. Bind the piece set, never the solution order:
        // otherwise trying permutations of public pieces reveals the answer.
        usort($canonical['blocks'], static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));
        return hash('sha256', self::encode($canonical));
    }

    /** @return list<array{id:string,code:string}> No position or solution order. */
    public static function publicBlocks(string $schema): array
    {
        $blocks = self::decode($schema)['blocks'];
        for ($index = count($blocks) - 1; $index > 0; --$index) {
            $other = random_int(0, $index);
            [$blocks[$index], $blocks[$other]] = [$blocks[$other], $blocks[$index]];
        }
        return $blocks;
    }

    /** @param list<string> $ids */
    public static function assemble(string $schema, array $ids): string
    {
        $blocks = self::decode($schema)['blocks'];
        if (! array_is_list($ids) || count($ids) !== count($blocks)) {
            throw new DomainException('모든 코드 조각을 한 번씩 배치해 주세요.');
        }
        $byId = array_column($blocks, 'code', 'id');
        $source = '';
        foreach ($ids as $id) {
            if (! is_string($id) || ! array_key_exists($id, $byId)) {
                throw new DomainException('코드 조각이 중복되었거나 문제 버전과 일치하지 않습니다.');
            }
            $source .= $byId[$id];
            unset($byId[$id]);
        }
        return $source;
    }

    public static function languageKey(string $schema): string
    {
        return self::decode($schema)['language_key'];
    }

    /** Teacher editing only. */
    public static function source(string $schema): string
    {
        return implode('', array_column(self::decode($schema)['blocks'], 'code'));
    }

    private static function decode(string $schema): array
    {
        if (strlen($schema) > self::MAX_BYTES * 3) {
            throw new DomainException('코드 배열 설정이 너무 큽니다.');
        }
        try {
            $decoded = json_decode($schema, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new DomainException('코드 배열 설정을 읽을 수 없습니다.', 0, $error);
        }
        if (! is_array($decoded)) {
            throw new DomainException('코드 배열 설정이 올바르지 않습니다.');
        }
        self::validate($decoded);
        return $decoded;
    }

    private static function validate(array $schema): void
    {
        $keys = array_keys($schema);
        sort($keys);
        if ($keys !== ['blocks', 'language_key', 'version'] || ($schema['version'] ?? null) !== 1
            || ! is_string($schema['language_key'] ?? null) || ! is_array($schema['blocks'] ?? null)
            || ! array_is_list($schema['blocks']) || count($schema['blocks']) < 2
            || count($schema['blocks']) > self::MAX_BLOCKS) {
            throw new DomainException('코드 배열은 2개부터 200개까지의 조각으로 구성해야 합니다.');
        }
        self::assertLanguage($schema['language_key']);
        $seen = [];
        $bytes = 0;
        foreach ($schema['blocks'] as $block) {
            if (! is_array($block) || count($block) !== 2
                || ! is_string($block['id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $block['id']) !== 1
                || isset($seen[$block['id']]) || ! is_string($block['code'] ?? null)
                || trim($block['code']) === '' || ! str_ends_with($block['code'], "\n")
                || str_contains($block['code'], "\0") || str_contains($block['code'], "\r")
                || preg_match('//u', $block['code']) !== 1) {
                throw new DomainException('코드 배열 조각의 ID와 원문을 확인해 주세요.');
            }
            $seen[$block['id']] = true;
            $bytes += strlen($block['code']);
        }
        if ($bytes > self::MAX_BYTES) {
            throw new DomainException('배열할 완성 코드는 64KB 이하여야 합니다.');
        }
    }

    private static function assertLanguage(string $languageKey): void
    {
        if (! in_array($languageKey, ['python', 'c', 'cpp', 'java'], true)) {
            throw new DomainException('코드 배열은 python, c, cpp, java 언어를 지원합니다.');
        }
    }

    /**
     * Split at logical newlines, retaining indentation and every source byte.
     * Quotes, parentheses, continued lines and comments are never cut apart.
     * Braces remain movable lines for C-like languages; Python suites retain
     * their original indentation and are judged after server reconstruction.
     * @return list<string>
     */
    private static function split(string $source, string $language): array
    {
        $python = $language === 'python';
        $quote = '';
        $rawTerminator = '';
        $triple = false;
        $comment = false;
        $lineComment = false;
        $stack = [];
        $buffer = '';
        $blocks = [];
        $length = strlen($source);
        for ($i = 0; $i < $length; ++$i) {
            $character = $source[$i];
            $next = $source[$i + 1] ?? '';
            $wasLineComment = $lineComment;
            $buffer .= $character;
            if ($rawTerminator !== '') {
                if (substr($source, $i, strlen($rawTerminator)) === $rawTerminator) {
                    $buffer .= substr($rawTerminator, 1);
                    $i += strlen($rawTerminator) - 1;
                    $rawTerminator = '';
                }
                continue;
            } elseif ($lineComment) {
                if ($character !== "\n") { continue; }
                $lineComment = false;
            } elseif ($comment) {
                if ($character === '*' && $next === '/') {
                    $buffer .= $source[++$i];
                    $comment = false;
                }
                continue;
            } elseif ($quote !== '') {
                if ($character === '\\') {
                    if ($next !== '') { $buffer .= $source[++$i]; }
                    continue;
                }
                if ($character === $quote) {
                    if (! $triple) {
                        $quote = '';
                    } elseif (substr($source, $i, 3) === str_repeat($quote, 3)) {
                        $buffer .= substr($source, $i + 1, 2);
                        $i += 2;
                        $quote = '';
                        $triple = false;
                    }
                }
                continue;
            } elseif (($python && $character === '#') || (! $python && $character === '/' && $next === '/')) {
                $lineComment = true;
            } elseif (! $python && $character === '/' && $next === '*') {
                $buffer .= $source[++$i];
                $comment = true;
            } elseif ($language === 'cpp' && $character === '"' && $i > 0 && $source[$i - 1] === 'R') {
                $opening = strpos($source, '(', $i + 1);
                $delimiter = $opening === false ? null : substr($source, $i + 1, $opening - $i - 1);
                if ($delimiter === null || strlen($delimiter) > 16
                    || preg_match('/[\s()\\\\]/', $delimiter) === 1) {
                    throw new DomainException('C++ 원시 문자열 구분자를 확인해 주세요.');
                }
                $buffer .= $delimiter . '(';
                $i = $opening;
                $rawTerminator = ')' . $delimiter . '"';
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
                $triple = ($python || $language === 'java') && substr($source, $i, 3) === str_repeat($character, 3);
                if ($triple) { $buffer .= substr($source, $i + 1, 2); $i += 2; }
            } elseif (str_contains('([', $character) || ($python && $character === '{')) {
                $stack[] = $character;
            } elseif (str_contains(')]', $character) || ($python && $character === '}')) {
                $expected = [')' => '(', ']' => '[', '}' => '{'][$character];
                if (array_pop($stack) !== $expected) {
                    throw new DomainException('완성 코드의 괄호를 확인해 주세요.');
                }
            }
            if ($character === "\n" && $stack === [] && $quote === '' && ! $comment
                && (($python && $wasLineComment) || ! str_ends_with(substr($buffer, 0, -1), '\\'))
                && trim($buffer) !== '') {
                $blocks[] = $buffer;
                $buffer = '';
            }
        }
        if ($quote !== '' || $rawTerminator !== '' || $comment || $stack !== []) {
            throw new DomainException('완성 코드의 문자열·주석·괄호가 닫혀 있는지 확인해 주세요.');
        }
        if ($buffer !== '') {
            if ($blocks === []) { $blocks[] = $buffer; }
            else { $blocks[count($blocks) - 1] .= $buffer; }
        }
        return $blocks;
    }
}
