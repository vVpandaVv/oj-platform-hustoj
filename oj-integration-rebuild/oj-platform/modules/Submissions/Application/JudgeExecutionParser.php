<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Application;

use CodingPanda\OjPlatform\Modules\Submissions\Domain\JudgeExecutionReport;

final class JudgeExecutionParser
{
    private const MAX_RAW_BYTES = 65536;
    private const MAX_CASES = 100;
    private const MAX_COMPARISONS = 50;
    private const MAX_COMPARISON_ROWS = 200;

    public function __construct(private readonly RuntimeErrorParser $runtimeErrors)
    {
    }

    public function parse(string $raw): JudgeExecutionReport
    {
        $truncated = strlen($raw) > self::MAX_RAW_BYTES;
        $raw = substr($raw, 0, self::MAX_RAW_BYTES);
        $raw = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $raw) ?? $raw;
        $raw = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $raw);
        $lines = explode("\n", $raw);
        $cases = [];
        $comparisons = [];
        $buffer = [];
        $comparisonRows = 0;

        for ($index = 0, $count = count($lines); $index < $count; ++$index) {
            $line = $lines[$index];
            $cells = $this->cells($line);
            if ($this->isHeader($cells, ['filename', 'size', 'result', 'memory', 'time'])
                && $this->isSeparator($lines[$index + 1] ?? '', 5)) {
                $index += 2;
                while ($index < $count) {
                    $row = $this->cells($lines[$index]);
                    if (count($row) !== 5 || preg_match('/^\d+$/D', trim($row[1])) !== 1
                        || preg_match('/^[A-Z][A-Z0-9_]{0,31}$/D', trim($row[2])) !== 1) {
                        break;
                    }
                    $file = trim($row[0]);
                    if (isset($cases[$file]) || count($cases) < self::MAX_CASES) {
                        $case = $cases[$file] ?? $this->emptyCase($file);
                        $cases[$file] = array_replace($case, [
                            'size' => (int) trim($row[1]), 'result' => trim($row[2]),
                            'memory' => trim($row[3]), 'time' => trim($row[4]),
                        ]);
                    } else {
                        $truncated = true;
                    }
                    ++$index;
                }
                --$index;
                continue;
            }

            // HUSTOJ writes each comparison as a filename heading and a two-column table.
            if (preg_match('/^(.+\.out)\s*$/D', trim($line), $heading) === 1
                && preg_match('/^\s*-{2,}\s*$/D', $lines[$index + 1] ?? '') === 1
                && $this->isHeader($this->cells($lines[$index + 2] ?? ''), ['expected', 'yours'])
                && $this->isSeparator($lines[$index + 3] ?? '', 2)) {
                $file = $heading[1];
                $rows = [];
                $index += 4;
                while ($index < $count) {
                    $row = $this->cells($lines[$index]);
                    if (count($row) !== 2) {
                        break;
                    }
                    if ($comparisonRows < self::MAX_COMPARISON_ROWS && count($comparisons) < self::MAX_COMPARISONS) {
                        $rows[] = ['expected' => $row[0], 'actual' => $row[1]];
                        ++$comparisonRows;
                    } else {
                        $truncated = true;
                    }
                    ++$index;
                }
                if ($rows !== []) {
                    $comparisons[] = ['file' => $file, 'rows' => $rows];
                }
                --$index;
                continue;
            }

            // The filename trailer belongs to the traceback immediately preceding it.
            if (preg_match('/^\s*(.+\.in):\s*non-zero return\s*=\s*(-?\d+)\s*$/D', $line, $match) === 1) {
                $file = trim($match[1]);
                if (isset($cases[$file]) || count($cases) < self::MAX_CASES) {
                    $case = $cases[$file] ?? $this->emptyCase($file);
                    $case['exit_code'] = (int) $match[2];
                    $case['result'] = $case['result'] ?: 'RE';
                    $case['diagnostics'] = $this->runtimeErrors->parse(implode("\n", $buffer));
                    $truncated = $truncated || $case['diagnostics']->truncated();
                    $cases[$file] = $case;
                } else {
                    $truncated = true;
                }
                $buffer = [];
                continue;
            }
            $buffer[] = $line;
        }
        $diagnostics = $this->runtimeErrors->parse(implode("\n", $buffer));
        return new JudgeExecutionReport(
            array_values($cases), $comparisons, $diagnostics, $raw,
            $truncated || $diagnostics->truncated()
        );
    }

    private function emptyCase(string $file): array
    {
        return [
            'file' => $file, 'size' => null, 'result' => '', 'memory' => '', 'time' => '',
            'exit_code' => null, 'diagnostics' => $this->runtimeErrors->parse(''),
        ];
    }

    /** @return list<string> */
    private function cells(string $line): array
    {
        if (! str_contains($line, '|')) {
            return [];
        }
        $unindented = ltrim($line, "\t ");
        if (str_starts_with($unindented, '|')) {
            $line = substr($unindented, 1);
        }
        $cells = [];
        $cell = '';
        $marked = false;
        for ($index = 0, $length = strlen($line); $index < $length; ++$index) {
            $character = $line[$index];
            if ($character === '\\' && ($line[$index + 1] ?? '') === '|') {
                $cell .= '|';
                ++$index;
            } elseif ($character === '`') {
                $marked = ! $marked;
                $cell .= $character;
            } elseif ($character === '|' && ! $marked) {
                $cells[] = $cell;
                $cell = '';
            } else {
                $cell .= $character;
            }
        }
        $cells[] = $cell;
        // An optional trailing pipe is syntax only when it introduces a third/sixth cell.
        if (in_array(count($cells), [3, 6], true) && end($cells) === '') {
            array_pop($cells);
        }
        return $cells;
    }

    private function isHeader(array $cells, array $expected): bool
    {
        return array_map(static fn (string $cell): string => strtolower(trim($cell)), $cells) === $expected;
    }

    private function isSeparator(string $line, int $columns): bool
    {
        $cells = $this->cells($line);
        if (count($cells) !== $columns) {
            return false;
        }
        foreach ($cells as $cell) {
            if (preg_match('/^\s*:?-{2,}:?\s*$/D', $cell) !== 1) {
                return false;
            }
        }
        return true;
    }
}
