<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\RuntimeDiagnostic;
use CodingPanda\OjPlatform\Modules\Submissions\Domain\RuntimeErrorReport;

final class RuntimeErrorParser
{
    private const MAX_RAW_BYTES = 65536;
    private const MAX_ROWS = 50;

    public function parse(string $raw): RuntimeErrorReport
    {
        $rawTruncated = strlen($raw) > self::MAX_RAW_BYTES;
        $normalized = $this->normalize(substr($raw, 0, self::MAX_RAW_BYTES));
        if (trim($normalized) === '') {
            return new RuntimeErrorReport([], false, $rawTruncated, '');
        }
        $lines = preg_split('/\n/', $normalized) ?: [];
        $diagnostics = [];
        $recognized = false;

        foreach ($lines as $index => $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }
            if (preg_match(
                '/^File "([^"]+)", line (\d+), in (.+)$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '호출 위치',
                    'Python 호출',
                    $this->pythonSourceLine($lines, $index),
                    $match[1],
                    (int) $match[2],
                    $match[3]
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^at\s+(.+?)\s+in\s+(.+):line\s+(\d+)$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '호출 위치',
                    '.NET 호출',
                    '',
                    $match[2],
                    (int) $match[3],
                    $match[1]
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^at\s+([A-Za-z0-9_.$<>]+)\(([^():]+):(\d+)\)$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '호출 위치',
                    'Java 호출',
                    '',
                    $match[2],
                    (int) $match[3],
                    $match[1]
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^at\s+(.+?)\s+\((.+):(\d+):(\d+)\)$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '호출 위치',
                    'JavaScript 호출',
                    '열 ' . $match[4],
                    $match[2],
                    (int) $match[3],
                    $match[1]
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^at\s+(.+):(\d+):(\d+)$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '호출 위치',
                    'JavaScript 호출',
                    '열 ' . $match[3],
                    $match[1],
                    (int) $match[2]
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^#\d+\s+(?:0x[0-9A-Fa-f]+\s+in\s+)?(.+?)\s+(\S+?):(\d+)(?::(\d+))?(?:\s|$)/',
                $line,
                $match
            ) === 1) {
                $column = isset($match[4]) && $match[4] !== ''
                    ? '열 ' . $match[4]
                    : '';
                $this->add(
                    $diagnostics,
                    '호출 위치',
                    'Sanitizer 호출',
                    $column,
                    $match[2],
                    (int) $match[3],
                    $match[1]
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^(.+?):(\d+)(?::(\d+))?:\s*runtime error:\s*(.+)$/',
                $line,
                $match
            ) === 1) {
                $column = isset($match[3]) && $match[3] !== ''
                    ? '열 ' . $match[3]
                    : '';
                $this->add(
                    $diagnostics,
                    '실행 오류',
                    'UndefinedBehaviorSanitizer',
                    trim($match[4] . ($column === '' ? '' : ' (' . $column . ')')),
                    $match[1],
                    (int) $match[2]
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^==\d+==ERROR:\s+([A-Za-z]+Sanitizer):\s+([A-Za-z0-9_-]+)(.*)$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '메모리 오류',
                    $match[1] . ': ' . $match[2],
                    trim($match[3])
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^SUMMARY:\s+([A-Za-z]+Sanitizer):\s+([A-Za-z0-9_-]+)\s*(.*)$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '요약',
                    $match[1] . ': ' . $match[2],
                    trim($match[3])
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^(READ|WRITE) of size (\d+)(.*)$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '메모리 접근',
                    $match[1],
                    '크기 ' . $match[2] . trim($match[3])
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^thread [\'\"](.+?)[\'\"] panicked at (.+?),\s*(.+):(\d+):(\d+)$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '패닉',
                    'Rust panic',
                    $match[2] . ' (열 ' . $match[5] . ')',
                    $match[3],
                    (int) $match[4],
                    $match[1]
                );
                $recognized = true;
                continue;
            }
            if (preg_match('/^panic:\s*(.+)$/', $line, $match) === 1) {
                $this->add($diagnostics, '패닉', 'Go panic', $match[1]);
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^terminate called after throwing an instance of [\'\"]([^\'\"]+)[\'\"]$/',
                $line,
                $match
            ) === 1) {
                $this->add($diagnostics, '예외', $match[1], '처리되지 않은 C++ 예외');
                $recognized = true;
                continue;
            }
            if (preg_match('/^what\(\):\s*(.+)$/', $line, $match) === 1) {
                $this->add($diagnostics, '원인', 'what()', $match[1]);
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^Exception in thread "[^"]+"\s+([A-Za-z0-9_.$]+)(?::\s*(.*))?$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '예외',
                    $match[1],
                    trim($match[2] ?? '')
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^Caused by:\s+([A-Za-z0-9_.$]+)(?::\s*(.*))?$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '원인',
                    $match[1],
                    trim($match[2] ?? '')
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^(?:Unhandled exception\.?\s*)?([A-Za-z_][A-Za-z0-9_.$]*(?:Error|Exception|Exit|Interrupt))(?::\s*(.*))?$/',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '예외',
                    $match[1],
                    trim($match[2] ?? '')
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^Command terminated by signal\s+(.+)$/i',
                $line,
                $match
            ) === 1) {
                $this->add($diagnostics, '프로세스 종료', 'Signal', trim($match[1]));
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^(Segmentation fault|Floating point exception|Bus error|Aborted|Killed)(?:\s*\(([^)]*)\))?.*$/i',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '프로세스 종료',
                    $match[1],
                    trim($match[2] ?? '')
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/\b(SIGSEGV|SIGFPE|SIGABRT|SIGBUS|SIGKILL)\b/',
                $line,
                $match
            ) === 1) {
                $this->add($diagnostics, '프로세스 종료', $match[1], $line);
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^(?:(free|malloc|realloc)\(\):\s*)?'
                    . '(double free or corruption|invalid pointer|corrupted size|.+stack smashing detected.+)(.*)$/i',
                $line,
                $match
            ) === 1) {
                $this->add(
                    $diagnostics,
                    '메모리 오류',
                    trim(($match[1] ?? '') . ' ' . $match[2]),
                    trim($match[3] ?? '')
                );
                $recognized = true;
                continue;
            }
            if (preg_match(
                '/^(?:process\s+)?exited with (?:exit )?code\s+(-?\d+)$/i',
                $line,
                $match
            ) === 1) {
                $this->add($diagnostics, '프로세스 종료', '종료 코드', $match[1]);
                $recognized = true;
            }
        }

        if (! $recognized) {
            $diagnostics = $this->fallback($lines);
        }
        $diagnostics = $this->unique($diagnostics);
        $rowsTruncated = count($diagnostics) > self::MAX_ROWS;

        return new RuntimeErrorReport(
            array_slice($diagnostics, 0, self::MAX_ROWS),
            $recognized,
            $rawTruncated || $rowsTruncated,
            $normalized
        );
    }

    private function normalize(string $raw): string
    {
        $withoutAnsi = preg_replace(
            '/\x1B\[[0-?]*[ -\/]*[@-~]/',
            '',
            $raw
        );

        return str_replace(
            ["\r\n", "\r", "\0"],
            ["\n", "\n", ''],
            $withoutAnsi ?? $raw
        );
    }

    /**
     * @param list<string> $lines
     */
    private function pythonSourceLine(array $lines, int $index): string
    {
        $next = $lines[$index + 1] ?? '';
        if (
            preg_match('/^\s{2,}\S/', $next) !== 1
            || preg_match('/^\s*(?:File |at |Caused by:)/', $next) === 1
        ) {
            return '';
        }

        return trim($next);
    }

    /**
     * @param list<RuntimeDiagnostic> $diagnostics
     */
    private function add(
        array &$diagnostics,
        string $kind,
        string $type,
        string $message,
        string $file = '',
        ?int $line = null,
        string $function = ''
    ): void {
        $diagnostics[] = new RuntimeDiagnostic(
            trim($kind),
            trim($type),
            trim($message),
            trim($file),
            $line,
            trim($function)
        );
    }

    /**
     * @param list<string> $lines
     * @return list<RuntimeDiagnostic>
     */
    private function fallback(array $lines): array
    {
        $diagnostics = [];
        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }
            $type = '실행 오류';
            $message = $line;
            if (preg_match('/^([^:]{1,80}):\s*(.+)$/', $line, $match) === 1) {
                $type = trim($match[1]);
                $message = trim($match[2]);
            }
            $this->add($diagnostics, '진단', $type, $message);
        }

        return $diagnostics;
    }

    /**
     * @param list<RuntimeDiagnostic> $diagnostics
     * @return list<RuntimeDiagnostic>
     */
    private function unique(array $diagnostics): array
    {
        $unique = [];
        $seen = [];
        foreach ($diagnostics as $diagnostic) {
            $key = $diagnostic->fingerprint();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $diagnostic;
        }

        return $unique;
    }
}
