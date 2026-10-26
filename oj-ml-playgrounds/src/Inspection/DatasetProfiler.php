<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Inspection;

use RuntimeException;
use Throwable;

/** Bounded, non-executing CSV aggregation. Raw cells, headers and paths never leave this class. */
final class DatasetProfiler
{
    public const MAX_BYTES = 8388608;
    public const MAX_ROWS = 50000;
    public const MAX_COLUMNS = 128;
    public const MAX_CELLS = 1000000;
    public const MAX_DATASETS = 6;
    public const MAX_EXPERIMENTS = 20;
    public const MAX_DISTINCT_PER_COLUMN = 4096;
    public const MAX_DISTINCT_TOTAL = 20000;
    public const MAX_RESPONSE_BYTES = 49152;
    private const MAX_RECORD_BYTES = 262144;
    private const MIN_GROUP = 5;
    private const MAX_SECONDS = 3.0;

    private int $bytesRemaining = self::MAX_BYTES;
    private int $cellsRemaining = self::MAX_CELLS;
    private int $distinctRemaining = self::MAX_DISTINCT_TOTAL;
    private float $deadline = 0.0;

    /**
     * Entries have already passed the inspector's archive path, type and compression checks.
     * Profiles describe the archived, pre-transform CSV, not re-executed preprocessing or training.
     *
     * @param array<string,array<string,mixed>> $entries
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $runs
     * @return array<string,mixed>
     */
    public function profile(\ZipArchive $zip, array $entries, array $manifest, array $settings, array $runs): array
    {
        $this->bytesRemaining = self::MAX_BYTES;
        $this->cellsRemaining = self::MAX_CELLS;
        $this->distinctRemaining = self::MAX_DISTINCT_TOTAL;
        $this->deadline = microtime(true) + self::MAX_SECONDS;
        $result = [
            'schemaVersion' => 1,
            'source' => 'server_archive_csv',
            'status' => 'complete',
            'reasons' => [],
            'stage' => 'before_preprocessing',
            'missingDefinition' => 'empty_or_whitespace',
            'privacy' => ['rawRowsIncluded' => false, 'categoryLabelsIncluded' => false, 'minimumGroupSize' => self::MIN_GROUP],
            'limits' => ['bytes' => self::MAX_BYTES, 'rowsPerDataset' => self::MAX_ROWS, 'columns' => self::MAX_COLUMNS,
                'cells' => self::MAX_CELLS, 'datasets' => self::MAX_DATASETS, 'experiments' => self::MAX_EXPERIMENTS],
            'datasets' => [],
            'bindings' => [],
        ];
        if (($manifest['dataType'] ?? null) !== 'tabular') {
            $result['status'] = 'unavailable';
            $result['reasons'][] = 'unsupported_data_type';
            return $result;
        }

        $sources = [['role' => 'current', 'experimentIndex' => null, 'settings' => $settings]];
        $experiments = is_array($runs['experiments'] ?? null) ? array_values($runs['experiments']) : [];
        if (count($experiments) > self::MAX_EXPERIMENTS) {
            $result['reasons'][] = 'experiment_limit';
        }
        foreach (array_slice($experiments, 0, self::MAX_EXPERIMENTS) as $index => $experiment) {
            $sources[] = ['role' => 'experiment', 'experimentIndex' => $index,
                'settings' => is_array($experiment) && is_array($experiment['settings'] ?? null) ? $experiment['settings'] : []];
        }
        $known = [];
        $names = [];
        foreach ($sources as $source) {
            $data = is_array($source['settings']['data'] ?? null) ? $source['settings']['data'] : [];
            foreach (['dataset' => 'training', 'testDataset' => 'test'] as $field => $usage) {
                if ($usage === 'test' && !array_key_exists($field, $data)) {
                    continue;
                }
                $binding = ['role' => $source['role'], 'experimentIndex' => $source['experimentIndex'], 'usage' => $usage,
                    'profileId' => null, 'featureColumns' => [], 'targetColumn' => null, 'status' => 'unavailable', 'reasons' => []];
                $reference = $data[$field] ?? null;
                $reason = $this->referenceReason($reference, $entries);
                if ($reason !== null) {
                    $binding['reasons'][] = $reason;
                    $result['bindings'][] = $binding;
                    continue;
                }
                $key = $reference['path'] . "\0" . ($reference['hasHeader'] ? '1' : '0');
                if (!array_key_exists($key, $known)) {
                    if (count($result['datasets']) >= self::MAX_DATASETS) {
                        $binding['reasons'][] = 'dataset_limit';
                        $result['bindings'][] = $binding;
                        continue;
                    }
                    $profileIndex = count($result['datasets']);
                    $known[$key] = $profileIndex;
                    [$profile, $columnNames] = $this->readDataset($zip, $reference, (int) $entries[$reference['path']]['size']);
                    $profile['profileId'] = 'dataset-' . ($profileIndex + 1);
                    $result['datasets'][] = $profile;
                    $names[$profileIndex] = $columnNames;
                }
                $profileIndex = $known[$key];
                $profile = $result['datasets'][$profileIndex];
                $binding['profileId'] = $profile['profileId'];
                $binding['status'] = $profile['status'];
                $binding['reasons'] = $profile['reasons'];
                $features = $data['features'] ?? null;
                if (!is_array($features) || !array_is_list($features) || $features === []) {
                    $binding['reasons'][] = 'feature_selection_missing';
                } else {
                    foreach (array_slice($features, 0, self::MAX_COLUMNS) as $feature) {
                        $index = is_string($feature) ? array_search($feature, $names[$profileIndex], true) : false;
                        if ($index === false) {
                            $binding['reasons'][] = 'feature_column_unresolved';
                        } else {
                            $binding['featureColumns'][] = $index;
                        }
                    }
                    if (count($features) > self::MAX_COLUMNS) {
                        $binding['reasons'][] = 'feature_limit';
                    }
                    $binding['featureColumns'] = array_values(array_unique($binding['featureColumns']));
                }
                $target = $data['target'] ?? null;
                $targetIndex = is_string($target) ? array_search($target, $names[$profileIndex], true) : false;
                $taskType = $source['settings']['taskType'] ?? ($source['role'] === 'current' ? ($manifest['taskType'] ?? null) : null);
                if ($targetIndex !== false) {
                    $binding['targetColumn'] = $targetIndex;
                } elseif ($taskType !== 'clustering') {
                    $binding['reasons'][] = 'target_column_unresolved';
                }
                $binding['reasons'] = array_values(array_unique($binding['reasons']));
                if (count($binding['reasons']) > count($profile['reasons'])) {
                    $binding['status'] = 'unavailable';
                }
                $result['bindings'][] = $binding;
            }
        }

        foreach ($result['bindings'] as $binding) {
            if ($binding['status'] !== 'complete') {
                $result['reasons'] = array_merge($result['reasons'], $binding['reasons']);
            }
        }
        $result['reasons'] = array_values(array_unique($result['reasons']));
        if ($result['reasons'] !== []) {
            $result['status'] = count(array_filter($result['datasets'], static fn (array $item): bool => $item['rowsAnalyzed'] > 0)) > 0
                ? 'partial' : 'unavailable';
        }
        if (strlen((string) json_encode($result)) > self::MAX_RESPONSE_BYTES) {
            // Keep the binding identity, but never label an omitted profile complete.
            foreach ($result['datasets'] as &$dataset) {
                $dataset['columns'] = [];
                $dataset['status'] = 'unavailable';
                $dataset['reasons'][] = 'response_limit';
            }
            unset($dataset);
            foreach ($result['bindings'] as &$binding) {
                $binding['status'] = 'unavailable';
                $binding['reasons'][] = 'response_limit';
            }
            unset($binding);
            $result['status'] = 'unavailable';
            $result['reasons'][] = 'response_limit';
        }
        return $result;
    }

    private function referenceReason(mixed $reference, array $entries): ?string
    {
        if ($reference === null) {
            return 'dataset_reference_missing';
        }
        if (!is_array($reference) || !is_string($reference['path'] ?? null) || !is_bool($reference['hasHeader'] ?? null)) {
            return 'dataset_reference_invalid';
        }
        $path = $reference['path'];
        if (strlen($path) > 512 || !str_starts_with($path, 'dataset/') || strlen($path) <= 8
            || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':')
            || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)
            || str_contains($path, '//') || str_ends_with($path, '/')) {
            return 'dataset_reference_invalid';
        }
        if (($reference['encoding'] ?? null) !== 'utf-8') {
            return 'unsupported_encoding';
        }
        if (!str_ends_with(strtolower($path), '.csv')) {
            return 'unsupported_dataset_format';
        }
        return isset($entries[$path]) && empty($entries[$path]['directory']) ? null : 'dataset_entry_missing';
    }

    /** @return array{0:array<string,mixed>,1:list<string>} */
    private function readDataset(\ZipArchive $zip, array $reference, int $size): array
    {
        $base = ['status' => 'unavailable', 'reasons' => [], 'rowsAnalyzed' => 0, 'rowCount' => null, 'columnCount' => null, 'columns' => []];
        if ($size < 1 || $size > $this->bytesRemaining) {
            $base['reasons'][] = $size < 1 ? 'empty_dataset' : 'byte_limit';
            return [$base, []];
        }
        if (microtime(true) > $this->deadline) {
            $base['reasons'][] = 'time_limit';
            return [$base, []];
        }
        $stream = @$zip->getStream($reference['path']);
        if (!is_resource($stream)) {
            $base['reasons'][] = 'dataset_unreadable';
            return [$base, []];
        }
        $this->bytesRemaining -= $size;
        $columns = [];
        $names = [];
        $rows = 0;
        $width = null;
        $headerSeen = false;
        $reason = null;
        try {
            foreach ($this->records($stream) as $record) {
                if (!$headerSeen) {
                    $record = str_starts_with($record, "\xEF\xBB\xBF") ? substr($record, 3) : $record;
                }
                if (preg_match('//u', $record) !== 1) {
                    throw new RuntimeException('invalid_utf8');
                }
                $cells = array_map(static fn (mixed $value): string => $value === null ? '' : (string) $value, str_getcsv($record, ',', '"', ''));
                if (count($cells) > self::MAX_COLUMNS) {
                    throw new RuntimeException('column_limit');
                }
                if ($width === null) {
                    $width = count($cells);
                    $names = $this->columnNames($reference['hasHeader'] ? $cells : [], $width);
                    for ($index = 0; $index < $width; $index++) {
                        $columns[] = ['missing' => 0, 'present' => 0, 'numeric' => 0, 'mean' => 0.0, 'm2' => 0.0,
                            'overflow' => false, 'distinct' => [], 'distinctComplete' => true];
                    }
                } elseif (count($cells) !== $width) {
                    throw new RuntimeException('row_width_mismatch');
                }
                if (!$headerSeen && $reference['hasHeader']) {
                    $headerSeen = true;
                    continue;
                }
                $headerSeen = true;
                if ($rows >= self::MAX_ROWS) {
                    $reason = 'row_limit';
                    break;
                }
                if ($width > $this->cellsRemaining) {
                    $reason = 'cell_limit';
                    break;
                }
                if (($rows % 128) === 0 && microtime(true) > $this->deadline) {
                    $reason = 'time_limit';
                    break;
                }
                $this->cellsRemaining -= $width;
                foreach ($cells as $index => $cell) {
                    $value = $this->trim($cell);
                    $column = &$columns[$index];
                    if ($value === '') {
                        $column['missing']++;
                        unset($column);
                        continue;
                    }
                    $column['present']++;
                    if ($column['distinctComplete']) {
                        // Hashes are transient, never serialized or used as public identifiers.
                        $key = hash('sha256', $cell);
                        if (isset($column['distinct'][$key])) {
                            $column['distinct'][$key]++;
                        } elseif (count($column['distinct']) < self::MAX_DISTINCT_PER_COLUMN && $this->distinctRemaining > 0) {
                            $column['distinct'][$key] = 1;
                            $this->distinctRemaining--;
                        } else {
                            $column['distinctComplete'] = false;
                        }
                    }
                    $number = $this->number($value);
                    if ($number !== null) {
                        $column['numeric']++;
                        $delta = $number - $column['mean'];
                        $column['mean'] += $delta / $column['numeric'];
                        $column['m2'] += $delta * ($number - $column['mean']);
                        if (!is_finite($column['mean']) || !is_finite($column['m2'])) {
                            $column['overflow'] = true;
                        }
                    }
                    unset($column);
                }
                $rows++;
            }
        } catch (RuntimeException $exception) {
            $reason = $exception->getMessage();
            // Parsing failures cannot safely describe the whole file; discard the prefix.
            if ($reason !== 'time_limit') {
                $base['reasons'][] = $reason;
                return [$base, []];
            }
        } catch (Throwable $exception) {
            $base['reasons'][] = 'dataset_unreadable';
            return [$base, []];
        } finally {
            fclose($stream);
        }
        if ($rows === 0) {
            $base['reasons'][] = $reason ?? 'empty_dataset';
            return [$base, []];
        }
        $base['status'] = $reason === null ? 'complete' : 'partial';
        $base['reasons'] = $reason === null ? [] : [$reason];
        $base['rowsAnalyzed'] = $rows;
        $base['rowCount'] = $reason === null ? $rows : null;
        $base['columnCount'] = $width;
        foreach ($columns as $index => $column) {
            $base['columns'][] = $this->aggregate($index, $column, $rows);
        }
        return [$base, $names];
    }

    private function aggregate(int $index, array $column, int $rows): array
    {
        $count = $column['present'];
        $distinct = count($column['distinct']);
        $type = $count === 0 ? 'empty' : ($column['numeric'] === $count ? 'numeric' : ($column['numeric'] > 0 ? 'mixed' : 'categorical'));
        $suppression = [];
        if ($count < self::MIN_GROUP) {
            $suppression[] = 'small_group';
        }
        if ($distinct <= 1 && $column['distinctComplete']) {
            $suppression[] = 'constant_or_empty';
        }
        if (!$column['distinctComplete']) {
            $suppression[] = 'cardinality_limit';
        } elseif ($count > 0 && $distinct / $count >= 0.95) {
            $suppression[] = 'high_uniqueness';
        }
        if ($column['overflow']) {
            $suppression[] = 'numeric_range';
        }
        $numeric = null;
        if ($type === 'numeric' && $suppression === []) {
            $numeric = ['mean' => $this->rounded($column['mean']), 'stdDev' => $this->rounded(sqrt(max(0.0, $column['m2'] / $count)))];
        }
        $distribution = null;
        if ($column['distinctComplete'] && $count >= self::MIN_GROUP && $distinct > 1 && $distinct / $count < 0.95) {
            $counts = array_values($column['distinct']);
            // If any category is small, hide all counts to avoid subtraction/reconstruction.
            $small = min($counts) < self::MIN_GROUP;
            if ($small) {
                $suppression[] = 'small_category';
                $distribution = ['counts' => [], 'suppressed' => true];
            } else {
                rsort($counts, SORT_NUMERIC);
                $distribution = ['counts' => array_slice($counts, 0, 20), 'suppressed' => count($counts) > 20];
                if (count($counts) > 20) {
                    $suppression[] = 'distribution_limit';
                }
            }
        }
        return ['index' => $index, 'type' => $type, 'presentCount' => $count, 'missingCount' => $column['missing'],
            'missingRate' => round($column['missing'] / $rows, 6),
            'distinctCount' => $column['distinctComplete'] ? $distinct : null,
            'distinctAtLeast' => $distinct + ($column['distinctComplete'] ? 0 : 1),
            'distribution' => $distribution, 'numeric' => $numeric, 'suppressionReasons' => $suppression];
    }

    /** Strict RFC 4180 scanner, bounded before str_getcsv allocation; handles chunk boundaries and multiline quoted cells. */
    private function records($stream): \Generator
    {
        $record = '';
        $inQuotes = false;
        $quotePending = false;
        $fieldStart = true;
        $afterQuote = false;
        $skipLf = false;
        $firstChunk = true;
        while (!feof($stream)) {
            if (microtime(true) > $this->deadline) {
                throw new RuntimeException('time_limit');
            }
            $chunk = @fread($stream, 65536);
            if ($chunk === false || ($chunk === '' && !feof($stream))) {
                throw new RuntimeException('dataset_unreadable');
            }
            if ($firstChunk) {
                $firstChunk = false;
                $chunk = str_starts_with($chunk, "\xEF\xBB\xBF") ? substr($chunk, 3) : $chunk;
            }
            for ($i = 0, $length = strlen($chunk); $i < $length; $i++) {
                $char = $chunk[$i];
                if ($skipLf) {
                    $skipLf = false;
                    if ($char === "\n") {
                        continue;
                    }
                }
                if ($quotePending) {
                    if ($char === '"') {
                        $quotePending = false;
                        $record .= $char;
                        $this->checkRecord($record);
                        continue;
                    }
                    $quotePending = false;
                    $inQuotes = false;
                    $afterQuote = true;
                }
                if ($inQuotes) {
                    $record .= $char;
                    $quotePending = $char === '"';
                    $this->checkRecord($record);
                    continue;
                }
                if ($char === "\r" || $char === "\n") {
                    yield $record;
                    $record = '';
                    $fieldStart = true;
                    $afterQuote = false;
                    $skipLf = $char === "\r";
                    continue;
                }
                if ($afterQuote && $char !== ',') {
                    throw new RuntimeException('invalid_csv');
                }
                $afterQuote = false;
                if ($char === ',') {
                    $fieldStart = true;
                } elseif ($char === '"') {
                    if (!$fieldStart) {
                        throw new RuntimeException('invalid_csv');
                    }
                    $inQuotes = true;
                    $fieldStart = false;
                } else {
                    $fieldStart = false;
                }
                $record .= $char;
                $this->checkRecord($record);
            }
        }
        if ($inQuotes && !$quotePending) {
            throw new RuntimeException('invalid_csv');
        }
        if ($record !== '') {
            yield $record;
        }
    }

    private function checkRecord(string $record): void
    {
        if (strlen($record) > self::MAX_RECORD_BYTES) {
            throw new RuntimeException('record_limit');
        }
    }

    private function trim(string $value): string
    {
        // ECMAScript trim(), including BOM but not U+0085 (NEL).
        $space = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
        return preg_replace('/^' . $space . '+|' . $space . '+$/u', '', $value) ?? $value;
    }

    /** JavaScript Number's finite decimal and unsigned base-prefixed forms, without locale parsing. */
    private function number(string $value): ?float
    {
        if (preg_match('/^0[xX][0-9a-fA-F]+$/D', $value) === 1) {
            $number = (float) hexdec(substr($value, 2));
        } elseif (preg_match('/^0[bB][01]+$/D', $value) === 1) {
            $number = (float) bindec(substr($value, 2));
        } elseif (preg_match('/^0[oO][0-7]+$/D', $value) === 1) {
            $number = (float) octdec(substr($value, 2));
        } elseif (preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $value) === 1) {
            $number = (float) $value;
        } else {
            return null;
        }
        return is_finite($number) ? $number : null;
    }

    private function rounded(float $value): float
    {
        return (float) sprintf('%.6g', $value);
    }

    /** Match upstream columnNames including duplicate/empty headers, but do not emit those names. */
    private function columnNames(array $header, int $width): array
    {
        $names = [];
        $taken = [];
        for ($index = 0; $index < $width; $index++) {
            $letter = '';
            for ($number = $index; $number >= 0; $number = intdiv($number, 26) - 1) {
                $letter = chr(65 + ($number % 26)) . $letter;
            }
            $raw = $this->trim($header[$index] ?? '');
            $label = $raw === '' || isset($taken[$raw]) ? ($raw === '' ? '' : $raw . ' ') . '(' . $letter . ')' : $raw;
            $name = $label;
            for ($suffix = 2; isset($taken[$name]); $suffix++) {
                $name = $label . ' (' . $suffix . ')';
            }
            $names[] = $name;
            $taken[$name] = true;
        }
        return $names;
    }
}
