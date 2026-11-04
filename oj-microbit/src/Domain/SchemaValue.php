<?php

declare(strict_types=1);

namespace OjMicrobit\Domain;

use InvalidArgumentException;

/** Internal, dependency-free validation helpers shared by the public schemas. */
final class SchemaValue
{
    public static function object(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException($path . ': 객체가 필요합니다.');
        }
        return $value;
    }

    public static function list(mixed $value, int $maximum, string $path): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new InvalidArgumentException($path . ': 목록 형식 또는 항목 수가 올바르지 않습니다.');
        }
        return $value;
    }

    public static function string(mixed $value, int $maximum, string $path, bool $empty = false, bool $trim = true): string
    {
        if (!is_string($value) || str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException($path . ': 올바른 UTF-8 문자열이 필요합니다.');
        }
        $result = $trim ? trim($value) : $value;
        if ((!$empty && $result === '') || strlen($result) > $maximum) {
            throw new InvalidArgumentException($path . ': 문자열 길이가 올바르지 않습니다.');
        }
        return $result;
    }

    public static function integer(mixed $value, int $minimum, int $maximum, string $path): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($path . ': ' . $minimum . '~' . $maximum . ' 범위의 정수가 필요합니다.');
        }
        return $value;
    }

    public static function oneOf(mixed $value, array $allowed, string $path): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($path . ': 허용되지 않은 값입니다.');
        }
        return $value;
    }

    public static function boolean(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException($path . ': 참 또는 거짓이 필요합니다.');
        }
        return $value;
    }
}
