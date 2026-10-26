<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Shared\Export;

use RuntimeException;

final class SpreadsheetCsv
{
    public static function cell(string $value): string
    {
        $probe = preg_replace('/^[\s\p{Z}\p{Cf}\x00-\x1F]+/u', '', $value) ?? ltrim($value);
        if (
            ($probe !== '' && str_contains('=+-@', $probe[0]))
            || preg_match('/^[\t\r\n]/', $value) === 1
        ) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    /** @param list<string|int|float|bool|null> $values */
    public static function row(array $values): string
    {
        return implode(',', array_map(
            static fn (mixed $value): string => is_string($value)
                ? self::cell($value)
                : ($value === null ? '' : (string) $value),
            $values
        ));
    }

    /** @param iterable<list<string|int|float|bool|null>> $rows */
    public static function encode(iterable $rows, bool $withBom = false): string
    {
        $stream = fopen('php://temp', 'w+b');
        if (! is_resource($stream)) {
            throw new RuntimeException('CSV 내보내기를 준비하지 못했습니다.');
        }
        try {
            if ($withBom) {
                fwrite($stream, "\xEF\xBB\xBF");
            }
            foreach ($rows as $row) {
                self::writeRow($stream, $row);
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            if ($contents === false) {
                throw new RuntimeException('CSV 내보내기를 읽지 못했습니다.');
            }
            return $contents;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    public static function writeRow(mixed $stream, array $values): void
    {
        $line = self::row($values) . "\r\n";
        if (fwrite($stream, $line) !== strlen($line)) {
            throw new RuntimeException('CSV 내보내기를 기록하지 못했습니다.');
        }
    }
}
