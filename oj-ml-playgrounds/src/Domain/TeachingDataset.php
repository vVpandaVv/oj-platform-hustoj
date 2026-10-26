<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Domain;

/** Deliberately bounded comma-CSV subset, identical to the 0.12.1 canonical serializer. */
final class TeachingDataset
{
    public const MAX_BYTES = 5242880;
    public const MAX_ROWS = 20000;
    public const MAX_COLUMNS = 100;
    public const MAX_CELLS = 200000;
    public const MAX_CELL_BYTES = 65536;
    public const DATASET_PATH = 'dataset/data.csv';

    /** @return array{content:string,sha256:string,bytes:int,rowCount:int,columnCount:int} */
    public static function normalize(string $input): array
    {
        if ($input === '' || strlen($input) > self::MAX_BYTES) {
            throw new Problem('OJ_ML_DATASET_SIZE_INVALID', 'CSV 파일은 비어 있지 않은 5MiB 이하 파일이어야 합니다.', 413);
        }
        if (preg_match('//u', $input) !== 1) {
            throw new Problem('OJ_ML_DATASET_ENCODING_INVALID', 'CSV를 UTF-8 형식으로 저장한 뒤 다시 올려 주세요.', 400);
        }
        if (str_starts_with($input, "\xEF\xBB\xBF")) { $input = substr($input, 3); }
        // PapaParse guesses alternate delimiters. Reject ambiguous inputs instead of silently
        // pinning a hash that changes when a learner imports this file into the upstream app.
        if (preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F;|]/', $input) === 1) {
            throw new Problem('OJ_ML_DATASET_DELIMITER_INVALID', '쉼표 구분 CSV만 지원합니다. 탭·세미콜론·파이프와 제어 문자는 사용할 수 없습니다.', 400);
        }
        $rows = []; $row = []; $cell = ''; $state = 0; $cells = 0;
        $pushCell = static function () use (&$row, &$cell, &$cells): void {
            if (strlen($cell) > self::MAX_CELL_BYTES || ++$cells > self::MAX_CELLS || count($row) >= self::MAX_COLUMNS) {
                throw new Problem('OJ_ML_DATASET_COMPLEXITY_LIMIT', 'CSV는 100열·20만 셀 이하이며 각 셀은 64KiB 이하여야 합니다.', 413);
            }
            self::assertNonFormula($cell);
            $row[] = $cell; $cell = '';
        };
        $pushRow = static function () use (&$rows, &$row): void {
            $empty = true;
            foreach ($row as $value) {
                // ECMAScript String.trim whitespace, used by upstream isEmptyRow.
                if (preg_match('/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]*$/uD', $value) !== 1) { $empty = false; break; }
            }
            if (!$empty) {
                if (count($rows) >= self::MAX_ROWS) { throw new Problem('OJ_ML_DATASET_COMPLEXITY_LIMIT', 'CSV는 헤더를 포함하여 20,000행 이하여야 합니다.', 413); }
                $rows[] = $row;
            }
            $row = [];
        };
        $length = strlen($input);
        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];
            if ($state === 1) {
                if ($char === '"') {
                    if ($i + 1 < $length && $input[$i + 1] === '"') { $cell .= '"'; $i++; }
                    else { $state = 2; }
                } else { $cell .= $char; }
            } elseif ($char === ',') {
                $pushCell(); $state = 0;
            } elseif ($char === "\r" || $char === "\n") {
                $pushCell(); $pushRow(); $state = 0;
                if ($char === "\r" && $i + 1 < $length && $input[$i + 1] === "\n") { $i++; }
            } elseif ($state === 2 || ($char === '"' && $cell !== '')) {
                throw new Problem('OJ_ML_DATASET_CSV_INVALID', 'CSV 따옴표 형식이 올바르지 않습니다.', 400);
            } elseif ($char === '"') {
                $state = 1;
            } else { $cell .= $char; }
            if (strlen($cell) > self::MAX_CELL_BYTES) { throw new Problem('OJ_ML_DATASET_COMPLEXITY_LIMIT', 'CSV 셀은 64KiB 이하여야 합니다.', 413); }
        }
        if ($state === 1) { throw new Problem('OJ_ML_DATASET_CSV_INVALID', 'CSV에 닫히지 않은 따옴표가 있습니다.', 400); }
        $pushCell(); $pushRow();
        $width = count($rows[0] ?? []);
        if (count($rows) < 2 || $width < 2) {
            throw new Problem('OJ_ML_DATASET_TABLE_INVALID', 'CSV에 2열 이상의 헤더와 데이터 행이 필요합니다.', 400);
        }
        $headers = [];
        foreach ($rows[0] as $header) {
            if (preg_match('/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]*$/uD', $header) === 1 || isset($headers[$header])) {
                throw new Problem('OJ_ML_DATASET_HEADER_INVALID', '첫 행의 열 이름은 비어 있거나 중복될 수 없습니다.', 400);
            }
            $headers[$header] = true;
        }
        $lines = [];
        foreach ($rows as $values) {
            if (count($values) !== $width) { throw new Problem('OJ_ML_DATASET_TABLE_INVALID', '모든 데이터 행의 열 개수를 헤더와 같게 맞춰 주세요.', 400); }
            $encoded = [];
            foreach ($values as $value) {
                $encoded[] = strpbrk($value, ",\"\r\n") === false ? $value : '"' . str_replace('"', '""', $value) . '"';
            }
            $lines[] = implode(',', $encoded);
        }
        $content = "\xEF\xBB\xBF" . implode("\n", $lines);
        if (strlen($content) > self::MAX_BYTES) { throw new Problem('OJ_ML_DATASET_SIZE_INVALID', '정규화된 CSV가 5MiB를 초과합니다.', 413); }
        return ['content' => $content, 'sha256' => hash('sha256', $content), 'bytes' => strlen($content), 'rowCount' => count($rows) - 1, 'columnCount' => $width];
    }

    public static function filename(string $name): string
    {
        if (strlen($name) > 480 || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name) === 1
            || !str_ends_with(strtolower($name), '.csv')) {
            throw new Problem('OJ_ML_DATASET_FILENAME_INVALID', '경로가 없는 UTF-8 CSV 파일 이름을 사용하세요.', 400);
        }
        $name = sanitize_file_name($name);
        if ($name === '' || preg_match('/^.{1,120}$/suD', $name) !== 1 || !str_ends_with(strtolower($name), '.csv')) {
            throw new Problem('OJ_ML_DATASET_FILENAME_INVALID', 'CSV 파일 이름은 120자 이하로 지정하세요.', 400);
        }
        return $name;
    }

    /** Do not rewrite learning values; reject spreadsheet formulas before distributing CSV. */
    private static function assertNonFormula(string $cell): void
    {
        $value = preg_replace('/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', '', $cell);
        if (is_string($value) && $value !== '' && str_contains('=+@-', $value[0])
            && preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value) !== 1) {
            throw new Problem('OJ_ML_DATASET_FORMULA_UNSAFE', 'CSV 수식으로 해석될 수 있는 셀이 있습니다. 공백 다음 =·+·@·- 로 시작하는 값은 사용할 수 없습니다. 부호가 있는 일반 숫자와 지수 표기만 허용하며, -apple 같은 분류명도 수정해야 합니다.', 400);
        }
    }
}
