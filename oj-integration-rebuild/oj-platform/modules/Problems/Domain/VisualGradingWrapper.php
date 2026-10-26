<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Domain;

use InvalidArgumentException;

/**
 * Python code placed before and after a student's source so that the drawing
 * is written to stdout as a PNG. Every line is hidden from students with the
 * existing "#hidden" marker, so the code must stay safe to annotate line by
 * line: no backslash continuations and no multi-line strings.
 */
final class VisualGradingWrapper
{
    private const MAX_BYTES = 24576;

    public function __construct(
        private readonly string $prefix,
        private readonly string $suffix
    ) {
        foreach ([$prefix, $suffix] as $source) {
            if (trim($source) === '' || strlen($source) > self::MAX_BYTES) {
                throw new InvalidArgumentException('이미지 채점 래퍼 코드 크기가 올바르지 않습니다.');
            }
            if (preg_match('//u', $source) !== 1) {
                throw new InvalidArgumentException('이미지 채점 래퍼 코드는 UTF-8이어야 합니다.');
            }
            if (
                str_contains($source, '"""')
                || str_contains($source, "'''")
                || preg_match('/\\\\[ \t]*$/m', $source) === 1
            ) {
                throw new InvalidArgumentException(
                    '이미지 채점 래퍼 코드에는 여러 줄 문자열이나 줄 이음을 쓸 수 없습니다.'
                );
            }
        }
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function suffix(): string
    {
        return $this->suffix;
    }

    public function hiddenPrefix(): string
    {
        return self::hide($this->prefix);
    }

    public function hiddenSuffix(): string
    {
        return self::hide($this->suffix);
    }

    private static function hide(string $source): string
    {
        $lines = explode("\n", rtrim(str_replace(["\r\n", "\r"], "\n", $source), "\n"));

        return implode("\n", array_map(
            static fn (string $line): string => rtrim($line) . '  #hidden',
            $lines
        ));
    }
}
